<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Turns the optional numbers a wrapper sends into values an app can display without checking them again.
 *
 * Invoke responses, stream events, and citations all hydrate through it, so one wrapper value cannot mean two different things on two screens.
 * Anything absent, malformed, non-finite, or past PHP's integer range becomes null, which the app hides rather than showing a wrong number.
 * Required token counters keep their own zero-defaulting rules in Usage::fromArray().
 *
 * @internal Hydration helper shared by this library's DTOs; not part of the supported public API.
 */
final class WireNumber
{
    /**
     * Reads a whole number the app may show or hide, such as a context size or a citation page offset.
     * Use it in any fromArray() that hydrates a count or position the screen can leave out.
     *
     * @param array<string, mixed> $wireData Decoded wire map; an empty map means the wrapper sent no number and the screen shows none.
     * @param string $fieldName Wire field to read; a name the map does not contain counts as absent.
     * @return ?int Whole number the app can display, or null when the wrapper sent nothing it can trust.
     */
    public static function optionalWholeNumber(array $wireData, string $fieldName): ?int
    {
        $wireValue = $wireData[$fieldName] ?? null;

        // A clean integer needs no conversion, so the app sees the wrapper's own value.
        if (is_int($wireValue)) {
            return $wireValue;
        }

        // Some wrappers report a whole number as a float, so round it and let the range check below reject anything unusable.
        if (is_float($wireValue)) {
            return self::roundedWholeNumberOrNull($wireValue);
        }

        // Other wrappers send the number as text. Reading it as an integer first keeps large counts exact.
        // Converting a value like "9223372036854775807" through float instead would wrap it to a negative number the user would believe.
        if (is_string($wireValue) && is_numeric($wireValue)) {
            $exactWholeNumber = filter_var($wireValue, FILTER_VALIDATE_INT);

            // Text that is already a whole number needs no rounding, so the app shows exactly what the wrapper sent.
            if ($exactWholeNumber !== false) {
                return $exactWholeNumber;
            }

            return self::roundedWholeNumberOrNull((float) $wireValue);
        }

        return null;
    }

    /**
     * Reads a decimal the app may show or hide, such as the confidence behind a guardrail decision.
     * Use it in any fromArray() that hydrates a fractional value the screen can leave out.
     *
     * @param array<string, mixed> $wireData Decoded wire map; an empty map means the wrapper sent no value and the screen shows none.
     * @param string $fieldName Wire field to read; a name the map does not contain counts as absent.
     * @return ?float Decimal the app can display, or null when the wrapper sent nothing it can trust.
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
     * Rounds a wrapper number only while the result still fits the integer the app will display.
     * Use it after float or text parsing so an unusable size is hidden instead of shown incorrectly.
     *
     * @param float $wireValue Number supplied by a wrapper; non-finite or out-of-range means the app cannot show it.
     * @return ?int Rounded whole number for the app, or null so the screen omits an unsafe value.
     */
    private static function roundedWholeNumberOrNull(float $wireValue): ?int
    {
        $roundedWireValue = round($wireValue);

        // NaN, infinity, and overflowing values must not wrap into an unrelated number the user would read as a real limit.
        if (!is_finite($roundedWireValue) || $roundedWireValue >= (float) PHP_INT_MAX || $roundedWireValue < (float) PHP_INT_MIN) {
            return null;
        }

        return (int) $roundedWireValue;
    }

    /**
     * Keeps a decimal only while it can still survive the app's own JSON encoding.
     * Use it for every optional decimal, because json_encode() fails outright on infinity and NaN.
     *
     * @param float $wireValue Number supplied by a wrapper; infinity or NaN means the app can neither show nor re-encode it.
     * @return ?float Finite decimal for the app, or null so the screen omits an unusable value.
     */
    private static function finiteDecimalOrNull(float $wireValue): ?float
    {
        return is_finite($wireValue) ? $wireValue : null;
    }
}
