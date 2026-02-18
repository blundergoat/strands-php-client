<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

use StrandsPhpClient\Response\StopReason;
use StrandsPhpClient\Response\Usage;

/**
 * The accumulated result of a completed stream() call.
 */
class StreamResult
{
    /**
     * @param string          $text        The full text assembled from all Text events.
     * @param string|null     $sessionId   Session ID from the Complete event.
     * @param Usage           $usage       Token usage statistics.
     * @param list<array{name: string, duration_ms?: int}>  $toolsUsed  Tools the agent used.
     * @param int             $textEvents  Number of Text events received.
     * @param int             $totalEvents Total number of events received.
     * @param StopReason|null $stopReason  Why the agent stopped generating output.
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $sessionId = null,
        public readonly Usage $usage = new Usage(),
        public readonly array $toolsUsed = [],
        public readonly int $textEvents = 0,
        public readonly int $totalEvents = 0,
        public readonly ?StopReason $stopReason = null,
    ) {
    }
}
