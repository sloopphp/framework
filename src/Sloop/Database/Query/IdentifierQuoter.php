<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * Splitting and backtick quoting of MySQL identifiers.
 *
 * Kept in one place because it is the boundary that stops a column name from
 * being read as SQL: Expression quotes the columns handed to its factories, and
 * Grammar quotes everything a query builder names. Two implementations of the
 * same escaping would be two chances to get it wrong.
 *
 * Quoting by doubling backticks assumes the connection charset is ASCII
 * transparent, which ConnectionConfigResolver enforces by rejecting the
 * charsets whose multi-byte characters can end in a backtick byte.
 *
 * @internal
 */
final class IdentifierQuoter
{
    /**
     * Split a possibly qualified identifier into its segments.
     *
     * Every segment has to be non-empty, which rejects the shapes that would
     * otherwise produce the empty identifier '``'.
     *
     * @param  string                   $identifier Identifier, optionally qualified ('users.score')
     * @return list<string>             Segments in written order
     * @throws InvalidArgumentException When any segment is empty
     */
    public static function split(string $identifier): array
    {
        $segments = explode('.', $identifier);

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException(
                    'Identifier must not contain an empty segment, got ' . $identifier . '.',
                );
            }
        }

        return $segments;
    }

    /**
     * Wrap one segment in backticks, doubling any backtick inside it.
     *
     * @param  string $segment Single identifier segment, already known to be non-empty
     * @return string Backtick-quoted segment
     */
    public static function quoteSegment(string $segment): string
    {
        return '`' . str_replace('`', '``', $segment) . '`';
    }

    /**
     * Quote a possibly qualified identifier, segment by segment.
     *
     * @param  string                   $identifier Identifier, optionally qualified ('users.score')
     * @return string                   Backtick-quoted identifier
     * @throws InvalidArgumentException When any segment is empty
     */
    public static function quote(string $identifier): string
    {
        return implode('.', array_map(
            self::quoteSegment(...),
            self::split($identifier),
        ));
    }
}
