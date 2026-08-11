<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * The clauses that narrow a statement down to a set of rows.
 *
 * WHERE, ORDER BY and the row limit read the same whether the rows are being
 * selected, updated or deleted, so they are collected here and every statement
 * that addresses existing rows inherits them.
 *
 * The conditions are kept in the order they were added, and each one records
 * how it joins to the one before it. Nothing is grouped: `a OR b AND c` reaches
 * the server as written and MySQL binds AND tighter than OR.
 */
abstract class BuilderWhere extends Builder
{
    /**
     * Conditions of the WHERE clause, in the order they were added.
     *
     * @var list<Condition>
     */
    protected array $conditions = [];

    /**
     * Terms of the ORDER BY clause, in the order they were added.
     *
     * @var list<Order>
     */
    protected array $orders = [];

    /**
     * Maximum number of rows to address, or null for no limit.
     *
     * @var int|null
     */
    protected ?int $limit = null;

    /**
     * Rows to skip before the limit applies, or null for none.
     *
     * @var int|null
     */
    protected ?int $offset = null;

    /**
     * Add a condition, joined to the preceding one with AND.
     *
     * Called with two arguments the second one is the value and the comparison
     * is `=`; called with three the second one is the operator.
     *
     * @param  string|Expression                     $column   Column to compare, or an expression standing in for one
     * @param  string|int|float|bool|Expression|null $operator Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null $value    Value to compare against, when an operator was given
     * @return static                                This builder
     * @throws InvalidArgumentException              When the operator is not a supported comparison, or a value is null
     */
    public function where(
        string|Expression $column,
        string|int|float|bool|Expression|null $operator,
        string|int|float|bool|Expression|null $value = null,
    ): static {
        $this->conditions[] = self::toCondition(Conjunction::And, $column, $operator, $value, \func_num_args());

        return $this;
    }

    /**
     * Add a condition, joined to the preceding one with AND.
     *
     * Same as where(); spelled out for a chain that reads better with the
     * conjunction named at every step.
     *
     * @param  string|Expression                     $column   Column to compare, or an expression standing in for one
     * @param  string|int|float|bool|Expression|null $operator Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null $value    Value to compare against, when an operator was given
     * @return static                                This builder
     * @throws InvalidArgumentException              When the operator is not a supported comparison, or a value is null
     */
    public function andWhere(
        string|Expression $column,
        string|int|float|bool|Expression|null $operator,
        string|int|float|bool|Expression|null $value = null,
    ): static {
        $this->conditions[] = self::toCondition(Conjunction::And, $column, $operator, $value, \func_num_args());

        return $this;
    }

    /**
     * Add a condition, joined to the preceding one with OR.
     *
     * @param  string|Expression                     $column   Column to compare, or an expression standing in for one
     * @param  string|int|float|bool|Expression|null $operator Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null $value    Value to compare against, when an operator was given
     * @return static                                This builder
     * @throws InvalidArgumentException              When the operator is not a supported comparison, or a value is null
     */
    public function orWhere(
        string|Expression $column,
        string|int|float|bool|Expression|null $operator,
        string|int|float|bool|Expression|null $value = null,
    ): static {
        $this->conditions[] = self::toCondition(Conjunction::Or, $column, $operator, $value, \func_num_args());

        return $this;
    }

    /**
     * Sort by a column, or by an expression producing the sort key.
     *
     * The direction is taken as the SQL keyword rather than as the Direction
     * enum: the enum names the values a Grammar writes and is internal to that
     * seam, so it is not part of what a caller has to reach for.
     *
     * @param  string|Expression        $column    Column to sort by, or an expression producing the sort key
     * @param  string                   $direction Sort direction as the SQL keyword, in any case
     * @return static                   This builder
     * @throws InvalidArgumentException When the direction is neither ASC nor DESC
     */
    public function orderBy(string|Expression $column, string $direction = Direction::Ascending->value): static
    {
        $this->orders[] = new Order($column, self::toDirection($direction));

        return $this;
    }

    /**
     * Address at most this many rows.
     *
     * @param  int                      $limit Maximum number of rows
     * @return static                   This builder
     * @throws InvalidArgumentException When the limit is negative
     */
    public function limit(int $limit): static
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('Limit must not be negative, got ' . $limit . '.');
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * Skip this many rows before the limit applies.
     *
     * MySQL has no OFFSET without a LIMIT, so a statement carrying an offset
     * alone is rejected when it is compiled.
     *
     * @param  int                      $offset Rows to skip
     * @return static                   This builder
     * @throws InvalidArgumentException When the offset is negative
     */
    public function offset(int $offset): static
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must not be negative, got ' . $offset . '.');
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * Read one condition out of the two shapes the where methods accept.
     *
     * The number of arguments is what tells the shapes apart, rather than the
     * type of the second one: a caller comparing a column against the string
     * '=' means that string as a value, and guessing from the type would turn
     * it into an operator.
     *
     * @param  Conjunction                           $conjunction   How the condition joins to the preceding one
     * @param  string|Expression                     $column        Column to compare, or an expression standing in for one
     * @param  string|int|float|bool|Expression|null $operator      Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null $value         Value to compare against, when an operator was given
     * @param  int                                   $argumentCount Number of arguments the caller passed
     * @return Condition                             The condition to add
     * @throws InvalidArgumentException              When the operator is not a string or not a supported comparison, or a value is null
     */
    private static function toCondition(
        Conjunction $conjunction,
        string|Expression $column,
        string|int|float|bool|Expression|null $operator,
        string|int|float|bool|Expression|null $value,
        int $argumentCount,
    ): Condition {
        if ($argumentCount < 3) {
            return new Condition($column, '=', self::requireValue($operator), $conjunction);
        }

        if (!\is_string($operator)) {
            throw new InvalidArgumentException(
                'A comparison operator must be a string, got ' . get_debug_type($operator) . '.',
            );
        }

        return new Condition($column, $operator, self::requireValue($value), $conjunction);
    }

    /**
     * Reject null where a value to compare against is expected.
     *
     * A comparison against NULL is never true, so a statement built with one
     * would quietly match nothing. Refusing it here turns that into an error at
     * the line that wrote it.
     *
     * @param  string|int|float|bool|Expression|null $value Value the caller gave
     * @return string|int|float|bool|Expression      The same value, known not to be null
     * @throws InvalidArgumentException              When the value is null
     */
    private static function requireValue(
        string|int|float|bool|Expression|null $value,
    ): string|int|float|bool|Expression {
        if ($value === null) {
            throw new InvalidArgumentException(
                'A comparison against null is never true, so it is rejected rather than matching no rows.',
            );
        }

        return $value;
    }

    /**
     * Read a sort direction from the SQL keyword.
     *
     * @param  string                   $direction Direction as ASC / DESC in any case
     * @return Direction                The direction as the enum a Grammar reads
     * @throws InvalidArgumentException When the keyword names no direction
     */
    private static function toDirection(string $direction): Direction
    {
        return Direction::tryFrom(strtoupper($direction))
            ?? throw new InvalidArgumentException(
                'A sort direction is ASC or DESC, got "' . $direction . '".',
            );
    }
}
