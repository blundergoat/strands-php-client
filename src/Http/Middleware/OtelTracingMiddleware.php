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
class OtelTracingMiddleware implements RequestMiddleware
{
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
        $spanName = $this->deriveSpanName($url);

        $span = $this->tracer
            ->spanBuilder($spanName ?: 'strands.client request')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $span->setAttribute('http.request.method', 'POST');
        $span->setAttribute('url.full', self::stripQueryString($url));

        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host)) {
            $span->setAttribute('server.address', $host);
        }

        $port = parse_url($url, PHP_URL_PORT);
        if (is_int($port)) {
            $span->setAttribute('server.port', $port);
        }

        $scope = $span->activate();
        $this->spanStack->push([$span, $scope]);

        $carrier = $headers;
        $this->propagator->inject(
            $carrier,
            ArrayAccessGetterSetter::getInstance(),
            Context::getCurrent(),
        );

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

            if ($error !== null) {
                $span->recordException($error);
                $span->setAttribute('error.type', $error::class);
                $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());

                if ($error instanceof AgentErrorException) {
                    $span->setAttribute('strands.error.status_code', $error->statusCode);
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

    private function deriveSpanName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return $this->spanNamePrefix . ' request';
        }

        $segment = basename($path);

        if ($segment === '' || $segment === '/') {
            return $this->spanNamePrefix . ' request';
        }

        return $this->spanNamePrefix . ' ' . $segment;
    }

    private static function stripQueryString(string $url): string
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
        $result .= $parts['path'] ?? '';

        return $result;
    }
}
