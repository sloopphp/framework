<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * A test for whether a statement returns any row at all.
 *
 * Nothing is compared: the test is answered by whether a row came back, so the
 * columns the inner statement selects carry no meaning here. That is what
 * separates it from a membership test, which reads what those columns hold and
 * is therefore held to a single one.
 *
 * The inner statement usually names a column of the row being tested, which
 * makes the server answer it once per row rather than once for the whole
 * statement.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class ExistsCondition extends WherePart
{
    /**
     * Describe one test for the presence of a row.
     *
     * @param SubQuery    $query       Statement whose rows the test asks about
     * @param bool        $negated     Whether the test is NOT EXISTS rather than EXISTS
     * @param Conjunction $conjunction How this joins to the preceding part
     */
    public function __construct(
        public SubQuery $query,
        public bool $negated = false,
        Conjunction $conjunction = Conjunction::And,
    ) {
        parent::__construct($conjunction);
    }
}
