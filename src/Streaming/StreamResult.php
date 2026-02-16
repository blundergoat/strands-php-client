<?php

declare(strict_types=1);

namespace Strands\Streaming;

use Strands\Response\Usage;

/**
 * The accumulated result of a completed stream() call.
 */
class StreamResult
{
    /**
     * @param string       $text        The full text assembled from all Text events.
     * @param string|null  $sessionId   Session ID from the Complete event.
     * @param Usage        $usage       Token usage statistics.
     * @param list<array{name: string, duration_ms?: int}>  $toolsUsed  Tools the agent used.
     * @param int          $textEvents  Number of Text events received.
     * @param int          $totalEvents Total number of events received.
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $sessionId = null,
        public readonly Usage $usage = new Usage(),
        public readonly array $toolsUsed = [],
        public readonly int $textEvents = 0,
        public readonly int $totalEvents = 0,
    ) {
    }
}
