<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * A column named where a value would otherwise stand.
 *
 * A comparison binds what is on its right-hand side, which is what keeps a
 * value out of the SQL. A column has to be written instead of bound, and this
 * is what says so: the name is held as it was given and a Grammar quotes it
 * when the statement is compiled, so it is reached by the table prefix the way
 * any other column reference is.
 *
 * That is the difference from naming the same column through Expression::of().
 * An Expression carries SQL that is written out as it stands, so neither the
 * quoting nor the prefix applies to what is inside it, and a statement that
 * runs under a prefix has to spell the real table name.
 *
 * @see Expression::column() for the factory that builds one of these
 */
final readonly class ColumnName
{
    /**
     * Name a column to be written where a value would be bound.
     *
     * The segments are checked here rather than left to the Grammar so that a
     * malformed name fails at the line that wrote it. How many segments a name
     * may have, and which of them carries the table prefix, stays the
     * Grammar's to say.
     *
     * @param  string                   $name Column name, optionally qualified ('users.id')
     * @throws InvalidArgumentException When a segment of the name is empty
     */
    public function __construct(
        public string $name,
    ) {
        IdentifierQuoter::split($name);
    }
}
