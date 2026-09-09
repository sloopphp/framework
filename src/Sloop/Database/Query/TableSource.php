<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * Where a statement reads its rows from, and the name it refers to them by.
 *
 * The rows come either from a table the server already knows by name, or from
 * a statement whose result stands in for one. The second needs an alias: it
 * has no name of its own, and both servers refuse a derived table without one.
 * A named table may take an alias as well, and then that is the name qualified
 * columns are written against.
 *
 * The alias is checked here rather than where the clause is written, so a name
 * that cannot stand as one says so at the line that named it.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class TableSource
{
    /**
     * Name what a statement reads from.
     *
     * @param  string|SubQuery          $source Table name, optionally schema qualified, or a statement standing in for a table
     * @param  string|null              $alias  Name to refer to the rows by, or null to refer to them by the table name
     * @throws InvalidArgumentException When a statement standing in for a table has no alias, or an alias is not a single name
     */
    public function __construct(
        public string|SubQuery $source,
        public ?string $alias = null,
    ) {
        if ($source instanceof SubQuery && $alias === null) {
            throw new InvalidArgumentException(
                'A statement read as a table needs an alias, because its rows have no name of their own.',
            );
        }

        if ($alias !== null && \count(IdentifierQuoter::split($alias)) !== 1) {
            throw new InvalidArgumentException(
                'An alias is one name, so it cannot be qualified, got ' . $alias . '.',
            );
        }
    }
}
