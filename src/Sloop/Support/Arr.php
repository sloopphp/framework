<?php

declare(strict_types=1);

namespace Sloop\Support;

/**
 * Array helper utilities.
 *
 * All methods are static. Dot-notation keys (e.g. "user.name") are supported
 * for nested array access in get/set/has.
 */
final class Arr
{
    /**
     * Get a value from a nested array using dot notation.
     *
     * @template TValue
     * @template TDefault
     * @param array<array-key, TValue> $array   Target array
     * @param string|int               $key     Dot-notated key
     * @param TDefault                 $default Returned when the key is missing
     * @return TValue|TDefault
     */
    public static function get(array $array, string|int $key, mixed $default = null): mixed
    {
        if (\is_int($key)) {
            return $array[$key] ?? $default;
        }

        if (\array_key_exists($key, $array)) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (!\is_array($array) || !\array_key_exists($segment, $array)) {
                return $default;
            }

            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * Set a value in a nested array using dot notation.
     *
     * Returns a new array with the value set. The original array is not modified.
     *
     * @param array<array-key, mixed> $array Source array
     * @param string                  $key   Dot-notated key
     * @param mixed                   $value Value to set
     * @return array<array-key, mixed>
     */
    public static function set(array $array, string $key, mixed $value): array
    {
        $keys    = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $segment) {
            if ($i === \count($keys) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !\is_array($current[$segment])) {
                    $current[$segment] = [];
                }

                $current = &$current[$segment];
            }
        }

        return $array;
    }

    /**
     * Check if a key exists in a nested array using dot notation.
     *
     * @param array<array-key, mixed> $array Target array
     * @param string|int              $key   Dot-notated key
     * @return bool
     */
    public static function has(array $array, string|int $key): bool
    {
        if (\is_int($key)) {
            return \array_key_exists($key, $array);
        }

        if (\array_key_exists($key, $array)) {
            return true;
        }

        foreach (explode('.', $key) as $segment) {
            if (!\is_array($array) || !\array_key_exists($segment, $array)) {
                return false;
            }

            $array = $array[$segment];
        }

        return true;
    }

    /**
     * Get a subset of the array with only the specified keys.
     *
     * @param array<array-key, mixed> $array Source array
     * @param array<int, string|int>  $keys  Keys to include
     * @return array<array-key, mixed>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Get the array without the specified keys.
     *
     * @param array<array-key, mixed> $array Source array
     * @param array<int, string|int>  $keys  Keys to exclude
     * @return array<array-key, mixed>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Get the first element matching an optional callback.
     *
     * @template TValue
     * @template TDefault
     * @param array<array-key, TValue>                 $array    Source array
     * @param (callable(TValue, array-key): bool)|null $callback Filter callback
     * @param TDefault                                 $default  Returned when no match
     * @return TValue|TDefault
     */
    public static function first(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($array)) {
                return $default;
            }

            return reset($array);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get the last element matching an optional callback.
     *
     * @template TValue
     * @template TDefault
     * @param array<array-key, TValue>                 $array    Source array
     * @param (callable(TValue, array-key): bool)|null $callback Filter callback
     * @param TDefault                                 $default  Returned when no match
     * @return TValue|TDefault
     */
    public static function last(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($array)) {
                return $default;
            }

            return end($array);
        }

        return self::first(array_reverse($array, true), $callback, $default);
    }

    /**
     * Flatten a multi-dimensional array into a single level.
     *
     * @param array<array-key, mixed> $array Source array
     * @param int                     $depth Maximum depth to flatten (INF for unlimited)
     * @return array<int, mixed>
     * @throws \InvalidArgumentException If depth is negative
     */
    public static function flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        if ($depth < 0) {
            throw new \InvalidArgumentException('Depth must not be negative, got ' . $depth . '.');
        }

        $result = [];

        foreach ($array as $item) {
            if (!\is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 0) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, self::flatten($item, $depth - 1));
            }
        }

        return $result;
    }

    /**
     * Pluck a single key's value from each sub-array.
     *
     * @param array<int, array<array-key, mixed>> $array Array of sub-arrays
     * @param string                              $value Key to extract
     * @param string|null                         $key   Optional key to use as the result's keys
     * @return array<array-key, mixed>
     * @throws \UnexpectedValueException If a key value is not a string or integer
     */
    public static function pluck(array $array, string $value, ?string $key = null): array
    {
        $result = [];

        foreach ($array as $item) {
            $itemValue = self::get($item, $value);

            if ($key !== null) {
                $itemKey = self::get($item, $key);

                if (!\is_string($itemKey) && !\is_int($itemKey)) {
                    throw new \UnexpectedValueException(
                        'Pluck key \'' . $key . '\' must resolve to a string or integer, got '
                        . get_debug_type($itemKey) . '.'
                    );
                }

                $result[$itemKey] = $itemValue;
            } else {
                $result[] = $itemValue;
            }
        }

        return $result;
    }

    /**
     * Recursively merge arrays. Numeric keys are appended, string keys are overwritten.
     *
     * @param array<array-key, mixed> $array     Base array
     * @param array<array-key, mixed> ...$arrays Arrays to merge
     * @return array<array-key, mixed>
     */
    public static function merge(array $array, array ...$arrays): array
    {
        foreach ($arrays as $other) {
            foreach ($other as $key => $value) {
                if (\is_int($key)) {
                    $array[] = $value;
                } elseif (\is_array($value) && isset($array[$key]) && \is_array($array[$key])) {
                    $array[$key] = self::merge($array[$key], $value);
                } else {
                    $array[$key] = $value;
                }
            }
        }

        return $array;
    }

    /**
     * Wrap the given value in an array if it is not already one.
     *
     * @param mixed $value Value to wrap
     * @return array<array-key, mixed>
     */
    public static function wrap(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        return $value === null ? [] : [$value];
    }
}
