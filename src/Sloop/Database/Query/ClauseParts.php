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
