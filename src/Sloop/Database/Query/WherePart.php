<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * One entry in the list a WHERE clause is built from.
 *
 * A WHERE clause is more than a sequence of comparisons: it also holds the
 * parentheses that group them and the fragments a caller wrote as SQL. Each of
 * those is described by its own class, and this is what they have in common —
 * every one of them joins to whatever came before it.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
abstract readonly class WherePart
{
    /**
     * Record how this part joins to the one before it.
     *
     * @param Conjunction $conjunction How this joins to the preceding part
     */
    public function __construct(
        public Conjunction $conjunction = Conjunction::And,
    ) {
    }
}
