<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use LogicException;

/**
 * The clauses that narrow a statement down to a set of rows.
 *
 * WHERE, ORDER BY and the row limit read the same whether the rows are being
 * selected, updated or deleted, so they are collected here and every statement
 * that addresses existing rows inherits them.
 *
 * The parts of the WHERE clause are kept in the order they were added, and each
 * one records how it joins to the one before it. Precedence is not guessed at:
 * `a OR b AND c` reaches the server as written and MySQL binds AND tighter than
 * OR. Where that is not what was meant, whereOpen() and whereClose() put the
 * parentheses in.
 */
abstract class BuilderWhere extends Builder
{
    /**
     * Parts of the WHERE clause, in the order they were added.
     *
     * @var list<WherePart>
     */
    protected array $conditions = [];

    /**
     * Groups opened by whereOpen() and not yet closed.
     *
     * @var int
     */
    private int $openGroups = 0;

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
     * @throws InvalidArgumentException              When the operator is not a supported comparison, or null stands where the operator cannot read it
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
     * @throws InvalidArgumentException              When the operator is not a supported comparison, or null stands where the operator cannot read it
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
     * @throws InvalidArgumentException              When the operator is not a supported comparison, or null stands where the operator cannot read it
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
     * Keep only rows where the column holds no value.
     *
     * The same statement as where(column, 'IS', null), for a chain that reads
     * better with the test named than with the operator spelled out.
     *
     * @param  string|Expression $column Column to test, or an expression standing in for one
     * @return static            This builder
     */
    public function whereNull(string|Expression $column): static
    {
        $this->conditions[] = new Condition($column, 'IS', null, Conjunction::And);

        return $this;
    }

    /**
     * Keep only rows where the column holds a value.
     *
     * @param  string|Expression $column Column to test, or an expression standing in for one
     * @return static            This builder
     */
    public function whereNotNull(string|Expression $column): static
    {
        $this->conditions[] = new Condition($column, 'IS NOT', null, Conjunction::And);

        return $this;
    }

    /**
     * Keep only rows whose column is one of the given values.
     *
     * @param  string|Expression        $column Column to test, or an expression standing in for one
     * @param  array<int|string, mixed> $values Values making up the set; must not be empty or hold null
     * @return static                   This builder
     * @throws InvalidArgumentException When the set is empty, holds null, or holds a value that cannot be compared
     */
    public function whereIn(string|Expression $column, array $values): static
    {
        $this->conditions[] = new InCondition($column, $values, negated: false, conjunction: Conjunction::And);

        return $this;
    }

    /**
     * Keep only rows whose column is none of the given values.
     *
     * @param  string|Expression        $column Column to test, or an expression standing in for one
     * @param  array<int|string, mixed> $values Values making up the set; must not be empty or hold null
     * @return static                   This builder
     * @throws InvalidArgumentException When the set is empty, holds null, or holds a value that cannot be compared
     */
    public function whereNotIn(string|Expression $column, array $values): static
    {
        $this->conditions[] = new InCondition($column, $values, negated: true, conjunction: Conjunction::And);

        return $this;
    }

    /**
     * Keep only rows whose column falls between two bounds, both included.
     *
     * @param  string|Expression                $column Column to test, or an expression standing in for one
     * @param  string|int|float|bool|Expression $min    Lower bound, included in the range
     * @param  string|int|float|bool|Expression $max    Upper bound, included in the range
     * @return static                           This builder
     */
    public function whereBetween(
        string|Expression $column,
        string|int|float|bool|Expression $min,
        string|int|float|bool|Expression $max,
    ): static {
        $this->conditions[] = new BetweenCondition($column, $min, $max, Conjunction::And);

        return $this;
    }

    /**
     * Add a condition written as SQL, joined to the preceding one with AND.
     *
     * What is given here goes into the statement as written, so it is the one
     * place in a WHERE clause where the text is the caller's rather than the
     * grammar's. Values belong in the bindings, where the prepared statement
     * keeps them from being read as SQL.
     *
     * @param  string                   $sql      SQL of the condition, with `?` where its values go
     * @param  array<int|string, mixed> $bindings Values for the placeholders, in order
     * @return static                   This builder
     * @throws InvalidArgumentException When the bindings are not a list
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->conditions[] = new RawCondition(Expression::of($sql, $bindings), Conjunction::And);

        return $this;
    }

    /**
     * Add a condition written as SQL, joined to the preceding one with OR.
     *
     * @param  string                   $sql      SQL of the condition, with `?` where its values go
     * @param  array<int|string, mixed> $bindings Values for the placeholders, in order
     * @return static                   This builder
     * @throws InvalidArgumentException When the bindings are not a list
     */
    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        $this->conditions[] = new RawCondition(Expression::of($sql, $bindings), Conjunction::Or);

