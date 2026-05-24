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
    public function afterInvoke(string $url, AgentResponse $response, float $durationMs): void;

    public function afterStream(string $url, StreamResult $result, float $durationMs): void;

    /**
     * @param array<string, mixed> $response
     */
    public function afterPostJson(string $url, array $response, float $durationMs): void;

    public function afterStreamSse(string $url, StreamSseSummary $summary, float $durationMs): void;
}
