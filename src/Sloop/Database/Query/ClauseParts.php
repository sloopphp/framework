<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * Checks the clause arrays every statement spec is built from.
 *
 * A spec is handed plain arrays and has to answer with lists of the right
 * element type, and the WHERE and ORDER BY clauses read the same whichever
 * statement carries them. The checking lives here so that both specs give the
 * same answer and the same message for the same wrong value.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final class ClauseParts
{
    /**
     * Reindex the conditions as a list and reject anything that is not a WherePart.
     *
     * @param  array<int|string, mixed> $conditions WherePart instances
     * @return list<WherePart>          Parts of the WHERE clause as a list
     * @throws InvalidArgumentException When an element is not a WherePart
     */
    public static function toConditions(array $conditions): array
    {
        $comparisons = [];

        foreach (array_values($conditions) as $index => $condition) {
            if (!$condition instanceof WherePart) {
                throw new InvalidArgumentException(
                    'Conditions must be a WherePart, got ' . get_debug_type($condition) . ' at index ' . $index . '.',
                );
            }

            $comparisons[] = $condition;
        }

        return $comparisons;
    }

    /**
     * Reindex the assignments as a list and reject anything that is not an Assignment.
     *
     * The keys a builder collects assignments under name the columns, which is
     * how a second mention of one replaces the first. Only their order matters
     * by the time they arrive here, so they are dropped.
     *
     * @param  array<int|string, mixed> $assignments Assignment instances
     * @return list<Assignment>         Parts of the SET clause as a list
     * @throws InvalidArgumentException When an element is not an Assignment
     */
    public static function toAssignments(array $assignments): array
    {
        $writes = [];

        foreach (array_values($assignments) as $index => $assignment) {
            if (!$assignment instanceof Assignment) {
                throw new InvalidArgumentException(
                    'Assignments must be an Assignment, got ' . get_debug_type($assignment) . ' at index ' . $index . '.',
                );
            }

            $writes[] = $assignment;
        }

        return $writes;
    }

    /**
     * Reindex the orders as a list and reject anything that is not an Order.
     *
     * @param  array<int|string, mixed> $orders Order instances
     * @return list<Order>              Orders as a list
     * @throws InvalidArgumentException When an element is not an Order
     */
    public static function toOrders(array $orders): array
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

    /**
     * Reindex the column names as a list and reject anything that is not a string.
     *
     * @param  array<int|string, mixed> $columns Column names
     * @return list<string>             Column names as a list
     * @throws InvalidArgumentException When an element is not a string
     */
    public static function toColumnNames(array $columns): array
    {
        $names = [];

        foreach (array_values($columns) as $index => $column) {
            if (!\is_string($column)) {
                throw new InvalidArgumentException(
                    'Columns must be a string, got ' . get_debug_type($column) . ' at index ' . $index . '.',
                );
            }

            $names[] = $column;
        }

        return $names;
    }

    /**
     * Reindex the rows as lists of values and reject anything that cannot be written.
     *
     * Each row has to carry exactly one value per column, since the columns are
     * named once for the whole statement. A row of another length would either
     * leave a column unwritten or name a value with no column to put it in, and
     * the server would answer with a count that says nothing about which row
     * was wrong.
     *
     * @param  array<int|string, mixed>                          $rows        Rows, each a list of values
     * @param  int                                               $columnCount Values each row must carry
     * @return list<list<string|int|float|bool|Expression|null>> Rows as lists
     * @throws InvalidArgumentException                          When a row is not an array, holds a value that cannot be written, or is of another length
     */
    public static function toValueRows(array $rows, int $columnCount): array
    {
        $tuples = [];

        foreach (array_values($rows) as $index => $row) {
            if (!\is_array($row)) {
                throw new InvalidArgumentException(
                    'Rows must be an array of values, got ' . get_debug_type($row) . ' at index ' . $index . '.',
                );
            }

            $values = [];

            foreach ($row as $value) {
                if ($value !== null && !\is_scalar($value) && !$value instanceof Expression) {
                    throw new InvalidArgumentException(
                        'A value must be a scalar, null or an Expression, got '
                        . get_debug_type($value) . ' in the row at index ' . $index . '.',
                    );
                }

                $values[] = $value;
            }

            if (\count($values) !== $columnCount) {
                throw new InvalidArgumentException(
                    'Every row carries one value per column, so the row at index ' . $index . ' must hold '
                    . $columnCount . ', got ' . \count($values) . '.',
                );
            }

            $tuples[] = $values;
        }

        return $tuples;
    }

    /**
     * Refuse a row limit that is negative.
     *
     * @param  int|null                 $limit Maximum number of rows, or null for no limit
     * @return void
     * @throws InvalidArgumentException When the limit is negative
     */
    public static function requireLimitNotNegative(?int $limit): void
    {
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('Limit must not be negative, got ' . $limit . '.');
        }
    }
}
