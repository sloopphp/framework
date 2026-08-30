<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * The parts of a DELETE statement, in the shape a Grammar reads them.
 *
 * The counterpart of SelectSpec for the statement that removes rows. It
 * carries no offset: MySQL takes ORDER BY and LIMIT on a DELETE to say which
 * rows go first and how many of them, but has no OFFSET there, so a builder
 * that collected one has to report it rather than pass it on.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class DeleteSpec
{
    /**
     * Parts of the WHERE clause, in the order they were added.
     *
     * @var list<WherePart>
     */
    public array $conditions;

    /**
     * Terms of the ORDER BY clause, in the order they were added.
     *
     * @var list<Order>
     */
    public array $orders;

    /**
     * Describe one DELETE statement.
     *
     * @param  string                   $from       Table to delete from, optionally schema qualified
     * @param  array<int|string, mixed> $conditions WherePart instances for the WHERE clause
     * @param  array<int|string, mixed> $orders     Order instances for the ORDER BY clause
     * @param  int|null                 $limit      Most rows to remove, or null for every match
     * @throws InvalidArgumentException When a clause holds the wrong type or the limit is negative
     */
    public function __construct(
        public string $from,
        array $conditions = [],
        array $orders = [],
        public ?int $limit = null,
    ) {
        $this->conditions = ClauseParts::toConditions($conditions);
        $this->orders     = ClauseParts::toOrders($orders);

        ClauseParts::requireLimitNotNegative($limit);
    }
}
