<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * One term of an ORDER BY clause.
 *
 * The column may be an Expression, which is how a hand-written sequence such as
 * `Expression::field()` becomes a sort order.
 *
 * A term written wholly as SQL carries no direction: the text already says how
 * it sorts, and appending a keyword to it would change what the caller wrote.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class Order
{
    /**
     * Describe one sort term.
     *
     * @param string|Expression $column    Column to sort by, or an expression producing the sort key
     * @param Direction|null    $direction Sort direction, or null to write the term as it stands
     */
    public function __construct(
        public string|Expression $column,
        public ?Direction $direction = Direction::Ascending,
    ) {
    }
}
