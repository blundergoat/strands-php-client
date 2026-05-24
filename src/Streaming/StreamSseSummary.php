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
