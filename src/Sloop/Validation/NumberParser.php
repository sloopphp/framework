<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Strict parsing of numbers that arrive as JSON numbers or as strings.
 *
 * @internal Shared by IntRule, FloatRule, and EnumRule.
 */
final class NumberParser
{
    /**
     * Read an int from a JSON int or a string of the form `-?\d+`.
     *
     * Leading zeros are accepted (`"042"` is 42); a sign `+`, surrounding
     * whitespace, a decimal point, an exponent, a float, and a value outside
     * the int range are not.
     *
     * @param  mixed    $value Raw value
     * @return int|null The int, or null when the value is not an int in that form
     */
    public static function int(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (!\is_string($value) || preg_match('/\A(-?)0*(\d+)\z/', $value, $matches) !== 1) {
            return null;
        }

        $digits    = $matches[2];
        $canonical = $digits === '0' ? '0' : $matches[1] . $digits;
        $parsed    = (int) $canonical;

        return (string) $parsed === $canonical ? $parsed : null;
    }

    /**
     * Read a finite float from a JSON int or float, or a string of the form `-?\d+(\.\d+)?`.
     *
     * @param  mixed      $value Raw value
     * @return float|null The float, or null when the value is not a finite number in that form
     */
    public static function float(mixed $value): ?float
    {
        if (\is_int($value)) {
            return (float) $value;
        }
        if (\is_string($value)) {
            if (preg_match('/\A-?\d+(\.\d+)?\z/', $value) !== 1) {
                return null;
            }
            $value = (float) $value;
        }

        return \is_float($value) && is_finite($value) ? $value : null;
    }
}
