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
     * Apply this sanitizer.
     *
     * @param  array<array-key, mixed> $value Input array
     * @return array<array-key, mixed>
     */
    public function apply(array $value): array
    {
        return match ($this) {
            self::RemoveEmpty => self::removeEmpty($value),
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
}
