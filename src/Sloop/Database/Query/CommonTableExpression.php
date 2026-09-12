<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * One statement named in a WITH clause, for the statement that follows to read.
 *
 * The name stands where a table name would, so a statement reads it through the
 * ordinary FROM and JOIN clauses. That is also why the table prefix reaches it:
 * a reference written against the name is prefixed like any other table, so the
 * name introduced here has to carry the same prefix for the two ends to meet.
 *
 * The columns the rows are read under may be named here rather than left to the
 * body. A recursive statement needs that, since the name a term of its own
 * refers to has to exist before the body is read.
 *
 * Whether the WITH clause says RECURSIVE is decided for the clause as a whole,
 * not here: SQL writes the keyword once, after WITH, and it covers every name
 * the clause introduces. This records what the caller asked for so that a
 * Grammar can write the keyword when any one of them asked.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class CommonTableExpression
{
    /**
     * Columns the rows are read under, empty when the body names them.
     *
     * @var list<string>
     */
    public array $columns;

    /**
     * Name a statement for the one that follows to read.
     *
     * @param  string                   $name      Name to read the rows under; a single name, not qualified
     * @param  SubQuery                 $query     Statement whose rows the name stands for
     * @param  array<int|string, mixed> $columns   Columns to read the rows under, in order; empty leaves the naming to the body
     * @param  bool                     $recursive Whether the body refers to the name being defined
     * @throws InvalidArgumentException When the name is qualified or empty, or a column is not a single name
     */
    public function __construct(
        public string $name,
        public SubQuery $query,
        array $columns = [],
        public bool $recursive = false,
    ) {
        if (\count(IdentifierQuoter::split($name)) !== 1) {
            throw new InvalidArgumentException(
                'A name given to a statement in a WITH clause stands on its own and is not qualified, got '
                . $name . '.',
            );
        }

        $this->columns = self::toColumns($columns);
    }

    /**
     * Reindex the column names as a list and reject what cannot be one.
     *
     * @param  array<int|string, mixed> $columns Column names as the caller gave them
     * @return list<string>             Column names as a list
     * @throws InvalidArgumentException When a name is not a string, or is qualified
     */
    private static function toColumns(array $columns): array
    {
        $list = [];

        foreach (array_values($columns) as $index => $column) {
            if (!\is_string($column)) {
                throw new InvalidArgumentException(
                    'A column of a statement in a WITH clause is named with a string, got '
                    . get_debug_type($column) . ' at index ' . $index . '.',
                );
            }

            if (\count(IdentifierQuoter::split($column)) !== 1) {
                throw new InvalidArgumentException(
                    'A column of a statement in a WITH clause stands on its own and is not qualified, got '
                    . $column . ' at index ' . $index . '.',
                );
            }

            $list[] = $column;
        }

        return $list;
    }
}
