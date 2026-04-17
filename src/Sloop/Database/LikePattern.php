<?php

declare(strict_types=1);

namespace Sloop\Database;

use InvalidArgumentException;

/**
 * Helper for escaping user input embedded in SQL LIKE patterns.
 *
 * Wildcards ('%' and '_') and the escape character itself are escaped so
 * that raw user input can be safely placed inside a LIKE expression without
 * being interpreted as wildcards.
 */
final class LikePattern
{
    /**
     * Escape wildcards in a LIKE pattern.
     *
     * Escapes the escape character itself, '%', and '_' so they are treated
     * as literal characters. Default escape character is backslash, which is
     * the MySQL/MariaDB default. Pass a custom escape character to match the
     * `LIKE ... ESCAPE '<char>'` clause.
     *
     * @param  string $value  Raw user input to embed in a LIKE pattern
     * @param  string $escape Single-byte escape character (must not be '%' or '_')
     * @return string Escaped value safe for use in a LIKE expression
     * @throws InvalidArgumentException When $escape is not exactly one byte, or is a LIKE wildcard
     */
    public static function escape(string $value, string $escape = '\\'): string
    {
        if (\strlen($escape) !== 1) {
            throw new InvalidArgumentException(
                'Escape character must be exactly one byte, got ' . \strlen($escape) . '.',
            );
        }

        if ($escape === '%' || $escape === '_') {
            throw new InvalidArgumentException(
                'Escape character must not be a LIKE wildcard, got ' . $escape . '.',
            );
        }

        return str_replace(
            [$escape, '%', '_'],
            [$escape . $escape, $escape . '%', $escape . '_'],
            $value,
        );
    }
}
