<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

use StrandsPhpClient\Response\Usage;

/**
 * Sanitized terminal summary for raw streamSse() custom endpoint streams.
 *
 * Raw event payloads are app-owned and may contain PHI. Observability code uses
 * this summary instead of inspecting arbitrary streamSse() event fields.
 */
class StreamSseSummary
{
    /**
     * Create a sanitized summary for a raw SSE stream.
     *
     * @param int $totalEvents Total number of raw SSE events seen.
     * @param int $textEvents Number of raw text events seen.
     * @param bool $cancelled Whether the caller cancelled the stream.
     * @param string|null $terminalType Terminal raw event type, when one was observed.
     * @param Usage|null $usage Token usage values to record.
     * @param string|null $stopReason Stop reason from the terminal event, when supplied.
     */
    public function __construct(
        public readonly int $totalEvents = 0,
        public readonly int $textEvents = 0,
        public readonly bool $cancelled = false,
        public readonly ?string $terminalType = null,
        public readonly ?Usage $usage = null,
        public readonly ?string $stopReason = null,
    ) {
    }
}
