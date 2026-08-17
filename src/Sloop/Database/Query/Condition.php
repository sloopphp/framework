<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * One comparison in a WHERE clause, and how it joins to the one before it.
 *
 * The operator is held here in the spelling it is written with, already checked
 * against the set the grammar writes. Which operators those are is the
 * grammar's to say, so a comparison is built through Grammar::comparison()
 * rather than here: an operator that reached this class unchecked would be
 * written into the SQL as given and could carry a fragment with it.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class Condition extends WherePart
{
    /**
     * Describe one comparison.
     *
     * @param string|Expression                     $column      Column to compare, or an expression standing in for one
     * @param string                                $operator    Comparison operator, in the spelling used to build SQL
     * @param string|int|float|bool|Expression|null $value       Value to compare against; bound unless the operator reads it as a keyword
     * @param Conjunction                           $conjunction How this joins to the preceding condition
     */
    public function __construct(
        public string|Expression $column,
        public string $operator,
        public string|int|float|bool|Expression|null $value,
        Conjunction $conjunction = Conjunction::And,
    ) {
        parent::__construct($conjunction);
    }
}
