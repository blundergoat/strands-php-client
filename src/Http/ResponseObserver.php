<?php

declare(strict_types=1);

namespace StrandsPhpClient\Http;

use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * A place to watch the finished, parsed result of each agent call.
 *
 * Where RequestMiddleware sees raw HTTP, an observer receives the typed
 * outcome — the AgentResponse, StreamResult, or sanitized SSE summary — after
 * the client has parsed it. Ideal for metrics, tracing, and audit logging.
 * Kept separate from RequestMiddleware so existing implementations still compile.
 */
interface ResponseObserver
{
    /**
     * Observe parsed invoke response data after the HTTP request completes.
     *
     * @param string $url Request URL being observed.
     * @param AgentResponse $response Parsed response data for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void
     */
    public function afterInvoke(string $url, AgentResponse $response, float $durationMs): void;

    /**
     * Observe parsed stream result data after the HTTP stream completes.
     *
     * @param string $url Request URL being observed.
     * @param StreamResult $result Parsed stream result for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void
     */
    public function afterStream(string $url, StreamResult $result, float $durationMs): void;

    /**
     * Records parsed custom-endpoint data for app telemetry.
     *
     * @param array<string, mixed> $response Parsed custom-endpoint result; empty when the endpoint returned no data.
     * @param string $url agent endpoint the app is calling.
     * @param float $durationMs elapsed time reported to app telemetry.
     * @return void No returned value; updates client or observer state.
     */
    public function afterPostJson(string $url, array $response, float $durationMs): void;

    /**
     * Observe sanitized raw SSE stream summary data after the stream completes.
     *
     * @param string $url Request URL being observed.
     * @param StreamSseSummary $summary Sanitized raw SSE stream summary.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void
     */
    public function afterStreamSse(string $url, StreamSseSummary $summary, float $durationMs): void;
}
