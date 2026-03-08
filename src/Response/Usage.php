<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Token usage and performance statistics for an agent request/response.
 */
class Usage
{
    /**
     * @param int $inputTokens            Number of input tokens processed.
     * @param int $outputTokens           Number of output tokens generated.
     * @param int $cacheReadInputTokens   Input tokens served from cache.
     * @param int $cacheWriteInputTokens  Input tokens written to cache.
     * @param int $latencyMs              Server-reported total latency in milliseconds.
     * @param int $timeToFirstByteMs      Server-reported time from request receipt to first byte sent.
     */
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
     * Total tokens consumed (input + output).
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
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
