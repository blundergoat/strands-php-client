<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Holds token counts and server timing for one agent turn.
 *
 * Read it for usage, cost, caching, and latency displays after invoke() or stream().
 * Every public property remains an integer for 1.x compatibility; fractional wire timings are rounded and unsafe numbers become zero.
 *
 * Missing usage also becomes zero, so an app never needs nullable counter checks.
 */
class Usage
{
    /**
     * Stores the integer counters an app can show for one completed agent turn.
     * Use fromArray() for wire data; direct construction is mainly for tests, fixtures, and app-created summaries.
     *
     * @param int $inputTokens            Number of input tokens processed.
     * @param int $outputTokens           Number of output tokens generated.
     * @param int $cacheReadInputTokens   Input tokens served from cache.
     * @param int $cacheWriteInputTokens  Input tokens written to cache.
     * @param int $latencyMs              Server-reported total latency in rounded milliseconds.
     * @param int $timeToFirstByteMs      Server-reported time from request receipt to first byte sent, rounded.
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
     * Returns the server total when available, otherwise adds input and output tokens.
     * Use it for one caller-facing total without duplicating the fallback rule in the UI.
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
     * Converts a raw usage block into safe integer counters for app readouts.
     * Use it at response boundaries; missing, malformed, non-finite, or out-of-range values become zero.
     *
     * @param array<string, mixed> $data Decoded usage block; an empty array leaves every caller-visible counter at zero.
     * @return self Hydrated usage counters; never null and zeroed for empty input.
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
     * Normalizes one token or timing field while preserving the public 1.x integer contract.
     * Use it during hydration; snake_case wins, camelCase is a fallback, fractional values round, and unusable values become zero.
     *
     * @param array<string, mixed> $usageData Decoded usage block; a missing or unusable value becomes zero for the caller.
     * @param string $snakeCaseField Snake-case wire field; an empty name reads only an empty-name key if one exists.
     * @param ?string $camelCaseField Older camelCase fallback; null skips fallback lookup, while an empty string checks an empty-name key.
     * @return int Count the app shows as usage, or 0 when the field is missing.
     */
    private static function intField(array $usageData, string $snakeCaseField, ?string $camelCaseField = null): int
    {
        $numericUsageValue = self::numberField($usageData, $snakeCaseField, $camelCaseField);

        // An integer already fits the public 1.x property exactly, so no rounding or range conversion is needed.
        if (is_int($numericUsageValue)) {
            return $numericUsageValue;
        }

        $roundedUsageValue = round($numericUsageValue);

        // A corrupt or extreme wrapper value must not wrap into a believable token count in the app's cost display.
        if (!is_finite($roundedUsageValue) || $roundedUsageValue >= (float) PHP_INT_MAX || $roundedUsageValue < (float) PHP_INT_MIN) {
            return 0;
        }

        return (int) $roundedUsageValue;
    }

    /**
     * Reads one wire number before intField() rounds it for the public usage display.
     * Use it to accept exact integers, finite floats, and numeric strings while rejecting every other value as zero.
     *
     * @param array<string, mixed> $usageData Decoded usage block; a missing or unusable value becomes zero for the caller.
     * @param string $snakeCaseField Snake-case wire field; an empty name reads only an empty-name key if one exists.
     * @param ?string $camelCaseField Older camelCase fallback; null skips fallback lookup, while an empty string checks an empty-name key.
     * @return int|float Numeric value for app readouts, or 0 when the field is missing.
     */
    private static function numberField(array $usageData, string $snakeCaseField, ?string $camelCaseField = null): int|float
    {
        // A missing snake_case value falls back to the older camelCase spelling when one exists, then to zero for the app display.
        $wireValue = $usageData[$snakeCaseField] ?? ($camelCaseField !== null ? ($usageData[$camelCaseField] ?? 0) : 0);

        // Already a clean integer — the common case, hand it straight back.
        if (is_int($wireValue)) {
            return $wireValue;
        }

        // Wire values may be fractional milliseconds; keep finite fractions until intField() rounds them.
        if (is_float($wireValue)) {
            return is_finite($wireValue) ? $wireValue : 0;
        }

        // Other wrappers send counts as numeric strings (for example, "1024" or "1e3"); preserve exact integers before trying a finite float.
        if (is_string($wireValue) && is_numeric($wireValue)) {
            $integerUsageValue = filter_var($wireValue, FILTER_VALIDATE_INT);

            // A valid integer string avoids the precision loss that converting a large token count through float would introduce.
            if ($integerUsageValue !== false) {
                return $integerUsageValue;
            }

            $parsedUsageNumber = (float) $wireValue;

            return is_finite($parsedUsageNumber) ? $parsedUsageNumber : 0;
        }

        return 0;
    }
}
