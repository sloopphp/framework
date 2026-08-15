<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * A condition the caller wrote as SQL.
 *
 * Everywhere else in a WHERE clause the parts are described rather than written,
 * so that nothing reaching the builder from outside the application can carry a
 * fragment of SQL with it. This is the one place that boundary is opened, and it
 * is opened deliberately: what is held here goes into the statement as given.
 * Values belong in the bindings rather than in the text.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class RawCondition extends WherePart
{
    /**
     * Describe one condition written as SQL.
     *
     * @param Expression  $expression  SQL of the condition together with its bindings
     * @param Conjunction $conjunction How this joins to the preceding part
     */
    public function __construct(
        public Expression $expression,
        Conjunction $conjunction = Conjunction::And,
    ) {
        parent::__construct($conjunction);
    }
}
