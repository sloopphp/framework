<?php

declare(strict_types=1);

namespace Sloop\Validation;

use UnitEnum;

/**
 * Finds values that are `===` to an earlier one, without comparing every pair.
 *
 * Each value is turned into a string key that two values share exactly when
 * they are `===`: the type is part of the key, so `1` and `'1'` differ, and
 * `0.0` and `-0.0` share one. An enum case has one: there is one instance of
 * each case, so `===` compares the case. A value with no such key (any other
 * object, NAN, which is not `===` to itself, a resource, or an array holding
 * one) is compared with the earlier ones of its kind one by one. A request
 * body cannot hold one.
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
     * serialize() keeps the type, the keys and their order, which is what
     * `===` compares, once every float is written as the bits of its double
     * (the same for 0.0 and -0.0, whatever serialize_precision is).
     *
     * @param  mixed       $value Value to key
     * @return string|null
     */
    private static function key(mixed $value): ?string
    {
        $normalized = self::normalize($value);

        return $normalized === null ? null : serialize($normalized[0]);
    }

    /**
     * The value with every float written as its bits, or null when it cannot be keyed.
     *
     * Wrapped in a one-element array so that a null value is told apart from
     * "no key". NAN, which is not `===` to itself, an object other than an enum
     * case, compared by identity, and a resource cannot be keyed.
     *
     * @param  mixed             $value Value to normalize
     * @return array{mixed}|null
     */
    private static function normalize(mixed $value): ?array
    {
        if (\is_float($value)) {
            if (is_nan($value)) {
                return null;
            }

            // The bits of the double, so that the key does not depend on
            // serialize_precision; -0.0 === 0.0, so the two share one. Wrapped in
            // an object so that no string can share the key: an object given as
            // a value is never keyed.
            return [(object) ['float' => bin2hex(pack('E', $value === 0.0 ? 0.0 : $value))]];
        }

        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $index => $item) {
                $part = self::normalize($item);
                if ($part === null) {
                    return null;
                }
                $normalized[$index] = $part[0];
            }

            return [$normalized];
        }

        if ($value instanceof UnitEnum) {
            return [$value];
        }

        return \is_object($value) || \is_resource($value) ? null : [$value];
    }
}
