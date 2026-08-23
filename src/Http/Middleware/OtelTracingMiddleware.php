<?php

declare(strict_types=1);

namespace StrandsPhpClient\Http\Middleware;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use StrandsPhpClient\Exceptions\AgentErrorException;
use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\Http\ResponseObserver;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Response\Usage;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Adds OpenTelemetry client spans and W3C trace headers around agent calls.
 *
 * Pass it to StrandsClient when operators need request timing, usage, tool, session, and finish-reason diagnostics without recording bodies or PHI.
 * Its LIFO span stack fits synchronous PHP-FPM request handling, but it is not safe when one instance is shared across Fibers or coroutines.
 *
 * Creating and registering the middleware explicitly controls when tracing work occurs.
 */
class OtelTracingMiddleware implements RequestMiddleware, ResponseObserver
{
    /** Policy marker used so telemetry consumers can identify this attribute set. */
    private const ATTRIBUTE_POLICY = 'strands-otel-v1';

    /** @var array<string, true> Low-cardinality wire values allowed on finish-reason attributes. */
    private const SAFE_STOP_REASONS = [
        'cancelled' => true,
        'checkpoint' => true,
        'content_filtered' => true,
        'end_turn' => true,
        'error' => true,
        'guardrail_intervened' => true,
        'interrupt' => true,
        'limit_output_tokens' => true,
        'limit_total_tokens' => true,
        'limit_turns' => true,
        'max_tokens' => true,
        'stop_sequence' => true,
        'tool_use' => true,
    ];

    /** @var \SplStack<array{0: SpanInterface, 1: ScopeInterface}> */
    private \SplStack $spanStack;

