<?php

declare(strict_types=1);

namespace StrandsPhpClient\Http;

/**
 * Middleware for observing and modifying HTTP requests to Strands agents.
 *
 * Middleware is scoped to the logical operation (invoke, stream, postJson,
 * streamSse), not to individual HTTP attempts. When retries are enabled,
 * beforeRequest() runs once before the first attempt and afterResponse()
 * runs once after the final attempt (success or failure after exhausting retries).
 *
 * Use cases: tracing (OpenTelemetry, Datadog), custom header injection,
 * request logging, metrics collection.
 *
 * Security note: Middleware that logs request bodies should mask API keys,
 * patient data, or other PII. The SDK does not auto-mask.
 */
interface RequestMiddleware
{
    /**
     * Called once before the operation begins. May modify headers and body.
     *
     * Middleware runs before authentication so that body-modifying middleware
     * does not invalidate auth signatures (e.g. SigV4). When retries are
     * configured, the modified headers and body are reused for every retry
     * attempt within the same operation.
     *
     * @param string               $url     The full request URL.
     * @param array<string, string> $headers Current request headers.
     * @param string               $body    JSON-encoded request body.
     *
     * @return array{headers: array<string, string>, body: string}
     */
    public function beforeRequest(string $url, array $headers, string $body): array;

    /**
     * Called once after the operation completes (success or failure).
     * For observability only — exceptions thrown here are logged, not propagated.
     *
     * @param string          $url        The request URL.
     * @param int             $statusCode HTTP status code (200 on success, 0 if cancelled or no response received).
     * @param float           $durationMs Total operation duration in milliseconds (including retries).
     * @param \Throwable|null $error      The exception, if the operation failed.
     */
    public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void;
}
