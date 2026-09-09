<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * Sort direction of one ORDER BY term.
 *
 * Backed by the SQL keyword so that a grammar can write the case straight out.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
enum Direction: string
{
    case Ascending  = 'ASC';
    case Descending = 'DESC';

    /**
     * Read a direction written as a keyword, in any case.
     *
     * @param  string                   $direction Sort direction as the caller wrote it
     * @return self                     The direction that keyword names
     * @throws InvalidArgumentException When the keyword is neither ASC nor DESC
     */
    public static function fromKeyword(string $direction): self
    {
        return self::tryFrom(strtoupper($direction))
            ?? throw new InvalidArgumentException(
                'A sort direction is ASC or DESC, got "' . $direction . '".',
            );
    }
}
