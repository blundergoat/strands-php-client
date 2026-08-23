<?php

declare(strict_types=1);

namespace StrandsPhpClient\Http;

/**
 * Observes or adjusts one logical agent request without changing the caller contract.
 *
 * It wraps invoke(), stream(), postJson(), or streamSse() once, even when the client retries the underlying HTTP call.
 * Use it for tracing, safe header injection, request metrics, or logging that must follow the whole operation.
 *
 * Middleware that records bodies must mask API keys, patient data, and other private content because the client does not mask it automatically.
 */
interface RequestMiddleware
{
    /**
     * Prepare the headers and body once before the app's agent operation starts.
     * This runs before authentication, so a modified body is signed correctly and then reused for every retry.
     *
     * @param string               $url     The full request URL.
     * @param array<string, string> $headers Current request headers.
     * @param string               $body    JSON-encoded request body.
     *
     * @return array{headers: array<string, string>, body: string} Two request fields; headers may be empty, but the result map never is.
     */
    public function beforeRequest(string $url, array $headers, string $body): array;

    /**
     * Observe the final operation outcome once, after success, cancellation, or the last failed retry.
     * Teardown contract: setup failures notify only middleware the request reached; observer errors are logged and never replace the app's result.
     *
     * @param string          $url        The request URL.
     * @param int             $statusCode HTTP status code (200 on success, 0 if cancelled or no response received).
     * @param float           $durationMs Total operation duration in milliseconds (including retries).
     * @param \Throwable|null $error      The failure that ended the operation, or null when the request succeeded.
     * @return void No returned value; updates client or observer state.
     */
    public function afterResponse(string $url, int $statusCode, float $durationMs, ?\Throwable $error = null): void;
}
