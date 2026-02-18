<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Token usage and performance statistics for an agent request/response.
 */
class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadInputTokens = 0,
        public readonly int $cacheWriteInputTokens = 0,
        public readonly int $latencyMs = 0,
        public readonly int $timeToFirstByteMs = 0,
    ) {
    }
}
