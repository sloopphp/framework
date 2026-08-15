<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * A test that a column falls within an inclusive range.
 *
 * Neither bound may be null, for the same reason a comparison against null is
 * refused: a NULL bound makes the test unknown for every row, so the statement
 * matches nothing whatever the data holds. The bounds are held to that by the
 * signature rather than by a check.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class BetweenCondition extends WherePart
{
    /**
     * Describe one range test.
     *
     * @param string|Expression                $column      Column to test, or an expression standing in for one
     * @param string|int|float|bool|Expression $min         Lower bound, included in the range
     * @param string|int|float|bool|Expression $max         Upper bound, included in the range
     * @param Conjunction                      $conjunction How this joins to the preceding part
     */
    public function __construct(
        public string|Expression $column,
        public string|int|float|bool|Expression $min,
        public string|int|float|bool|Expression $max,
        Conjunction $conjunction = Conjunction::And,
    ) {
        parent::__construct($conjunction);
    }
}
