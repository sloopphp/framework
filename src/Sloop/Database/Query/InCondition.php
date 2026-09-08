<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * A test for membership in a set: either values written out, or a subquery.
 *
 * Null is refused among the values written out. In SQL a NULL inside the set
 * makes the comparison unknown for every row that matches nothing else, which
 * turns `NOT IN` into a test no row can pass however the data looks. Refusing
 * it here names the problem at the line that built the set, rather than leaving
 * a query that quietly returns nothing.
 *
 * A subquery cannot carry that guard: what it matches is not known until it
 * runs, so a `NOT IN` over a subquery that returns a null behaves the way an
 * unguarded set would. The database guide says so where it describes this.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class InCondition extends WherePart
{
    /**
     * The set the column is tested against.
     *
     * @var list<string|int|float|bool|Expression>|SubQuery
     */
    public array|SubQuery $values;

    /**
     * Describe one membership test.
     *
     * @param  string|Expression                 $column      Column to test, or an expression standing in for one
     * @param  array<int|string, mixed>|SubQuery $values      Values making up the set, or a statement whose rows make it up; a written-out set must not be empty
     * @param  bool                              $negated     Whether the test is NOT IN rather than IN
     * @param  Conjunction                       $conjunction How this joins to the preceding part
     * @throws InvalidArgumentException          When the written-out set is empty or holds a value that cannot be compared
     */
    public function __construct(
        public string|Expression $column,
        array|SubQuery $values,
        public bool $negated = false,
        Conjunction $conjunction = Conjunction::And,
    ) {
        parent::__construct($conjunction);

        if ($values instanceof SubQuery) {
            $this->values = $values;

            return;
        }

        if ($values === []) {
            throw new InvalidArgumentException(
                'An IN test needs at least one value; an empty set matches nothing and says so only at the server.',
            );
        }

        $comparable = [];

        foreach (array_values($values) as $index => $value) {
            if ($value === null) {
                throw new InvalidArgumentException(
                    'Null cannot stand among the values of an IN test, because it makes NOT IN match no rows at all;'
                    . ' test for NULL separately with IS or IS NOT. Found at index ' . $index . '.',
                );
            }

            if (!\is_scalar($value) && !$value instanceof Expression) {
                throw new InvalidArgumentException(
                    'Values of an IN test must be scalar or an Expression, got '
                    . get_debug_type($value) . ' at index ' . $index . '.',
                );
            }

            $comparable[] = $value;
        }

        $this->values = $comparable;
    }
}
