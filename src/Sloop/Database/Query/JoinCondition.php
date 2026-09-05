<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * One comparison between two columns, and how it joins to the one before it.
 *
 * What separates this from a Condition is the right-hand side: there it is a
 * value and reaches the server through a placeholder, here it is another column
 * and is written into the SQL as an identifier. That is what an ON clause is
 * for — a join pairs rows by comparing their columns — and it is also why a
 * value cannot stand here by accident: a caller who means one writes it as an
 * Expression carrying its own binding.
 *
 * The operator is held in the spelling it is written with, already checked
 * against the set the grammar writes, for the reason Condition describes.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class JoinCondition extends WherePart
{
    /**
     * Describe one comparison between two columns.
     *
     * @param string|Expression $column      Column on the left, or an expression standing in for one
     * @param string            $operator    Comparison operator, in the spelling used to build SQL
     * @param string|Expression $reference   Column on the right, or an expression standing in for one
     * @param Conjunction       $conjunction How this joins to the preceding condition
     */
    public function __construct(
        public string|Expression $column,
        public string $operator,
        public string|Expression $reference,
        Conjunction $conjunction = Conjunction::And,
    ) {
        parent::__construct($conjunction);
    }
}
