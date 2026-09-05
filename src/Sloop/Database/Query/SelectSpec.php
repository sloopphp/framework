<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * The parts of a SELECT statement, in the shape a Grammar reads them.
 *
 * A query builder collects what the caller asked for and hands it over as one
 * of these, so that a Grammar never has to know which builder produced it and
 * can be swapped for another dialect.
 *
 * The element types of the clause arrays are checked here rather than left to
 * the Grammar, so a wrong value names itself where it was added instead of
 * surfacing as a type error in the middle of compiling.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class SelectSpec
{
    /**
     * Columns to select; an empty list selects everything.
     *
     * @var list<string|Expression>
     */
    public array $columns;

    /**
     * Joins, in the order they were added.
     *
     * @var list<Join>
     */
    public array $joins;

    /**
     * Parts of the WHERE clause, in the order they were added.
     *
     * @var list<WherePart>
     */
    public array $conditions;

    /**
     * Terms of the GROUP BY clause, in the order they were added.
     *
     * @var list<string|Expression>
     */
    public array $groupings;

    /**
     * Parts of the HAVING clause, in the order they were added.
     *
     * @var list<WherePart>
     */
    public array $having;

    /**
     * Terms of the ORDER BY clause, in the order they were added.
     *
     * @var list<Order>
     */
    public array $orders;

    /**
     * Describe one SELECT statement.
     *
     * @param  string                   $from       Table to select from, optionally schema qualified
     * @param  array<int|string, mixed> $columns    Column names or Expressions; empty selects everything
     * @param  array<int|string, mixed> $joins      Join instances, in the order they are written
     * @param  array<int|string, mixed> $conditions WherePart instances for the WHERE clause
     * @param  array<int|string, mixed> $groupings  Column names or Expressions for the GROUP BY clause
     * @param  array<int|string, mixed> $having     WherePart instances for the HAVING clause
     * @param  array<int|string, mixed> $orders     Order instances for the ORDER BY clause
     * @param  int|null                 $limit      Maximum number of rows, or null for no limit
     * @param  int|null                 $offset     Rows to skip; needs a limit
     * @param  RowLock|null             $lock       How to hold the rows read, or null to hold nothing
     * @throws InvalidArgumentException When a clause holds the wrong type, a bound is negative, or an offset has no limit
     */
    public function __construct(
        public string $from,
        array $columns = [],
        array $joins = [],
        array $conditions = [],
        array $groupings = [],
        array $having = [],
        array $orders = [],
        public ?int $limit = null,
        public ?int $offset = null,
        public ?RowLock $lock = null,
    ) {
        $this->columns    = self::toColumns($columns);
        $this->joins      = ClauseParts::toJoins($joins);
        $this->conditions = ClauseParts::toConditions($conditions);
        $this->groupings  = ClauseParts::toColumnReferences('GROUP BY terms', $groupings);
        $this->having     = ClauseParts::toConditions($having);
        $this->orders     = ClauseParts::toOrders($orders);

        ClauseParts::requireLimitNotNegative($limit);

        if ($offset !== null && $offset < 0) {
            throw new InvalidArgumentException('Offset must not be negative, got ' . $offset . '.');
        }

        if ($offset !== null && $limit === null) {
            throw new InvalidArgumentException('An offset needs a limit, because MySQL has no OFFSET without LIMIT.');
        }
    }

    /**
     * Reindex the columns as a list and reject anything that is not selectable.
     *
     * @param  array<int|string, mixed> $columns Column names or Expressions
     * @return list<string|Expression>  Columns as a list
     * @throws InvalidArgumentException When an element is neither a string nor an Expression
     */
    private static function toColumns(array $columns): array
    {
        return ClauseParts::toColumnReferences('Columns', $columns);
    }
}
