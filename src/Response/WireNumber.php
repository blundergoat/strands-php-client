<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Normalizes optional numeric wire fields for response DTOs.
 *
 * Invoke responses, stream events, and citations all hydrate through it, so they apply the same conversion rules.
 * Anything absent, malformed, non-finite, or past PHP's integer range becomes null.
 * Required token counters keep their own zero-defaulting rules in Usage::fromArray().
 *
 * @internal Hydration helper shared by this library's DTOs; not part of the supported public API.
 */
final class WireNumber
{
    /**
     * Reads an optional whole number, such as a context size or citation page offset.
     * Use it in any fromArray() that hydrates a count or position the wrapper may omit.
     *
     * @param array<string, mixed> $wireData Decoded wire map; an empty map means the wrapper sent no number.
     * @param string $fieldName Wire field to read; a name the map does not contain counts as absent.
     * @return ?int Whole number, or null when the field is absent or unusable.
     */
    public static function optionalWholeNumber(array $wireData, string $fieldName): ?int
    {
        $wireValue = $wireData[$fieldName] ?? null;

        // A clean integer needs no conversion and preserves the wrapper's exact value.
        if (is_int($wireValue)) {
            return $wireValue;
        }

        // Some wrappers report a whole number as a float, so round it and let the range check below reject anything unusable.
        if (is_float($wireValue)) {
            return self::roundedWholeNumberOrNull($wireValue);
        }

        // Other wrappers send the number as text. Reading it as an integer first keeps large counts exact.
        // Converting a value like "9223372036854775807" through float could wrap it to an unrelated negative number.
        if (is_string($wireValue) && is_numeric($wireValue)) {
            $exactWholeNumber = filter_var($wireValue, FILTER_VALIDATE_INT);

            // Text that is already a whole number avoids precision loss through a float conversion.
            if ($exactWholeNumber !== false) {
                return $exactWholeNumber;
            }

            return self::roundedWholeNumberOrNull((float) $wireValue);
        }

        return null;
    }

    /**
     * Reads an optional decimal, such as the confidence behind a guardrail decision.
     * Use it in any fromArray() that hydrates a fractional value the wrapper may omit.
     *
     * @param array<string, mixed> $wireData Decoded wire map; an empty map means the wrapper sent no value.
     * @param string $fieldName Wire field to read; a name the map does not contain counts as absent.
     * @return ?float Decimal, or null when the field is absent or unusable.
     */
    public static function optionalDecimal(array $wireData, string $fieldName): ?float
    {
        $wireValue = $wireData[$fieldName] ?? null;

        // A plain number is ready to use once the finite check below accepts it.
        if (is_float($wireValue) || is_int($wireValue)) {
            return self::finiteDecimalOrNull((float) $wireValue);
        }

        // Some wrappers send the value as text such as "0.87"; an overflowing exponent becomes infinity during the cast.
        if (is_string($wireValue) && is_numeric($wireValue)) {
            return self::finiteDecimalOrNull((float) $wireValue);
        }

        return null;
    }

    /**
     * Rounds a wrapper number only while the result still fits a PHP integer.
     * Use it after float or text parsing so an unusable value becomes null instead of wrapping.
     *
     * @param float $wireValue Number supplied by a wrapper; non-finite or out-of-range values are rejected.
     * @return ?int Rounded whole number, or null when the value is unsafe.
     */
    private static function roundedWholeNumberOrNull(float $wireValue): ?int
    {
        $roundedWireValue = round($wireValue);

        // NaN, infinity, and overflowing values must not wrap into an unrelated integer.
        if (!is_finite($roundedWireValue) || $roundedWireValue >= (float) PHP_INT_MAX || $roundedWireValue < (float) PHP_INT_MIN) {
            return null;
        }

        return (int) $roundedWireValue;
    }

    /**
     * Keeps a decimal only while it can survive JSON encoding.
     * Use it for every optional decimal, because json_encode() fails outright on infinity and NaN.
     *
     * @param float $wireValue Number supplied by a wrapper; infinity and NaN are rejected.
     * @return ?float Finite decimal, or null when the value is unusable.
     */
    private static function finiteDecimalOrNull(float $wireValue): ?float
    {
        return is_finite($wireValue) ? $wireValue : null;
    }
}
