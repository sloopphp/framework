<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * One parenthesis of a group of conditions.
 *
 * Grouping is recorded in the same list as the conditions themselves rather than
 * by nesting, which keeps the order the caller wrote and lets a group be opened
 * and closed by separate calls in a chain.
 *
 * Only the opening parenthesis carries a conjunction, since that is where the
 * group joins to what came before it; the closing one is given AND and a grammar
 * never reads it.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class GroupBoundary extends WherePart
{
    /**
     * Describe one end of a group.
     *
     * @param GroupEdge   $edge        Which end of the group this stands at
     * @param Conjunction $conjunction How the group joins to the preceding part
     */
    public function __construct(
        public GroupEdge $edge,
        Conjunction $conjunction = Conjunction::And,
    ) {
        parent::__construct($conjunction);
    }
}
