<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * A SELECT statement combined with others, in the shape a Grammar reads it.
 *
 * The first statement is described in full, including the sort and row window
 * written inside its own parentheses. The sort and row window held here apply
 * to the combined rows.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class UnionSpec
{
    /**
     * Statements the WITH clause names, in the order they are written.
     *
     * Held here rather than on the first statement because the clause leads the
     * whole combination: MariaDB refuses a WITH inside the parentheses of a
     * combined statement, and a name introduced there would in any case be out
     * of reach of the statements that follow.
     *
     * @var list<CommonTableExpression>
     */
    public array $commonTables;

    /**
     * Statements whose rows are added to those of the first, in order.
     *
     * @var non-empty-list<Union>
     */
    public array $unions;

    /**
     * Terms of the ORDER BY clause over the combined rows.
     *
     * @var list<Order>
     */
    public array $orders;

    /**
     * Describe one combined statement.
     *
     * @param  SelectSpec               $first        Statement whose rows come first
     * @param  array<int|string, mixed> $unions       Union instances, in the order they are written
     * @param  array<int|string, mixed> $orders       Order instances sorting the combined rows
     * @param  int|null                 $limit        Maximum number of combined rows, or null for no limit
     * @param  int|null                 $offset       Combined rows to skip; needs a limit
     * @param  array<int|string, mixed> $commonTables CommonTableExpression instances the WITH clause names, in the order they are written
     * @throws InvalidArgumentException When no statement is combined, a clause holds the wrong type, two statements of the WITH clause share a name, a bound is negative, or an offset has no limit
     */
    public function __construct(
        public SelectSpec $first,
        array $unions,
        array $orders = [],
        public ?int $limit = null,
        public ?int $offset = null,
        array $commonTables = [],
    ) {
        $this->commonTables = ClauseParts::toCommonTables($commonTables);
        $this->unions       = self::toUnions($unions);
        $this->orders       = ClauseParts::toOrders($orders);

        ClauseParts::requireLimitNotNegative($limit);

        if ($offset !== null && $offset < 0) {
            throw new InvalidArgumentException('Offset must not be negative, got ' . $offset . '.');
        }

        if ($offset !== null && $limit === null) {
            throw new InvalidArgumentException('An offset needs a limit, because MySQL has no OFFSET without LIMIT.');
        }
    }

    /**
     * Reindex the combined statements as a list and reject anything that is not one.
     *
     * @param  array<int|string, mixed> $unions Union instances
     * @return non-empty-list<Union>    Combined statements as a list
     * @throws InvalidArgumentException When there are none, or an element is not a Union
     */
    private static function toUnions(array $unions): array
    {
        $combined = [];

        foreach (array_values($unions) as $index => $union) {
            if (!$union instanceof Union) {
                throw new InvalidArgumentException(
                    'Unions must be a Union, got ' . get_debug_type($union) . ' at index ' . $index . '.',
                );
            }

            $combined[] = $union;
        }

        if ($combined === []) {
            throw new InvalidArgumentException('A combined statement needs at least one statement to add.');
        }

        return $combined;
    }
}
