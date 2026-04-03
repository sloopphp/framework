<?php

declare(strict_types=1);

namespace Sloop\Support;

use Random\Randomizer;

/**
 * String helper utilities.
 *
 * All methods are static. Fluent string wrapper is planned for v0.2.
 */
final class Str
{
    /**
     * camelCase conversion cache.
     *
     * @var array<string, string>
     */
    private static array $camelCache = [];

    /**
     * snake_case conversion cache.
     *
     * @var array<string, string>
     */
    private static array $snakeCache = [];

    /**
     * StudlyCase conversion cache.
     *
     * @var array<string, string>
     */
    private static array $studlyCache = [];

    /**
     * Convert a string to camelCase.
     *
     * @param string $value String to convert
     * @return string
     */
    public static function camel(string $value): string
    {
        if (isset(self::$camelCache[$value])) {
            return self::$camelCache[$value];
        }

        return self::$camelCache[$value] = lcfirst(self::studly($value));
    }

    /**
     * Convert a string to snake_case.
     *
     * @param string $value     String to convert
     * @param string $delimiter Delimiter character
     * @return string
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $cacheKey = $value . $delimiter;

        if (isset(self::$snakeCache[$cacheKey])) {
            return self::$snakeCache[$cacheKey];
        }

        $result = preg_replace('/[A-Z]/', $delimiter . '$0', $value) ?? $value;
        $result = preg_replace('/[-\s]+/', $delimiter, $result) ?? $result;
        $result = strtolower(trim($result, $delimiter));

        return self::$snakeCache[$cacheKey] = $result;
    }

    /**
     * Convert a string to StudlyCase (PascalCase).
     *
     * @param string $value String to convert
     * @return string
     */
    public static function studly(string $value): string
    {
        if (isset(self::$studlyCache[$value])) {
            return self::$studlyCache[$value];
        }

        $words  = preg_split('/[-_\s]+/', $value) ?: [$value];
        $studly = implode('', array_map(ucfirst(...), $words));

        return self::$studlyCache[$value] = $studly;
    }

    /**
     * Generate a URL-friendly slug.
     *
     * @param string $value     String to slugify
     * @param string $separator Word separator
     * @return string
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $value) ?? $value;
        $value = preg_replace('/[-\s]+/', $separator, $value) ?? $value;

        return mb_strtolower(trim($value, $separator));
    }

    /**
     * Generate a random alphanumeric string.
     *
     * @param int $length Length of the generated string
     * @return string
     * @throws \InvalidArgumentException If length is negative
     */
    public static function random(int $length = 16): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Length must not be negative, got ' . $length . '.');
        }

        $chars      = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $charsLen   = \strlen($chars);
        $randomizer = new Randomizer();
        $result     = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[$randomizer->getInt(0, $charsLen - 1)];
        }

        return $result;
    }

    /**
     * Truncate a string to the given length, appending a suffix if truncated.
     *
     * @param string $value String to truncate
     * @param int    $limit Maximum character length
     * @param string $end   Suffix appended when truncated
     * @return string
     * @throws \InvalidArgumentException If limit is negative
     */
    public static function truncate(string $value, int $limit, string $end = '...'): string
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit must not be negative, got ' . $limit . '.');
        }

        if (grapheme_strlen($value) <= $limit) {
            return $value;
        }

        return grapheme_substr($value, 0, $limit) . $end;
    }

    /**
     * Determine if a string contains a given substring or any of the given substrings.
     *
     * @param string                    $haystack String to search in
     * @param string|array<int, string> $needles  Substring(s) to search for
     * @return bool
     */
    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a string starts with a given substring or any of the given substrings.
     *
     * @param string                    $haystack String to search in
     * @param string|array<int, string> $needles  Prefix(es) to check
     * @return bool
     */
    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a string ends with a given substring or any of the given substrings.
     *
     * @param string                    $haystack String to search in
     * @param string|array<int, string> $needles  Suffix(es) to check
     * @return bool
     */
    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
