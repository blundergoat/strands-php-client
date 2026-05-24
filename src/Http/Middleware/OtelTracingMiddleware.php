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
    private const ATTRIBUTE_POLICY = 'strands-otel-v1';

    /** @var \SplStack<array{0: SpanInterface, 1: ScopeInterface}> */
    private \SplStack $spanStack;

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly TextMapPropagatorInterface $propagator,
        private readonly string $spanNamePrefix = 'strands.client',
    ) {
        /** @var \SplStack<array{0: SpanInterface, 1: ScopeInterface}> $stack */
        $stack = new \SplStack();
        $this->spanStack = $stack;
    }

    public static function create(TracerInterface $tracer): self
    {
        return new self($tracer, TraceContextPropagator::getInstance());
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{headers: array<string, string>, body: string}
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
        if (is_string($host)) {
            $span->setAttribute('server.address', $host);
        }

        $port = parse_url($url, PHP_URL_PORT);
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

        /** @var array<string, string> $injectedHeaders */
        $injectedHeaders = $carrier;

        return ['headers' => $injectedHeaders, 'body' => $body];
    }

    public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void
    {
        try {
            if ($this->spanStack->isEmpty()) {
                return;
            }

            [$span, $scope] = $this->spanStack->pop();

            if ($statusCode > 0) {
                $span->setAttribute('http.response.status_code', $statusCode);
            }

            $span->setAttribute('strands.operation.duration_ms', $durationMs);

            if ($error !== null) {
                $span->addEvent('exception', [
                    'exception.type' => $error::class,
                    'exception.message' => self::safeErrorDescription($error),
                    'exception.escaped' => true,
                ]);
                $span->setAttribute('error.type', self::classBasename($error::class));
                $span->setStatus(StatusCode::STATUS_ERROR, self::safeErrorDescription($error));

                if ($error instanceof AgentErrorException) {
                    $span->setAttribute('strands.error.status_code', $error->statusCode);

                    if ($error->errorCode !== null) {
                        $span->setAttribute('strands.error.code', $error->errorCode);
                    }
                }
            } elseif ($statusCode >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, sprintf('HTTP %d', $statusCode));
            }

            $scope->detach();
            $span->end();
        } catch (\Throwable) {
            // Contract: exceptions in afterResponse are swallowed.
        }
    }

    public function afterInvoke(string $url, AgentResponse $response, float $durationMs): void
    {
        $span = $this->currentSpan();
        if ($span === null) {
            return;
        }

        self::setUsageAttributes($span, $response->usage);
        self::setAgentAttributes($span, $response->agent, $response->sessionId);
        self::setToolsAttributes($span, $response->toolsUsed);

        if ($response->stopReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $response->stopReason->value);
        }

        $model = $response->metadata['model'] ?? null;
        if (is_string($model)) {
            $span->setAttribute('gen_ai.request.model', $model);
        }
    }

    public function afterStream(string $url, StreamResult $result, float $durationMs): void
    {
        $span = $this->currentSpan();
        if ($span === null) {
            return;
        }

        self::setUsageAttributes($span, $result->usage);
        self::setToolsAttributes($span, $result->toolsUsed);
        $span->setAttribute('strands.session.present', $result->sessionId !== null);
        $span->setAttribute('strands.stream.text_events', $result->textEvents);
        $span->setAttribute('strands.stream.total_events', $result->totalEvents);
        $span->setAttribute('strands.stream.cancelled', $result->cancelled);

        if ($result->timeToFirstTextTokenMs !== null) {
            $span->setAttribute('strands.stream.ttft_ms', $result->timeToFirstTextTokenMs);
        }

        if ($result->stopReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $result->stopReason->value);
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    public function afterPostJson(string $url, array $response, float $durationMs): void
    {
        $span = $this->currentSpan();
        if ($span === null) {
            return;
        }

        self::setRawResponseSummaryAttributes($span, $response);
    }

    public function afterStreamSse(string $url, StreamSseSummary $summary, float $durationMs): void
    {
        $span = $this->currentSpan();
        if ($span === null) {
            return;
        }

        $span->setAttribute('strands.stream.text_events', $summary->textEvents);
        $span->setAttribute('strands.stream.total_events', $summary->totalEvents);
        $span->setAttribute('strands.stream.cancelled', $summary->cancelled);

        if ($summary->usage !== null) {
            self::setUsageAttributes($span, $summary->usage);
        }

        if ($summary->stopReason !== null) {
            $span->setAttribute('gen_ai.response.finish_reason', $summary->stopReason);
        }
    }

    /**
     * @return non-empty-string
     */
    private function deriveSpanName(string $route, string $operation): string
    {
        $prefix = $this->spanNamePrefix !== '' ? $this->spanNamePrefix : 'strands.client';

        if ($operation === 'invoke' || $operation === 'stream') {
            return $prefix . '.' . $operation;
        }

        $routeName = trim($route, '/');
        if ($routeName === '') {
            return $prefix . '.' . $operation;
        }

        $routeName = str_replace(['/', '{', '}'], ['.', '', ''], $routeName);

        return $prefix . '.custom.' . $routeName;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function deriveOperationName(string $route, array $headers): string
    {
        if ($route === '/invoke') {
            return 'invoke';
        }

        if ($route === '/stream') {
            return 'stream';
        }

        $accept = $headers['Accept'] ?? '';

        return str_contains($accept, 'text/event-stream')
            ? 'stream_sse'
            : 'post_json';
    }

    private function currentSpan(): ?SpanInterface
    {
        if ($this->spanStack->isEmpty()) {
            return null;
        }

        [$span] = $this->spanStack->top();

        return $span;
    }

    private function endOrphanedSpans(): void
    {
        while (!$this->spanStack->isEmpty()) {
            [$span, $scope] = $this->spanStack->pop();
            $span->setStatus(StatusCode::STATUS_ERROR, 'orphaned span: setup failed before afterResponse');
            $scope->detach();
            $span->end();
        }
    }

    private static function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        $result .= self::sanitizeRoute($url);

        return $result;
    }

    private static function sanitizeRoute(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return '/';
        }

        $sanitized = [];
        foreach ($segments as $index => $segment) {
            $previous = $sanitized[$index - 1] ?? null;
            $safeSegment = preg_replace('/[^A-Za-z0-9._~-]+/', '-', $segment) ?? '';
            $sanitized[] = self::isDynamicRouteSegment($safeSegment, $previous) ? '{id}' : $safeSegment;
        }

        return '/' . implode('/', $sanitized);
    }

    private static function isDynamicRouteSegment(string $segment, ?string $previous): bool
    {
        if ($segment === '') {
            return true;
        }

        if (ctype_digit($segment)) {
            return true;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1) {
            return true;
        }

        if (preg_match('/^[A-Za-z0-9_-]{24,}$/', $segment) === 1) {
            return true;
        }

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

    private static function setUsageAttributes(SpanInterface $span, Usage $usage): void
    {
        $span->setAttribute('gen_ai.usage.input_tokens', $usage->inputTokens);
        $span->setAttribute('gen_ai.usage.output_tokens', $usage->outputTokens);
        $span->setAttribute('gen_ai.usage.cache_read_input_tokens', $usage->cacheReadInputTokens);
        $span->setAttribute('gen_ai.usage.cache_write_input_tokens', $usage->cacheWriteInputTokens);
    }

    private static function setAgentAttributes(SpanInterface $span, ?string $agent, ?string $sessionId): void
    {
        if ($agent !== null) {
            $span->setAttribute('strands.agent.name', $agent);
        }

        $span->setAttribute('strands.session.present', $sessionId !== null);
    }

    /**
     * @param list<array<string, mixed>> $toolsUsed
     */
    private static function setToolsAttributes(SpanInterface $span, array $toolsUsed): void
    {
        $names = [];
        foreach ($toolsUsed as $tool) {
            $name = $tool['name'] ?? null;
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        $span->setAttribute('strands.tools.count', count($names));

        if ($names !== []) {
            $span->setAttribute('strands.tools.names', array_values(array_unique($names)));
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function setRawResponseSummaryAttributes(SpanInterface $span, array $response): void
    {
        $rawUsage = $response['usage'] ?? null;
        if (is_array($rawUsage)) {
            /** @var array<string, mixed> $rawUsage */
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
        if (is_array($toolsUsed)) {
            /** @var list<array<string, mixed>> $toolsUsed */
            self::setToolsAttributes($span, $toolsUsed);
        }

        $stopReason = $response['stop_reason'] ?? null;
        if (is_string($stopReason)) {
            $span->setAttribute('gen_ai.response.finish_reason', $stopReason);
        }

        $model = $response['model'] ?? null;
        if (is_string($model)) {
            $span->setAttribute('gen_ai.request.model', $model);
        }
    }

    private static function safeErrorDescription(\Throwable $error): string
    {
        if ($error instanceof AgentErrorException) {
            if ($error->errorCode !== null) {
                return 'agent_error:' . $error->errorCode;
            }

            return sprintf('agent_http_%d', $error->statusCode);
        }

        return self::classBasename($error::class);
    }

    /**
     * @param class-string $class
     */
    private static function classBasename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
