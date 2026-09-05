<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * The parts of an UPDATE statement, in the shape a Grammar reads them.
 *
 * The counterpart of DeleteSpec for the statement that changes rows, and it
 * carries the same clauses with the assignments added. There is no offset
 * here either: MySQL takes ORDER BY and LIMIT on an UPDATE to say which rows
 * change and how many of them, but has no OFFSET there.
 *
 * Whether there is anything to assign is not checked here. A spec describes
 * the statement it was handed, and a builder that collected no assignment has
 * a caller to answer to rather than a malformed clause.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class UpdateSpec
{
    /**
     * Tables joined to the one being updated, in the order they were added.
     *
     * @var list<Join>
     */
    public array $joins;

    /**
     * Columns being written and the values going into them, in the order they were first named.
     *
     * @var list<Assignment>
     */
    public array $assignments;

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
     * Describe one UPDATE statement.
     *
     * @param  string                   $table       Table to update, optionally schema qualified
     * @param  array<int|string, mixed> $joins       Join instances naming the tables joined to it
     * @param  array<int|string, mixed> $assignments Assignment instances for the SET clause
     * @param  array<int|string, mixed> $conditions  WherePart instances for the WHERE clause
     * @param  array<int|string, mixed> $orders      Order instances for the ORDER BY clause
     * @param  int|null                 $limit       Most rows to change, or null for every match
     * @throws InvalidArgumentException When a clause holds the wrong type or the limit is negative
     */
    public function __construct(
        public string $table,
        array $joins = [],
        array $assignments = [],
        array $conditions = [],
        array $orders = [],
        public ?int $limit = null,
    ) {
        $this->joins       = ClauseParts::toJoins($joins);
        $this->assignments = ClauseParts::toAssignments($assignments);
        $this->conditions  = ClauseParts::toConditions($conditions);
        $this->orders      = ClauseParts::toOrders($orders);

        ClauseParts::requireLimitNotNegative($limit);
    }
}
