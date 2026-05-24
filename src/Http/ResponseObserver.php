<?php

declare(strict_types=1);

namespace StrandsPhpClient\Http;

use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Observes parsed terminal operation data after HTTP calls complete.
 *
 * This is intentionally separate from RequestMiddleware so existing
 * beforeRequest()/afterResponse() implementations remain source-compatible.
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
     * @param array<string, mixed> $response
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
