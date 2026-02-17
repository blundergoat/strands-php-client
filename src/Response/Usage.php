<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Token usage statistics for an agent request/response.
 */
class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }
}
