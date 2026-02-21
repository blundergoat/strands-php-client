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

    /**
     * Create a Usage instance from a raw usage array (e.g. from API response).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inputTokens: self::intField($data, 'input_tokens'),
            outputTokens: self::intField($data, 'output_tokens'),
            cacheReadInputTokens: self::intField($data, 'cache_read_input_tokens'),
            cacheWriteInputTokens: self::intField($data, 'cache_write_input_tokens'),
            latencyMs: self::intField($data, 'latency_ms'),
            timeToFirstByteMs: self::intField($data, 'time_to_first_byte_ms'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
