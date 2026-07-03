<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Token counts and timing for a single agent request/response.
 *
 * This is what an app reads to show "cost" and speed: how many tokens the turn
 * consumed (including cache reads/writes) and how long the agent took. All
 * fields default to zero, so a response that omits usage is still safe to read.
 */
class Usage
{
    /**
     * Hold the token and timing counts for one turn.
     *
     * Usually built by fromArray() from the response's usage block.
     *
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
     *
     * @return int Total tokens consumed, for the app's usage/cost readout.
     */
    public function totalTokens(): int
    {
        // Prefer the server's own total when it sent one; otherwise add the two halves.
        if ($this->totalTokens > 0) {
            return $this->totalTokens;
        }

        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Create a Usage instance from a raw usage array (e.g. from API response).
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @return self New instance ready for app code.
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
     * Read one token/timing count, tolerating the wire's numeric quirks.
     *
     * Wrappers differ in how they spell and type these fields, so this accepts
     * snake_case or camelCase and coerces int/float/numeric-string alike.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @param string $snakeKey Snake-case usage field from the wire payload.
     * @param ?string $camelKey Camel-case fallback field from older payloads; null when there's no fallback to try.
     * @return int Count the app shows as usage, or 0 when the field is missing.
     */
    private static function intField(array $data, string $snakeKey, ?string $camelKey = null): int
    {
        $value = $data[$snakeKey] ?? ($camelKey !== null ? ($data[$camelKey] ?? 0) : 0);

        // Already a clean integer — the common case, hand it straight back.
        if (is_int($value)) {
            return $value;
        }

        // Some wrappers report counts as floats; round to whole tokens.
        if (is_float($value)) {
            return (int) round($value);
        }

        // Others send counts as numeric strings (e.g. "1024"); accept those too.
        if (is_string($value) && is_numeric($value)) {
            return (int) round((float) $value);
        }

        return 0;
    }
}
