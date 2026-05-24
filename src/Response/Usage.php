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
     * @param int $totalTokens            Server-reported total tokens, when emitted.
     */
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadInputTokens = 0,
        public readonly int $cacheWriteInputTokens = 0,
        public readonly int $latencyMs = 0,
        public readonly int $timeToFirstByteMs = 0,
        public readonly int $totalTokens = 0,
    ) {
    }

    /**
     * Total tokens consumed (input + output).
     */
    public function totalTokens(): int
    {
        if ($this->totalTokens > 0) {
            return $this->totalTokens;
        }

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
            inputTokens: self::intField($data, 'input_tokens', 'inputTokens'),
            outputTokens: self::intField($data, 'output_tokens', 'outputTokens'),
            cacheReadInputTokens: self::intField($data, 'cache_read_input_tokens', 'cacheReadInputTokens'),
            cacheWriteInputTokens: self::intField($data, 'cache_write_input_tokens', 'cacheWriteInputTokens'),
            latencyMs: self::intField($data, 'latency_ms', 'latencyMs'),
            timeToFirstByteMs: self::intField($data, 'time_to_first_byte_ms', 'timeToFirstByteMs'),
            totalTokens: self::intField($data, 'total_tokens', 'totalTokens'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intField(array $data, string $snakeKey, ?string $camelKey = null): int
    {
        $value = $data[$snakeKey] ?? ($camelKey !== null ? ($data[$camelKey] ?? 0) : 0);

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) round((float) $value);
        }

        return 0;
    }
}