        return $this;
    }

    /**
     * Open a group of conditions, joined to what precedes it with AND.
     *
     * Everything added until the matching whereClose() goes inside the
     * parentheses, so a chain groups by opening and closing rather than by
     * handing over a closure.
     *
     * @return static This builder
     */
    public function whereOpen(): static
    {
        return $this->openGroup(Conjunction::And);
    }

    /**
     * Open a group of conditions, joined to what precedes it with AND.
     *
     * Same as whereOpen(); spelled out for a chain that names the conjunction
     * at every step.
     *
     * @return static This builder
     */
    public function andWhereOpen(): static
    {
        return $this->openGroup(Conjunction::And);
    }

    /**
     * Open a group of conditions, joined to what precedes it with OR.
     *
     * @return static This builder
     */
    public function orWhereOpen(): static
    {
        return $this->openGroup(Conjunction::Or);
    }

    /**
     * Close the group opened last.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function whereClose(): static
    {
        return $this->closeGroup();
    }

    /**
     * Close the group opened last.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function andWhereClose(): static
    {
        return $this->closeGroup();
    }

    /**
     * Close the group opened last.
     *
     * The conjunction of a group is decided where it opens, so this reads the
     * same as whereClose() and exists to end a chain that opened with
     * orWhereOpen() in the matching spelling.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function orWhereClose(): static
    {
        return $this->closeGroup();
    }

    /**
     * Apply one of two callbacks depending on a condition.
     *
     * Lets a chain stay a chain where part of a statement depends on something
     * decided at runtime, instead of being broken up by an if statement around
     * the builder.
     *
     * The condition is a boolean rather than anything readable as one, so that
     * what decides is written at the call site: `0`, `'0'` and `[]` are all
     * falsy in PHP, and a statement that silently loses a clause because a
     * filter happened to be the number zero is the kind of surprise the rest of
     * this builder refuses.
     *
     * @param  bool          $condition Whether to apply the first callback
     * @param  callable      $callback  Applied to this builder when the condition holds
     * @param  callable|null $default   Applied to this builder when it does not
     * @return static        This builder
     */
    public function when(bool $condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            $callback($this);

            return $this;
        }

        if ($default !== null) {
            $default($this);
        }

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
    public function orderBy(string|Expression $column, string $direction = 'ASC'): static
    {
        $this->orders[] = new Order($column, self::toDirection($direction));

        return $this;
    }

    /**
     * Sort by a term written as SQL.
     *
     * The text carries its own direction, so none is appended. As with
     * whereRaw(), what is given goes into the statement as written and values
     * belong in the bindings.
     *
     * @param  string                   $sql      SQL of the sort term, with `?` where its values go
     * @param  array<int|string, mixed> $bindings Values for the placeholders, in order
     * @return static                   This builder
     * @throws InvalidArgumentException When the bindings are not a list
     */
    public function orderByRaw(string $sql, array $bindings = []): static
    {
        $this->orders[] = new Order(Expression::of($sql, $bindings), null);

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
     * Refuse a statement that opened a group and never closed it.
     *
     * Called where a statement is compiled, which is the last moment the chain
     * can still be wrong and the first at which nothing more will be added. An
     * unclosed group would otherwise reach the server as SQL that ends in the
     * middle of a parenthesis.
     *
     * @return void
     * @throws LogicException When a group is still open
     */
    protected function requireGroupsClosed(): void
    {
        if ($this->openGroups > 0) {
            throw new LogicException(
                'A group of conditions was opened and not closed; call whereClose() '
                . $this->openGroups . ' more time' . ($this->openGroups === 1 ? '' : 's') . '.',
            );
        }
    }

    /**
     * Record the opening of a group.
     *
     * @param  Conjunction $conjunction How the group joins to what precedes it
     * @return static      This builder
     */
    private function openGroup(Conjunction $conjunction): static
    {
        $this->conditions[] = new GroupBoundary(GroupEdge::Open, $conjunction);
        $this->openGroups++;

        return $this;
    }

    /**
     * Record the closing of the group opened last.
     *
     * Refused here rather than when the statement is compiled: a close with
     * nothing to close is a mistake in the chain, and the line that made it is
     * still the line being executed.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    private function closeGroup(): static
    {
        if ($this->openGroups === 0) {
            throw new LogicException('No group of conditions is open, so there is nothing to close.');
        }

        $this->conditions[] = new GroupBoundary(GroupEdge::Close);
        $this->openGroups--;

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
     * @throws InvalidArgumentException              When the operator is not a string or not a supported comparison, or null stands where the operator cannot read it
     */
    private static function toCondition(
        Conjunction $conjunction,
        string|Expression $column,
        string|int|float|bool|Expression|null $operator,
        string|int|float|bool|Expression|null $value,
        int $argumentCount,
    ): Condition {
        if ($argumentCount < 3) {
            return new Condition($column, '=', $operator, $conjunction);
        }

        if (!\is_string($operator)) {
            throw new InvalidArgumentException(
                'A comparison operator must be a string, got ' . get_debug_type($operator) . '.',
            );
        }

        return new Condition($column, $operator, $value, $conjunction);
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
