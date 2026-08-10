<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * One comparison in a WHERE clause, and how it joins to the one before it.
 *
 * The operator is checked against a fixed set rather than written into the SQL
 * as given, so that an operator built from outside the application cannot carry
 * a fragment of SQL with it.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class Condition
{
    /**
     * Comparison operators a condition may use, in their canonical spelling.
     *
     * @var list<string>
     */
    private const array ALLOWED_OPERATORS = [
        '=',
        '<=>',
        '!=',
        '<>',
        '<',
        '<=',
        '>',
        '>=',
        'LIKE',
        'NOT LIKE',
    ];

    /**
     * The operator in the spelling used to build SQL.
     *
     * @var string
     */
    public string $operator;

    /**
     * Describe one comparison.
     *
     * @param  string|Expression                $column      Column to compare, or an expression standing in for one
     * @param  string                           $operator    Comparison operator; matched case-insensitively
     * @param  string|int|float|bool|Expression $value       Value to compare against; bound unless it is an Expression
     * @param  Conjunction                      $conjunction How this joins to the preceding condition
     * @throws InvalidArgumentException         When the operator is not one of the supported comparisons
     */
    public function __construct(
        public string|Expression $column,
        string $operator,
        public string|int|float|bool|Expression $value,
        public Conjunction $conjunction = Conjunction::And,
    ) {
        $canonical = strtoupper($operator);
        if (!\in_array($canonical, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException('Unsupported comparison operator "' . $operator . '".');
        }

        $this->operator = $canonical;
    }
}
