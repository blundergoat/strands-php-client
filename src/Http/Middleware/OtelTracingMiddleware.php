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
 * Emits KIND_CLIENT spans and injects W3C trace-context headers.
 *
 * Zero runtime cost when no tracer is registered — the middleware must be
 * explicitly instantiated and passed to StrandsClient.
 *
 * Concurrency model: uses a LIFO SplStack of [SpanInterface, ScopeInterface]
 * pairs. This is correct for synchronous PHP-FPM (single-threaded per
 * request) but NOT safe under Fibers or coroutines.
 *
 * This middleware does NOT capture request/response bodies on spans (PHI risk).
 */
class OtelTracingMiddleware implements RequestMiddleware, ResponseObserver
{
    /** Policy marker used so telemetry consumers can identify this attribute set. */
    private const ATTRIBUTE_POLICY = 'strands-otel-v1';

    /** @var \SplStack<array{0: SpanInterface, 1: ScopeInterface}> */
    private \SplStack $spanStack;

    /**
     * Create tracing middleware with explicit tracing collaborators.
     *
     * @param TracerInterface $tracer Tracer used to create client spans.
     * @param TextMapPropagatorInterface $propagator Propagator used to inject trace
     * context headers.
     * @param string $spanNamePrefix Prefix used when naming generated spans.
     */
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
        private readonly string $spanNamePrefix = 'strands.client',
    ) {
        $this->spanStack = new \SplStack();
    }

    /**
     * Create tracing middleware with the default W3C trace-context propagator.
     *
     * @param TracerInterface $tracer Tracer used to create client spans.
     * @return self Middleware configured with the default trace-context propagator.
     */
    public static function create(TracerInterface $tracer): self
    {
        return new self($tracer, TraceContextPropagator::getInstance());
    }

    /**
     * Starts request tracing before the app waits for the agent.
     *
     * @param array<string, string> $headers headers that will reach the agent service.
     *
     * @param string $url agent endpoint the app is calling.
     * @param string $body request body the agent service will receive.
     * @return array{headers: array<string, string>, body: string} Request values with trace headers added.
     * @throws \Throwable If the propagator fails while injecting trace headers.
     */
    public function beforeRequest(string $url, array $headers, string $body): array
    {
        // Drain any spans left over from a previous request whose afterResponse()
        // never fired (e.g. buildRequest() failed before the try/catch in
        // StrandsClient::invoke()). Without this, the active scope leaks into
        // subsequent operations and produces incorrect parent-child traces.
        $this->endOrphanedSpans();

        $route = self::sanitizeRoute($url);
        $operation = self::deriveOperationName($route, $headers);
        $spanName = $this->deriveSpanName($route, $operation);

        $span = $this->tracer
            ->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

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

        try {
            $carrier = $headers;
            $this->propagator->inject(
                $carrier,
                ArrayAccessGetterSetter::getInstance(),
                Context::getCurrent(),
            );
        } catch (\Throwable $e) {
            $scope->detach();
            $span->end();

            throw $e;
        }

        $this->spanStack->push([$span, $scope]);

        /** @var array<string, string> $injectedHeaders validated before app code uses it. */
        $injectedHeaders = $carrier;

        return ['headers' => $injectedHeaders, 'body' => $body];
    }

    /**
     * Close the active span and record HTTP status or error metadata.
     *
     * @param string $url Request URL being observed.
     * @param int $statusCode HTTP status code for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @param \Throwable|null $error Transport or agent error that ended the operation, or null when it succeeded.
     * @return void
     */
    public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
    {
        try {
            // No open span means this response has no trace to close (tracing off or already ended).
            if ($this->spanStack->isEmpty()) {
                return;
            }

            [$span, $scope] = $this->spanStack->pop();

            // A real HTTP status (not the 0 sentinel) is worth recording on the trace.
            if ($statusCode > 0) {
                $span->setAttribute('http.response.status_code', $statusCode);
            }

            $span->setAttribute('strands.operation.duration_ms', $durationMs);

            // The user's request failed with an exception — flag the span red and note why.
            if ($error !== null) {
                $span->addEvent('exception', [
                    'exception.type' => $error::class,
                    'exception.message' => self::safeErrorDescription($error),
                    'exception.escaped' => true,
                ]);
                $span->setAttribute('error.type', self::classBasename($error::class));
                $span->setStatus(StatusCode::STATUS_ERROR, self::safeErrorDescription($error));

                // Agent-reported errors carry an HTTP status and code worth surfacing in traces.
                if ($error instanceof AgentErrorException) {
                    $span->setAttribute('strands.error.status_code', $error->statusCode);

                    // Include the machine-readable error code when the agent supplied one.
                    if ($error->errorCode !== null) {
                        $span->setAttribute('strands.error.code', $error->errorCode);
                    }
                }
            } elseif ($statusCode >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, sprintf('HTTP %d', $statusCode));
            }

            $scope->detach();
            $span->end();
        } catch (\Throwable $tracingException) {
            // Tracing must never break user code: span lifecycle failures are swallowed
            // by middleware contract. Discard the captured exception explicitly so static
            // analysis can see the handling is intentional rather than accidental.
            unset($tracingException);
        }
    }

    /**
     * Record invoke-specific response metadata on the active span.
     *
     * @param string $url Request URL being observed.
     * @param AgentResponse $response Parsed response data for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void
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

        // Record why the agent stopped, when it told us (for finish-reason dashboards).
        if ($response->stopReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $response->stopReason->value);
        }

        $model = $response->metadata['model'] ?? null;
        // Tag the model the agent used, when the response names one.
        if (is_string($model)) {
            $span->setAttribute('gen_ai.request.model', $model);
        }
    }

    /**
     * Record typed stream result metadata on the active span.
     *
     * @param string $url Request URL being observed.
     * @param StreamResult $result Parsed stream result for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void
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
        $span->setAttribute('strands.session.present', $result->sessionId !== null);
        $span->setAttribute('strands.stream.text_events', $result->textEvents);
        $span->setAttribute('strands.stream.total_events', $result->totalEvents);
        $span->setAttribute('strands.stream.cancelled', $result->cancelled);

        // Record time-to-first-token when we saw text, so latency dashboards have it.
        if ($result->timeToFirstTextTokenMs !== null) {
            $span->setAttribute('strands.stream.ttft_ms', $result->timeToFirstTextTokenMs);
        }

        // Record why the agent stopped, when it told us.
        if ($result->stopReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $result->stopReason->value);
        }
    }

    /**
     * Records parsed custom-endpoint data for app telemetry.
     *
     * @param array<string, mixed> $response parsed agent result returned to the app.
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
     * Record raw SSE stream summary metadata on the active span.
     *
     * @param string $url Request URL being observed.
     * @param StreamSseSummary $summary Sanitized raw SSE stream summary.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void
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

        // Record why the stream ended, when the terminal event said so.
        if ($summary->stopReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $summary->stopReason);
        }
    }

    /**
     * Names the span so traces show the app action clearly.
     *
     * @param string $route Sanitized endpoint route shown in telemetry.
     * @param string $operation Telemetry operation name shown for the app call.
     * @return non-empty-string Span name shown in the trace UI (e.g. "strands.client.invoke").
     */
    private function deriveSpanName(string $route, string $operation): string
    {
        $prefix = $this->spanNamePrefix !== '' ? $this->spanNamePrefix : 'strands.client';

        // The two standard operations get clean, stable span names.
        if ($operation === 'invoke' || $operation === 'stream') {
            return $prefix . '.' . $operation;
        }

        $routeName = trim($route, '/');
        // A custom endpoint with no path falls back to just the operation name.
        if ($routeName === '') {
            return $prefix . '.' . $operation;
        }

        $routeName = str_replace(['/', '{', '}'], ['.', '', ''], $routeName);

        return $prefix . '.custom.' . $routeName;
    }

    /**
     * Groups telemetry by the app-level client operation.
     *
     * @param array<string, string> $headers headers that will reach the agent service.
     * @param string $route Sanitized endpoint route shown in telemetry.
     * @return string Operation label that groups the trace (invoke, stream, stream_sse, post_json).
     */
    private static function deriveOperationName(string $route, array $headers): string
    {
        // The standard invoke path maps straight to its operation name.
        if ($route === '/invoke') {
            return 'invoke';
        }

        // Same for the standard streaming path.
        if ($route === '/stream') {
            return 'stream';
        }

        $accept = $headers['Accept'] ?? '';

        return str_contains($accept, 'text/event-stream')
            ? 'stream_sse'
            : 'post_json';
    }

    /**
     * Return the currently active span without mutating the span stack.
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
     * Close spans left open by failed request setup before starting a new span.
     *
     * @return void
     */
    private function endOrphanedSpans(): void
    {
        // Close every span a failed setup left open, so it can't corrupt the next request's trace.
        while (!$this->spanStack->isEmpty()) {
            [$span, $scope] = $this->spanStack->pop();
            $span->setStatus(StatusCode::STATUS_ERROR, 'orphaned span: setup failed before afterResponse');
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Return a telemetry-safe URL without query strings or fragments.
     *
     * @param string $url Request URL being observed.
     * @return string Telemetry-safe URL value.
     */
    private static function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);
        // If the URL won't parse, return it untouched rather than guess.
        if (!is_array($parts)) {
            return $url;
        }

        $result = '';
        // Rebuild only the safe parts (scheme/host/port), dropping any query string that could hold PII.
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        // Host is the agent's address.
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        // Include the port only when one is present.
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        $result .= self::sanitizeRoute($url);

        return $result;
    }

    /**
     * Return a telemetry-safe route with dynamic identifiers collapsed.
     *
     * @param string $url Request URL being observed.
     * @return string Telemetry-safe route value.
     */
    private static function sanitizeRoute(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        // No usable path means the call hit the agent root ("/").
        if (!is_string($path) || $path === '') {
            return '/';
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        // A path of only slashes also collapses to the root route.
        if ($segments === []) {
            return '/';
        }

        $sanitized = [];
        // Rewrite each segment so ids/tokens become "{id}" and can't leak into traces.
        foreach ($segments as $index => $segment) {
            $previous = $sanitized[$index - 1] ?? null;
            // Collapse unsafe path characters before the route is shown in telemetry.
            $safeSegment = preg_replace('/[^A-Za-z0-9._~-]+/', '-', $segment) ?? '';
            $sanitized[] = self::isDynamicRouteSegment($safeSegment, $previous) ? '{id}' : $safeSegment;
        }

        return '/' . implode('/', $sanitized);
    }

    /**
     * Determine whether a route segment should be hidden as an identifier.
     *
     * @param string $segment Route segment being evaluated.
     * @param string|null $previous Previous sanitized route segment, when present.
     * @return bool True when the segment should be replaced with a placeholder.
     */
    private static function isDynamicRouteSegment(string $segment, ?string $previous): bool
    {
        // An empty segment is treated as a placeholder id.
        if ($segment === '') {
            return true;
        }

        // A purely numeric segment is almost always a record id (e.g. /users/42).
        if (ctype_digit($segment)) {
            return true;
        }

        // UUID-like path parts usually identify user records and should not appear in traces.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1) {
            return true;
        }

        // Long opaque tokens are treated as identifiers before telemetry is emitted.
        if (preg_match('/^[A-Za-z0-9_-]{24,}$/', $segment) === 1) {
            return true;
        }

        // Otherwise, a segment right after a resource name (…/session/<this>) is its id.
        return in_array($previous, [
            'conversation',
            'conversations',
            'document',
            'documents',
            'file',
            'files',
            'patient',
            'patients',
            'request',
            'requests',
            'session',
            'sessions',
        ], true);
    }

    /**
     * Record token usage metrics on a span.
     *
     * @param SpanInterface $span Span receiving telemetry attributes.
     * @param Usage $usage Token usage values to record.
     * @return void
     */
    private static function setUsageAttributes(SpanInterface $span, Usage $usage): void
    {
        $span->setAttribute('gen_ai.usage.input_tokens', $usage->inputTokens);
        $span->setAttribute('gen_ai.usage.output_tokens', $usage->outputTokens);
        $span->setAttribute('gen_ai.usage.cache_read_input_tokens', $usage->cacheReadInputTokens);
        $span->setAttribute('gen_ai.usage.cache_write_input_tokens', $usage->cacheWriteInputTokens);
    }

    /**
     * Record agent and session metadata on a span.
     *
     * @param SpanInterface $span Span receiving telemetry attributes.
     * @param string|null $agent Agent name from the response, when present.
     * @param string|null $sessionId Session identifier from the response, when present.
     * @return void
     */
    private static function setAgentAttributes(SpanInterface $span, ?string $agent, ?string $sessionId): void
    {
        // Tag which named agent answered, when the response identifies one.
        if ($agent !== null) {
            $span->setAttribute('strands.agent.name', $agent);
        }

        $span->setAttribute('strands.session.present', $sessionId !== null);
    }

    /**
     * Records tool use so the app can explain agent actions.
     *
     * @param list<array<string, mixed>> $toolsUsed Tools the agent called; empty when it answered without any.
     * @param SpanInterface $span Telemetry span updated for the app request.
     * @return void No returned value; updates client or observer state.
     */
    private static function setToolsAttributes(SpanInterface $span, array $toolsUsed): void
    {
        $names = [];
        // Pull out each tool's name so the trace shows what the agent actually did.
        foreach ($toolsUsed as $tool) {
            $name = $tool['name'] ?? null;
            // Keep only well-formed string names; ignore malformed tool entries.
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        $span->setAttribute('strands.tools.count', count($names));

        // Only add the names list when at least one tool ran (keeps empty traces clean).
        if ($names !== []) {
            $span->setAttribute('strands.tools.names', array_values(array_unique($names)));
        }
    }

    /**
     * Copies safe response summary fields into telemetry.
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

        $agent = $response['agent'] ?? null;
        $sessionId = $response['session_id'] ?? null;
        self::setAgentAttributes(
            $span,
            is_string($agent) ? $agent : null,
            is_string($sessionId) ? $sessionId : null,
        );

        $toolsUsed = $response['tools_used'] ?? null;
        // Record which tools ran when the endpoint listed them.
        if (is_array($toolsUsed)) {
            /** @var list<array<string, mixed>> $toolsUsed validated before app code uses it. */
            self::setToolsAttributes($span, $toolsUsed);
        }

        $stopReason = $response['stop_reason'] ?? null;
        // Record why the agent stopped, when the endpoint said so.
        if (is_string($stopReason)) {
            $span->setAttribute('gen_ai.response.finish_reason', $stopReason);
        }

        $model = $response['model'] ?? null;
        // Tag the model used, when the endpoint names one.
        if (is_string($model)) {
            $span->setAttribute('gen_ai.request.model', $model);
        }
    }

    /**
     * Return a low-cardinality error description safe for span status fields.
     *
     * @param \Throwable $error Optional transport or agent error raised by the operation.
     * @return string Safe, low-cardinality error description.
     */
    private static function safeErrorDescription(\Throwable $error): string
    {
        // Agent-reported failures get a stable, low-cardinality label for grouping in traces.
        if ($error instanceof AgentErrorException) {
            // Prefer the agent's own error code when present (e.g. "agent_error:rate_limited").
            if ($error->errorCode !== null) {
                return 'agent_error:' . $error->errorCode;
            }

            return sprintf('agent_http_%d', $error->statusCode);
        }

        return self::classBasename($error::class);
    }

    /**
     * Shortens class names for readable app telemetry.
     *
     * @param class-string $class Fully-qualified class name to shorten for telemetry.
     * @return string The short class name (no namespace) shown in trace attributes.
     */
    private static function classBasename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
