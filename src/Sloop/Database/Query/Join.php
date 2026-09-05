<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * One join and the conditions that pair its rows with the ones already there.
 *
 * The conditions are held in the same shape as a WHERE clause — comparisons and
 * the parentheses grouping them, each recording how it joins to the one before
 * it — so that a grammar writes an ON clause the way it writes a WHERE, and the
 * two cannot drift apart in how they read `a OR b AND c`.
 *
 * A join is immutable: a builder that adds a condition puts a new one in the
 * place of the old. That keeps a statement spec independent of the builder it
 * came from, so a builder used again after compiling cannot change a statement
 * already handed over.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class Join
{
    /**
     * Parts of the ON clause, in the order they were added.
     *
     * @var list<WherePart>
     */
    public array $conditions;

    /**
     * Describe one join.
     *
     * @param  JoinType                 $type       Which rows to keep when the conditions find no match
     * @param  string                   $table      Table to join, optionally schema qualified
     * @param  array<int|string, mixed> $conditions WherePart instances for the ON clause
     * @throws InvalidArgumentException When a condition is not a WherePart
     */
    public function __construct(
        public JoinType $type,
        public string $table,
        array $conditions = [],
    ) {
        $this->conditions = ClauseParts::toConditions($conditions);
    }

    /**
     * Return this join with one more part on its ON clause.
     *
     * @param  WherePart $part Part to add
     * @return self      A join carrying the parts of this one and the new part
     */
    public function with(WherePart $part): self
    {
        return new self($this->type, $this->table, [...$this->conditions, $part]);
    }

    /**
     * Whether the ON clause holds anything the server would read as a condition.
     *
     * Parentheses on their own do not count: a group that was opened and closed
     * without a comparison inside it compiles to nothing, so a join carrying
     * only those pairs every row with every other just as one with no ON clause
     * at all does.
     *
     * @return bool Whether at least one condition would reach the server
     */
    public function hasCondition(): bool
    {
        foreach ($this->conditions as $part) {
            if (!$part instanceof GroupBoundary) {
                return true;
            }
        }

        return false;
    }
}
