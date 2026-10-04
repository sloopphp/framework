<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Finds values that are `===` to an earlier one, without comparing every pair.
 *
 * Each value is turned into a string key that two values share exactly when
 * they are `===`: the type is part of the key, so `1` and `'1'` differ, and
 * `0.0` and `-0.0` share one. A value with no such key (an object, NAN, which
 * is not `===` to itself, or an array holding a float or an object) is
 * compared with the earlier ones of its kind one by one.
 *
 * @internal Used by ArraySanitize::Unique and the distinct() rules.
 */
final class DistinctValues
{
    /**
     * Whether any two of the values are `===`.
     *
     * @param  array<array-key, mixed> $values Values to compare
     * @return bool
     */
    public static function hasDuplicate(array $values): bool
    {
        return \count(self::firstOccurrences($values)) !== \count($values);
    }

    /**
     * The values with every one that is `===` to an earlier one dropped.
     *
     * The keys of the values kept are preserved.
     *
     * @param  array<array-key, mixed> $values Values to filter
     * @return array<array-key, mixed>
     */
    public static function firstOccurrences(array $values): array
    {
        $seen    = [];
        $unkeyed = [];
        $kept    = [];
        foreach ($values as $position => $value) {
            $key = self::key($value);
            if ($key === null) {
                if (\in_array($value, $unkeyed, true)) {
                    continue;
                }
                $unkeyed[] = $value;
            } elseif (\array_key_exists($key, $seen)) {
                continue;
            } else {
                $seen[$key] = $position;
            }
            $kept[$position] = $value;
        }

        return $kept;
    }

    /**
     * A string two values share exactly when they are `===`, or null when there is none.
     *
     * @param  mixed       $value Value to key
     * @return string|null
     */
    private static function key(mixed $value): ?string
    {
        if (\is_float($value)) {
            if (is_nan($value)) {
                return null;
            }

            // -0.0 === 0.0, but the two serialize differently.
            return serialize($value === 0.0 ? 0.0 : $value);
        }

        return self::serializesLikeIdentity($value) ? serialize($value) : null;
    }

    /**
     * Whether two values that serialize alike are exactly the values that are `===`.
     *
     * True for null, bool, int, string, and arrays of those: serialize() keeps
     * the type, the keys and their order, which is what `===` compares. A
     * float inside an array is left out for the two zeros and NAN, and an
     * object for comparing by identity.
     *
     * @param  mixed $value Value to check
     * @return bool
     */
    private static function serializesLikeIdentity(mixed $value): bool
    {
        if (!\is_array($value)) {
            return $value === null || \is_bool($value) || \is_int($value) || \is_string($value);
        }

        foreach ($value as $item) {
            if (!self::serializesLikeIdentity($item)) {
                return false;
            }
        }

        return true;
    }
}
