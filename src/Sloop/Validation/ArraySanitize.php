<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Sanitizers applied to an array value before it is validated.
 *
 * The string sanitizers of Sanitize do not apply to an array, so the array
 * factories take these instead; any other transformation is passed to a
 * factory as a closure taking and returning an array.
 */
enum ArraySanitize
{
    /**
     * Drop the elements that count as empty, and renumber a list.
     *
     * Empty means what it means everywhere else in validation: null and the
     * empty string. A `0`, a `'0'` and a `false` are values, and stay.
     */
    case RemoveEmpty;

    /**
     * Drop every element that is `===` to an earlier one, and renumber a list.
     *
     * The first occurrence stays, and an array with other keys keeps them.
     * This runs on the input before the elements are given a type, so `'1'`
     * and `1` both stay; to compare the validated elements, use distinct().
     */
    case Unique;

    /**
     * Apply this sanitizer.
     *
     * @param  array<array-key, mixed> $value Input array
     * @return array<array-key, mixed>
     */
    public function apply(array $value): array
    {
        return match ($this) {
            self::RemoveEmpty => self::removeEmpty($value),
            self::Unique      => self::unique($value),
        };
    }

    /**
     * Drop null and '' elements, renumbering the result when the input was a list.
     *
     * @param  array<array-key, mixed> $value Input array
     * @return array<array-key, mixed>
     */
    private static function removeEmpty(array $value): array
    {
        $kept = array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== '');

        return array_is_list($value) ? array_values($kept) : $kept;
    }

    /**
     * Keep the first of every group of `===` elements, renumbering the result when the input was a list.
     *
     * @param  array<array-key, mixed> $value Input array
     * @return array<array-key, mixed>
     */
    private static function unique(array $value): array
    {
        $kept = DistinctValues::firstOccurrences($value);

        return array_is_list($value) ? array_values($kept) : $kept;
    }
}
