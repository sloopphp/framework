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
final readonly class Condition extends WherePart
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
        'IS',
        'IS NOT',
    ];

    /**
     * Operators that may be given null, because NULL is a legal right-hand side.
     *
     * Every other comparison against NULL evaluates to unknown rather than true,
     * so a statement built with one matches nothing however the data looks.
     *
     * @var list<string>
     */
    private const array NULL_OPERATORS = [
        'IS',
        'IS NOT',
        '<=>',
    ];

    /**
     * Operators whose right-hand side is a keyword rather than a value.
     *
     * MySQL reads what follows these as one of NULL, TRUE, FALSE or UNKNOWN. Of
     * those only NULL is written here, so anything else is refused rather than
     * compiled into SQL the server will reject.
     *
     * @var list<string>
     */
    private const array KEYWORD_OPERATORS = [
        'IS',
        'IS NOT',
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
     * @param  string|Expression                     $column      Column to compare, or an expression standing in for one
     * @param  string                                $operator    Comparison operator; matched case-insensitively
     * @param  string|int|float|bool|Expression|null $value       Value to compare against; bound unless it is an Expression. Null only where the operator reads it
     * @param  Conjunction                           $conjunction How this joins to the preceding condition
     * @throws InvalidArgumentException              When the operator is not one of the supported comparisons, or the operator and null do not go together
     */
    public function __construct(
        public string|Expression $column,
        string $operator,
        public string|int|float|bool|Expression|null $value,
        Conjunction $conjunction = Conjunction::And,
    ) {
        $canonical = strtoupper($operator);
        if (!\in_array($canonical, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException('Unsupported comparison operator "' . $operator . '".');
        }

        if ($value === null && !\in_array($canonical, self::NULL_OPERATORS, true)) {
            throw new InvalidArgumentException(
                'A comparison against null is never true, so it is rejected rather than matching no rows.'
                . ' Write IS or IS NOT to test for NULL.',
            );
        }

        if ($value !== null && \in_array($canonical, self::KEYWORD_OPERATORS, true)) {
            throw new InvalidArgumentException(
                $canonical . ' tests for NULL, so null is the only right-hand side it takes; got '
                . get_debug_type($value) . '. Use = to compare against a value.',
            );
        }

        $this->operator = $canonical;

        parent::__construct($conjunction);
    }
}