    /**
     * Creates tracing middleware with an app-selected tracer, propagator, and span label.
     * Use it when the app has custom trace propagation or naming requirements.
     *
     * @param TracerInterface $tracer Tracer used to create client spans.
     * @param TextMapPropagatorInterface $propagator Propagator used to inject trace-context headers.
     * @param string $spanNamePrefix Prefix for generated spans; an empty value falls back to strands.client in the trace UI.
     */
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
        private readonly string $spanNamePrefix = 'strands.client',
    ) {
        $this->spanStack = new \SplStack();
    }

    /**
     * Creates tracing middleware with the standard W3C trace-context propagator.
     * Use it for the normal setup where a tracer is configured but the app has no custom propagation rules.
     *
     * @param TracerInterface $tracer Tracer used to create client spans.
     * @return self Ready middleware; never null.
     */
    public static function create(TracerInterface $tracer): self
    {
        return new self($tracer, TraceContextPropagator::getInstance());
    }

    /**
     * Opens the span and injects trace headers before the app waits for the agent.
     * StrandsClient calls it automatically for each invoke, stream, or custom endpoint request.
     *
     * @param array<string, string> $headers Headers reaching the agent; an empty map receives only the propagator's trace headers.
     * @param string $url Agent endpoint being called; an invalid or empty URL is represented by safe fallback telemetry.
     * @param string $body Request body reaching the agent; an empty body stays empty and is never copied into the span.
     * @return array{headers: array<string, string>, body: string} Request values with trace headers added; the body is unchanged, even when empty.
     * @throws \Throwable If the propagator fails while injecting trace headers.
     */
    public function beforeRequest(string $url, array $headers, string $body): array
    {
        $route = self::sanitizeRoute($url);
        $operation = self::deriveOperationName($route, $headers);
        $spanName = $this->deriveSpanName($operation);

        $span = $this->tracer
            ->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $scope = null;

        try {
            $span->setAttribute('http.request.method', 'POST');
            $span->setAttribute('url.full', self::sanitizeUrl($url));
            $span->setAttribute('gen_ai.system', 'strands');
            $span->setAttribute('gen_ai.operation.name', $operation);
            $span->setAttribute('strands.otel.policy', self::ATTRIBUTE_POLICY);
            $span->setAttribute('strands.endpoint.route', $route);

            $host = parse_url($url, PHP_URL_HOST);
            // Record which agent host the call went to, when the URL names one.
            if (is_string($host)) {
                $span->setAttribute('server.address', $host);
            }

            $port = parse_url($url, PHP_URL_PORT);
            // Record the port too, when the URL specifies a non-default one.
            if (is_int($port)) {
                $span->setAttribute('server.port', $port);
            }

            $scope = $span->activate();

            $carrier = $headers;
            $this->propagator->inject(
                $carrier,
                ArrayAccessGetterSetter::getInstance(),
                Context::getCurrent(),
            );

            $this->spanStack->push([$span, $scope]);
        } catch (\Throwable $exception) {
            // For example, a custom propagator rejects an unexpected carrier shape, or a tracer fails while attributes are being set.
            // The span never reached the stack that afterResponse() drains, so closing it here is what stops it from staying open for the process.
            self::endSpanAndReleaseScope($span, $scope);

            throw $exception;
        }

        /** @var array<string, string> $injectedHeaders validated before app code uses it. */
        $injectedHeaders = $carrier;

        return ['headers' => $injectedHeaders, 'body' => $body];
    }

    /**
     * Closes the active request span and records whether the operation succeeded or failed.
     * StrandsClient calls it after transport completion or failure; calling it with no open span is a safe no-op.
     *
     * @param string $url Request URL being observed.
     * @param int $statusCode HTTP status code for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @param \Throwable|null $error Transport or agent error shown to the caller, or null when the request completed without an exception.
     * @return void No value; the trace is updated when a span is open.
     */
    public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
    {
        // No open span means this response has no trace to close (tracing off or already ended).
        if ($this->spanStack->isEmpty()) {
            return;
        }

        [$span, $scope] = $this->spanStack->pop();

        try {
            // A real HTTP status (not the 0 sentinel) is worth recording on the trace.
            if ($statusCode > 0) {
                $span->setAttribute('http.response.status_code', $statusCode);
            }

            $span->setAttribute('strands.operation.duration_ms', $durationMs);

            // An exception marks the operation as failed and supplies a bounded error category.
            if ($error !== null) {
                $span->addEvent('exception', [
                    'exception.type' => $error::class,
                    'exception.message' => self::safeErrorDescription($error),
                    'exception.escaped' => true,
                ]);
                $span->setAttribute('error.type', self::classBasename($error::class));
                $span->setStatus(StatusCode::STATUS_ERROR, self::safeErrorDescription($error));

                // Agent-reported errors expose only the HTTP status; response codes are app-owned data.
                if ($error instanceof AgentErrorException) {
                    $span->setAttribute('strands.error.status_code', $error->statusCode);
                }
            }

            // An HTTP error without an exception still marks the operation as failed.
            if ($error === null && $statusCode >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, sprintf('HTTP %d', $statusCode));
            }
        } catch (\Throwable $tracingException) {
            // An exporter can reject an attribute while the span is annotated; tracing must not replace the caller's result.
            // Discard the captured exception explicitly so static analysis can see that the middleware contract intentionally swallows it.
            unset($tracingException);
        } finally {
            // The span has already left the stack, so nothing else can close it. Teardown runs even when the annotations above failed.
            self::endSpanAndReleaseScope($span, $scope);
        }
    }

    /**
     * Ends one request span and releases the context the request was traced under.
     * Use it for a normal close and for a failed setup alike, because each step still runs when the other fails.
     * Without both, a broken exporter would leave the app's next agent call recorded as a child of a request that already finished.
     *
     * @param SpanInterface $span Span opened for the request being closed.
     * @param ?ScopeInterface $scope Scope to release; null means setup failed before the span was ever activated.
     * @return void No value; the span ends and the scope is released as far as the tracer allows.
     */
    private static function endSpanAndReleaseScope(SpanInterface $span, ?ScopeInterface $scope): void
    {
        // A scope exists only once activation succeeded; leaving it attached would parent the next call under this completed span.
        if ($scope !== null) {
            try {
                $scope->detach();
            } catch (\Throwable $detachException) {
                // For example, a context storage implementation rejects an out-of-order detach; the span below must still be ended.
                unset($detachException);
            }
        }

        try {
            $span->end();
        } catch (\Throwable $endException) {
            // An exporter can time out as the span ends; the caller's result or original agent error still stands.
            unset($endException);
        }
    }

    /**
     * Adds parsed invoke details that help operators diagnose the completed operation.
     * StrandsClient calls it after hydration; with no active span it leaves the app result untouched.
     *
     * @param string $url Request URL being observed.
     * @param AgentResponse $response Parsed response data for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void No value; the active trace is annotated when present.
     */
    public function afterInvoke(string $url, AgentResponse $response, float $durationMs): void
    {
        $span = $this->currentSpan();
        // Nothing to annotate if tracing isn't active for this request.
        if ($span === null) {
            return;
        }

        self::setUsageAttributes($span, $response->usage);
        self::setAgentAttributes($span, $response->agent, $response->sessionId);
        self::setToolsAttributes($span, $response->toolsUsed);

        $finishReason = self::safeStopReason($response->rawStopReason ?? $response->stopReason?->value);
        // Record why the agent stopped, when it supplied a contract-defined value.
        if ($finishReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $finishReason);
        }

        // A terminal agent error can arrive in a successful HTTP response, so mark the invoke operation as failed.
        if ($finishReason === 'error') {
            $span->setAttribute('error.type', 'agent_error');
            $span->setStatus(StatusCode::STATUS_ERROR, 'agent terminal error');
        }

        $model = $response->metadata['model'] ?? null;
        // Tag the model the agent used, when the response names one.
        if (is_string($model)) {
            $span->setAttribute('gen_ai.request.model', $model);
        }
    }

    /**
     * Adds the typed stream summary used to diagnose the completed stream operation.
     * StrandsClient calls it after streaming ends; with no active span it is a safe no-op.
     *
     * @param string $url Request URL being observed.
     * @param StreamResult $result Parsed stream result for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void No value; the active trace is annotated when present.
     */
    public function afterStream(string $url, StreamResult $result, float $durationMs): void
    {
        $span = $this->currentSpan();
        // Nothing to annotate if tracing isn't active for this request.
        if ($span === null) {
            return;
        }

        self::setUsageAttributes($span, $result->usage);
        self::setToolsAttributes($span, $result->toolsUsed);
        // A null session ID means the live answer cannot be resumed as an existing conversation.
        $span->setAttribute('strands.session.present', $result->sessionId !== null);
        $span->setAttribute('strands.stream.text_events', $result->textEvents);
        $span->setAttribute('strands.stream.total_events', $result->totalEvents);
        $span->setAttribute('strands.stream.cancelled', $result->cancelled);

        // Record time-to-first-token when we saw text, so latency dashboards have it.
        if ($result->timeToFirstTextTokenMs !== null) {
            $span->setAttribute('strands.stream.ttft_ms', $result->timeToFirstTextTokenMs);
        }

        $finishReason = self::safeStopReason($result->rawStopReason ?? $result->stopReason?->value);
        // Record why the agent stopped, when it supplied a contract-defined value.
        if ($finishReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $finishReason);
        }

        // A terminal agent error can arrive over a successful HTTP stream, so mark the operation as failed.
        if ($finishReason === 'error') {
            $span->setAttribute('error.type', 'agent_error');
            $span->setStatus(StatusCode::STATUS_ERROR, 'agent terminal error');
        }

        // An explicit stream error event also marks failure when no transport exception was thrown.
        if ($result->terminalType === 'error') {
            $span->setAttribute('error.type', 'stream_error');
            $span->setStatus(StatusCode::STATUS_ERROR, 'stream terminal error');
        }
    }

    /**
     * Adds only safe summary fields from a custom JSON response to the active span.
     * Use it through postJson() when operators need usage or finish diagnostics without exporting app-owned payload contents.
     *
     * @param array<string, mixed> $response Parsed custom result; an empty map records no usage, tools, session, or finish details.
     * @param string $url agent endpoint the app is calling.
     * @param float $durationMs elapsed time reported to app telemetry.
     * @return void No returned value; updates client or observer state.
     */
    public function afterPostJson(string $url, array $response, float $durationMs): void
    {
        $span = $this->currentSpan();
        // Nothing to annotate if tracing isn't active for this request.
        if ($span === null) {
            return;
        }

        self::setRawResponseSummaryAttributes($span, $response);
    }

    /**
     * Adds a sanitized raw-SSE summary after a custom SSE call finishes receiving events.
     * Use it through streamSse(); unknown or absent summary fields are omitted rather than exported as high-cardinality labels.
     *
     * @param string $url Request URL being observed.
     * @param StreamSseSummary $summary Sanitized raw SSE stream summary.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void No value; the active trace is annotated when present.
     */
    public function afterStreamSse(string $url, StreamSseSummary $summary, float $durationMs): void
    {
        $span = $this->currentSpan();
        // Nothing to annotate if tracing isn't active for this request.
        if ($span === null) {
            return;
        }

        $span->setAttribute('strands.stream.text_events', $summary->textEvents);
        $span->setAttribute('strands.stream.total_events', $summary->totalEvents);
        $span->setAttribute('strands.stream.cancelled', $summary->cancelled);

        // Record token usage when the terminal event reported it.
        if ($summary->usage !== null) {
            self::setUsageAttributes($span, $summary->usage);
        }

        $stopReason = self::safeStopReason($summary->stopReason);
        // Only contract-defined stop reasons are safe to use as telemetry labels.
        if ($stopReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $stopReason);
        }

        // A raw terminal agent error still marks failure despite the successful HTTP connection.
        if ($stopReason === 'error') {
            $span->setAttribute('error.type', 'agent_error');
            $span->setStatus(StatusCode::STATUS_ERROR, 'agent terminal error');
        }

        // Custom streams use their terminal type to signal an error that should be visible in operational traces.
        if ($summary->terminalType === 'error') {
            $span->setAttribute('error.type', 'stream_sse_error');
            $span->setStatus(StatusCode::STATUS_ERROR, 'stream_sse terminal error');
        }
    }

    /**
     * Builds the concise operation label shown in the trace UI.
     * Use it when opening a span so custom prefixes still produce a stable invoke, stream, stream_sse, or post_json name.
     *
     * @param string $operation Telemetry operation name shown for the app call.
     * @return non-empty-string Span name shown in the trace UI; an empty configured prefix falls back to strands.client.
     */
    private function deriveSpanName(string $operation): string
    {
        // An empty configured prefix falls back to the stable default operators expect in the trace UI.
        $prefix = $this->spanNamePrefix !== '' ? $this->spanNamePrefix : 'strands.client';

        return $prefix . '.' . $operation;
    }

    /**
     * Maps the safe route and Accept header to a stable telemetry operation.
     * Use it before opening a span so standard and custom endpoints group consistently.
     *
     * @param array<string, string> $headers Headers reaching the agent; an empty map makes a custom route a post_json operation.
     * @param string $route Sanitized endpoint route shown in telemetry.
     * @return string Operation label that groups the trace (invoke, stream, stream_sse, post_json).
     */
    private static function deriveOperationName(string $route, array $headers): string
    {
        // The standard invoke path maps straight to its operation name.
        if (str_ends_with($route, '/invoke')) {
            return 'invoke';
        }

        // Same for the standard streaming path.
        if (str_ends_with($route, '/stream')) {
            return 'stream';
        }

        $accept = self::headerValue($headers, 'Accept');

        return str_contains($accept, 'text/event-stream')
            ? 'stream_sse'
            : 'post_json';
    }

    /**
     * Returns the request span currently waiting for an agent response.
     * Use it before adding parsed response details; null means tracing was not opened or has already closed.
     *
     * @return SpanInterface|null Active span, or null when no request span is open.
     */
    private function currentSpan(): ?SpanInterface
    {
        // No open span means no request is being traced right now.
        if ($this->spanStack->isEmpty()) {
            return null;
        }

        [$span] = $this->spanStack->top();

        return $span;
    }

    /**
     * Removes query strings and fragments before a URL reaches the trace UI.
     * Use it for every request URL so user identifiers or secrets in query parameters are never exported.
     *
     * @param string $url Request URL being observed.
     * @return string Telemetry-safe URL; / when the input cannot be parsed.
     */
    private static function sanitizeUrl(string $url): string
    {
        $urlParts = parse_url($url);
        // If the URL will not parse, fail closed instead of emitting caller-controlled text.
        if (!is_array($urlParts)) {
            return '/';
        }

        // Rebuild only the safe parts (scheme/host/port), dropping any query string that could hold PII.
        $sanitizedUrl = isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '';
        // Host is the agent's address.
        if (isset($urlParts['host'])) {
            $sanitizedUrl .= $urlParts['host'];
        }
        // Include the port only when one is present.
        if (isset($urlParts['port'])) {
            $sanitizedUrl .= ':' . $urlParts['port'];
        }
        $sanitizedUrl .= self::sanitizeRoute($url);

        return $sanitizedUrl;
    }

    /**
     * Finds one request header without assuming how app code capitalized its name.
     * Use it when classifying custom routes; an absent header returns an empty string and therefore does not select SSE.
     *
     * @param array<string, string> $headers Request headers.
     * @param string $requestedHeaderName Header name to read.
     * @return string Header value, or an empty string when the caller did not send it.
     */
    private static function headerValue(array $headers, string $requestedHeaderName): string
    {
        // Callers may supply any HTTP-header casing, so inspect every header until the requested name matches case-insensitively.
        foreach ($headers as $headerName => $headerValue) {
            // The first matching name controls this request, such as whether the caller requested SSE.
            if (strcasecmp($headerName, $requestedHeaderName) === 0) {
                return $headerValue;
            }
        }

        return '';
    }

    /**
     * Collapses request paths to the small route set safe for dashboards and metrics.
     * Use it before naming spans; missing or empty paths become / and unknown custom paths become /{custom}.
     *
     * @param string $url Request URL being observed.
     * @return string Telemetry-safe route value; never empty.
     */
    private static function sanitizeRoute(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        // No usable path means the call hit the agent root ("/").
        if (!is_string($path) || $path === '') {
            return '/';
        }

        $finalSegment = basename($path);
        // A path of only slashes also collapses to the root route.
        if ($finalSegment === '') {
            return '/';
        }

        return match ($finalSegment) {
            'invoke' => '/invoke',
            'stream' => '/stream',
            default => '/{custom}',
        };
    }

    /**
     * Copies safe integer token counters into the active span.
     * Use it after a parsed response or terminal event gives the app a Usage object.
     *
     * @param SpanInterface $span Span receiving telemetry attributes.
     * @param Usage $usage Token usage values to record.
     * @return void No value; the supplied span receives usage attributes, including zeros.
     */
    private static function setUsageAttributes(SpanInterface $span, Usage $usage): void
    {
        $span->setAttribute('gen_ai.usage.input_tokens', $usage->inputTokens);
        $span->setAttribute('gen_ai.usage.output_tokens', $usage->outputTokens);
        $span->setAttribute('gen_ai.usage.cache_read_input_tokens', $usage->cacheReadInputTokens);
        $span->setAttribute('gen_ai.usage.cache_write_input_tokens', $usage->cacheWriteInputTokens);
    }

    /**
     * Records which agent answered and whether the app can continue the conversation.
     * Use it for invoke results; absent names are omitted and absent session IDs record session.present as false.
     *
     * @param SpanInterface $span Span receiving telemetry attributes.
     * @param string|null $agent Agent name; null omits the name, while an empty string is recorded exactly as supplied.
     * @param string|null $sessionId Conversation ID; null records that no resumable session is available, while an empty string counts as present.
     * @return void No value; the supplied span receives agent and session attributes.
     */
    private static function setAgentAttributes(SpanInterface $span, ?string $agent, ?string $sessionId): void
    {
        // Tag which named agent answered, when the response identifies one.
        if ($agent !== null) {
            $span->setAttribute('strands.agent.name', $agent);
        }

        // A null session ID means the answer cannot be resumed as an existing conversation.
        $span->setAttribute('strands.session.present', $sessionId !== null);
    }

    /**
     * Records a bounded count and deduplicated names for tools used during the answer.
     * Use it when response details are available; an empty list records a zero count and omits the names attribute.
     *
     * @param list<array<string, mixed>> $toolsUsed Tools the agent called; empty when it answered without any.
     * @param SpanInterface $span Telemetry span updated for the app request.
     * @return void No returned value; updates client or observer state.
     */
    private static function setToolsAttributes(SpanInterface $span, array $toolsUsed): void
    {
        $toolNames = [];
        // Pull out each tool's name so the trace shows what the agent actually did.
        foreach ($toolsUsed as $toolSummary) {
            $toolName = $toolSummary['name'] ?? null;
            // Keep only well-formed string names; ignore malformed tool entries.
            if (is_string($toolName)) {
                $toolNames[] = $toolName;
            }
        }

        $span->setAttribute('strands.tools.count', count($toolNames));

        // Only add the names list when at least one tool ran (keeps empty traces clean).
        if ($toolNames !== []) {
            $span->setAttribute('strands.tools.names', array_values(array_unique($toolNames)));
        }
    }

    /**
     * Extracts only approved usage, session, tool-count, and stop fields from an app-owned custom response.
     * Use it after postJson(); an empty map adds no optional detail and never exports arbitrary payload values.
     *
     * @param array<string, mixed> $response Parsed custom-endpoint result; empty when it returned no data.
     * @param SpanInterface $span Telemetry span updated for the app request.
     * @return void No returned value; updates client or observer state.
     */
    private static function setRawResponseSummaryAttributes(SpanInterface $span, array $response): void
    {
        $rawUsage = $response['usage'] ?? null;
        // Record token usage when the custom endpoint reported it, for cost dashboards.
        if (is_array($rawUsage)) {
            /** @var array<string, mixed> $rawUsage validated before app code uses it. */
            self::setUsageAttributes($span, Usage::fromArray($rawUsage));
        }

        $sessionId = $response['session_id'] ?? null;
        $span->setAttribute('strands.session.present', is_string($sessionId));

        $toolsUsed = $response['tools_used'] ?? null;
        // Custom endpoint payloads are app-owned, so export only a count, never raw names.
        if (is_array($toolsUsed) && array_is_list($toolsUsed)) {
            $span->setAttribute('strands.tools.count', count($toolsUsed));
        }

        $rawStopReason = $response['stop_reason'] ?? null;
        $stopReason = is_string($rawStopReason) ? self::safeStopReason($rawStopReason) : null;
        // Only contract-defined stop reasons are safe to use as telemetry labels.
        if ($stopReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $stopReason);
        }

        // A custom JSON result can report an agent-level error even though the HTTP request itself succeeded.
        if ($stopReason === 'error') {
            $span->setAttribute('error.type', 'agent_error');
            $span->setStatus(StatusCode::STATUS_ERROR, 'agent terminal error');
        }
    }

    /**
     * Accepts only known wire stop reasons before a value becomes a telemetry label.
     * Use it for typed and raw responses; null or a future unknown value stays out of traces while raw response data remains available to the app.
     *
     * @param string|null $stopReason Raw wrapper value; null means the response supplied no stop reason.
     * @return string|null Safe contract value, or null when the input is absent, empty, or unknown.
     */
    private static function safeStopReason(?string $stopReason): ?string
    {
        // Null or an unknown value is omitted from telemetry rather than creating an unbounded dashboard label.
        return $stopReason !== null && isset(self::SAFE_STOP_REASONS[$stopReason])
            ? $stopReason
            : null;
    }

    /**
     * Converts a caller-visible failure into a stable trace status description.
     * Use it instead of exception messages, which may contain user or endpoint data.
     *
     * @param \Throwable $error Transport or agent error raised by the operation; never null at this point.
     * @return non-empty-string Safe, low-cardinality description shown in the trace UI.
     */
    private static function safeErrorDescription(\Throwable $error): string
    {
        // Agent-reported failures get a stable, low-cardinality label for grouping in traces.
        if ($error instanceof AgentErrorException) {
            return sprintf('agent_http_%d', $error->statusCode);
        }

        return self::classBasename($error::class);
    }

    /**
     * Removes the namespace from an exception class before showing it in traces.
     * Use it for error.type and generic status descriptions so labels stay readable and bounded.
     *
     * @param class-string $className Fully-qualified class name to shorten for telemetry.
     * @return non-empty-string Short class name shown in trace attributes; malformed empty input falls back to Throwable.
     */
    private static function classBasename(string $className): string
    {
        $namespaceSeparatorPosition = strrpos($className, '\\');
        $shortClassName = $namespaceSeparatorPosition === false
            ? $className
            : substr($className, $namespaceSeparatorPosition + 1);

        // A real class-string is non-empty; keep a stable fallback if malformed test or integration data ever reaches this private boundary.
        if ($shortClassName === '') {
            return 'Throwable';
        }

        return $shortClassName;
    }
}
