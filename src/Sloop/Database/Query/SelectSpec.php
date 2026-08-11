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
     * Conditions of the WHERE clause, in the order they were added.
     *
     * @var list<Condition>
     */
    public array $conditions;

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
     * @param  array<int|string, mixed> $conditions Condition instances for the WHERE clause
     * @param  array<int|string, mixed> $orders     Order instances for the ORDER BY clause
     * @param  int|null                 $limit      Maximum number of rows, or null for no limit
     * @param  int|null                 $offset     Rows to skip; needs a limit
     * @throws InvalidArgumentException When a clause holds the wrong type, a bound is negative, or an offset has no limit
     */
    public function __construct(
        public string $from,
        array $columns = [],
        array $conditions = [],
        array $orders = [],
        public ?int $limit = null,
        public ?int $offset = null,
    ) {
        $this->columns    = self::toColumns($columns);
        $this->conditions = self::toConditions($conditions);
        $this->orders     = self::toOrders($orders);

        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('Limit must not be negative, got ' . $limit . '.');
        }

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
        $selectable = [];

        foreach (array_values($columns) as $index => $column) {
            if (!\is_string($column) && !$column instanceof Expression) {
                throw new InvalidArgumentException(
                    'Columns must be a string or an Expression, got ' . get_debug_type($column) . ' at index ' . $index . '.',
                );
            }

            $selectable[] = $column;
        }

        return $selectable;
    }

    /**
     * Reindex the conditions as a list and reject anything that is not a Condition.
     *
     * @param  array<int|string, mixed> $conditions Condition instances
     * @return list<Condition>          Conditions as a list
     * @throws InvalidArgumentException When an element is not a Condition
     */
    private static function toConditions(array $conditions): array
    {
        $comparisons = [];

        foreach (array_values($conditions) as $index => $condition) {
            if (!$condition instanceof Condition) {
                throw new InvalidArgumentException(
                    'Conditions must be a Condition, got ' . get_debug_type($condition) . ' at index ' . $index . '.',
                );
            }

            $comparisons[] = $condition;
        }

        return $comparisons;
    }

    /**
     * Reindex the orders as a list and reject anything that is not an Order.
     *
     * @param  array<int|string, mixed> $orders Order instances
     * @return list<Order>              Orders as a list
     * @throws InvalidArgumentException When an element is not an Order
     */
    private static function toOrders(array $orders): array
    {
        $sorts = [];

        foreach (array_values($orders) as $index => $order) {
            if (!$order instanceof Order) {
                throw new InvalidArgumentException(
                    'Orders must be an Order, got ' . get_debug_type($order) . ' at index ' . $index . '.',
                );
            }

            $sorts[] = $order;
        }

        return $sorts;
    }
}
