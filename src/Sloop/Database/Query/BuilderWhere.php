<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use Closure;
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
     * Depth below which the code running now may not close.
     *
     * A closure handed to where() is given this builder, so nothing stops it
     * calling whereClose() more times than it opened. Without a floor those
     * extra calls would close groups belonging to whoever called it, and the
     * conditions the closure adds afterwards would land outside the parentheses
     * it appears to be writing inside — silently, and with a different set of
     * rows coming back.
     *
     * @var int
     */
    private int $groupFloor = 0;

    /**
     * Whether a closure returned with a group of its own still open.
     *
     * The group is closed for it so that the depth is what it was before the
     * closure ran, which keeps a later close from taking a group it did not
     * open. The mistake itself is remembered here and reported when the
     * statement is compiled, because raising it from the finally would replace
     * whatever failure the closure was already carrying.
     *
     * @var bool
     */
    private bool $groupLeftOpenInClosure = false;

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
     * Add one condition, several at once, or a parenthesised group.
     *
     * Called with two arguments the second one is the value and the comparison
     * is `=`; called with three the second one is the operator.
     *
     * A list of conditions adds each of them, and a closure opens a group and
     * hands this builder to it. Both are described under addConditions() and
     * group().
     *
     * @param  string|Expression|array<int|string, mixed>|Closure $column   Column to compare, an expression standing in for one, a list of conditions, or a closure receiving this builder
     * @param  string|int|float|bool|Expression|null              $operator Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null              $value    Value to compare against, when an operator was given
     * @return static                                             This builder
     * @throws InvalidArgumentException                           When a condition is malformed, the operator is not a supported comparison, or null stands where the operator cannot read it
     * @throws LogicException                                     When a closure closes the group it was handed, or closes one it never opened
     */
    public function where(
        string|Expression|array|Closure $column,
        string|int|float|bool|Expression|null $operator = null,
        string|int|float|bool|Expression|null $value = null,
    ): static {
        return $this->addWhere(Conjunction::And, $column, $operator, $value, \func_num_args());
    }

    /**
     * Add one condition, several at once, or a parenthesised group, joined with AND.
     *
     * Same as where(); spelled out for a chain that reads better with the
     * conjunction named at every step.
     *
     * @param  string|Expression|array<int|string, mixed>|Closure $column   Column to compare, an expression standing in for one, a list of conditions, or a closure receiving this builder
     * @param  string|int|float|bool|Expression|null              $operator Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null              $value    Value to compare against, when an operator was given
     * @return static                                             This builder
     * @throws InvalidArgumentException                           When a condition is malformed, the operator is not a supported comparison, or null stands where the operator cannot read it
     * @throws LogicException                                     When a closure closes the group it was handed, or closes one it never opened
     */
    public function andWhere(
        string|Expression|array|Closure $column,
        string|int|float|bool|Expression|null $operator = null,
        string|int|float|bool|Expression|null $value = null,
    ): static {
        return $this->addWhere(Conjunction::And, $column, $operator, $value, \func_num_args());
    }

    /**
     * Add one condition, several at once, or a parenthesised group, joined with OR.
     *
     * Where several conditions are given, the OR joins the first of them and the
     * rest follow with AND, so the set reads as one alternative to what precedes
     * it. MySQL binds AND tighter than OR, so that is what the SQL says without
     * parentheses being added.
     *
     * @param  string|Expression|array<int|string, mixed>|Closure $column   Column to compare, an expression standing in for one, a list of conditions, or a closure receiving this builder
     * @param  string|int|float|bool|Expression|null              $operator Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null              $value    Value to compare against, when an operator was given
     * @return static                                             This builder
     * @throws InvalidArgumentException                           When a condition is malformed, the operator is not a supported comparison, or null stands where the operator cannot read it
     * @throws LogicException                                     When a closure closes the group it was handed, or closes one it never opened
     */
    public function orWhere(
        string|Expression|array|Closure $column,
        string|int|float|bool|Expression|null $operator = null,
        string|int|float|bool|Expression|null $value = null,
    ): static {
        return $this->addWhere(Conjunction::Or, $column, $operator, $value, \func_num_args());
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
     * @throws LogicException When no group is open, or a closure is closing the group it was handed
     */
    public function whereClose(): static
    {
        return $this->closeGroup();
    }

    /**
     * Close the group opened last.
     *
     * @return static         This builder
     * @throws LogicException When no group is open, or a closure is closing the group it was handed
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
     * @throws LogicException When no group is open, or a closure is closing the group it was handed
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
        if ($this->groupLeftOpenInClosure) {
            throw new LogicException(
                'A closure opened a group of conditions and returned without closing it.'
                . ' Close it inside the closure, or leave the parentheses it was handed to close themselves.',
            );
        }

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
     * Inside a closure the refusal starts one level higher: the group the
     * closure was handed is closed for it when the closure returns, so the
     * closure may only close groups it opened itself. Closing the one it was
     * given would put every condition it writes afterwards outside the
     * parentheses it appears to be writing inside, and the refusal has to
     * happen here rather than where the group is left, because by then those
     * conditions have already been recorded in the wrong place.
     *
     * @return static         This builder
     * @throws LogicException When no group is open, or the open group is the one a closure was handed
     */
    private function closeGroup(): static
    {
        if ($this->openGroups <= $this->groupFloor) {
            throw new LogicException(
                $this->openGroups === 0
                    ? 'No group of conditions is open, so there is nothing to close.'
                    : 'This group is closed when the closure returns, so the closure cannot close it itself.',
            );
        }

        $this->conditions[] = new GroupBoundary(GroupEdge::Close);
        $this->openGroups--;

        return $this;
    }

    /**
     * Read what the where methods were given and record it.
     *
     * @param  Conjunction                                        $conjunction   How what is added joins to what precedes it
     * @param  string|Expression|array<int|string, mixed>|Closure $column        Column, expression, list of conditions, or closure
     * @param  string|int|float|bool|Expression|null              $operator      Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null              $value         Value to compare against, when an operator was given
     * @param  int                                                $argumentCount Number of arguments the caller passed
     * @return static                                             This builder
     * @throws InvalidArgumentException                           When a condition is malformed, or a column stands alone
     * @throws LogicException                                     When a closure closes a group it may not close
     */
    private function addWhere(
        Conjunction $conjunction,
        string|Expression|array|Closure $column,
        string|int|float|bool|Expression|null $operator,
        string|int|float|bool|Expression|null $value,
        int $argumentCount,
    ): static {
        if ($column instanceof Closure || \is_array($column)) {
            if ($argumentCount > 1) {
                throw new InvalidArgumentException(
                    'A ' . ($column instanceof Closure ? 'closure' : 'list of conditions')
                    . ' says everything on its own, so the other ' . ($argumentCount - 1)
                    . ' argument(s) would be ignored. Pass it alone.',
                );
            }

            return $column instanceof Closure
                ? $this->group($conjunction, $column)
                : $this->addConditions($conjunction, $column);
        }

        if ($argumentCount < 2) {
            throw new InvalidArgumentException(
                'A comparison needs something to compare against, but only a column was given.'
                . ' Pass a value, or an operator and a value.',
            );
        }

        $this->conditions[] = self::toCondition($conjunction, $column, $operator, $value, $argumentCount);

        return $this;
    }

    /**
     * Add several conditions written as a list.
     *
     * Each condition is itself a list of two or three: a column and a value, or
     * a column, an operator and a value — the same two shapes the where methods
     * take as arguments. They join to each other with AND, and the first of them
     * joins to whatever precedes it with the conjunction of the calling method.
     *
     * An empty list adds nothing, so a set of conditions built up at runtime can
     * be handed over without the caller checking whether anything ended up in it.
     *
     * @param  Conjunction              $conjunction How the first condition joins to what precedes it
     * @param  array<int|string, mixed> $conditions  Conditions, each a list of two or three
     * @return static                   This builder
     * @throws InvalidArgumentException When a condition is not a list of two or three, or holds a column that is not one
     */
    private function addConditions(Conjunction $conjunction, array $conditions): static
    {
        $joinsToPrevious = $conjunction;

        foreach (array_values($conditions) as $index => $condition) {
            if (!\is_array($condition)) {
                throw new InvalidArgumentException(
                    'Each condition must be a list of two or three, got ' . get_debug_type($condition)
                    . ' at index ' . $index . '.',
                );
            }

            $parts = array_values($condition);
            $count = \count($parts);

            if ($count !== 2 && $count !== 3) {
                throw new InvalidArgumentException(
                    'Each condition must be a list of two (column, value) or three (column, operator, value), got '
                    . $count . ' at index ' . $index . '.',
                );
            }

            $column = $parts[0];

            if ($count === 3 && !\is_string($parts[1])) {
                throw new InvalidArgumentException(
                    'The middle part of a condition of three is the operator, so it must be a string, got '
                    . get_debug_type($parts[1]) . ' at index ' . $index . '.',
                );
            }

            if (!\is_string($column) && !$column instanceof Expression) {
                throw new InvalidArgumentException(
                    'The first part of a condition names a column, so it must be a string or an Expression, got '
                    . get_debug_type($column) . ' at index ' . $index . '.',
                );
            }

            $this->conditions[] = self::toCondition(
                $joinsToPrevious,
                $column,
                self::toComparable($parts[1], $index),
                $count === 3 ? self::toComparable($parts[2], $index) : null,
                $count,
            );

            $joinsToPrevious = Conjunction::And;
        }

        return $this;
    }

    /**
     * Open a group, hand this builder to the closure, and close it again.
     *
     * The group is closed even where the closure throws, so a caller that
     * catches the failure and carries on is not left holding a builder whose
     * parentheses no longer balance.
     *
     * The closure cannot close this group itself: the floor raised here puts it
     * out of reach for as long as the closure runs, so an attempt fails at the
     * line that made it. Letting it through and tidying up afterwards would be
     * too late, because whatever the closure wrote after closing has already
     * been recorded outside the parentheses.
     *
     * A closure that opened further groups and left them open has them closed
     * here as well, so that the depth on the way out is the depth on the way
     * in. Leaving them open instead would let a later close take a group it
     * never opened, which is how two mistakes in one chain used to cancel out
     * and put conditions in parentheses nobody wrote. The mistake is not
     * forgiven by the tidying up: it is remembered and reported when the
     * statement is compiled, which is late enough not to replace a failure the
     * closure was already carrying.
     *
     * @param  Conjunction              $conjunction How the group joins to what precedes it
     * @param  Closure                  $callback    Applied to this builder inside the group
     * @return static                   This builder
     * @throws InvalidArgumentException When a condition added inside the group is malformed
     * @throws LogicException           When the closure closes the group it was handed, or one it never opened
     */
    private function group(Conjunction $conjunction, Closure $callback): static
    {
        $floor = $this->groupFloor;

        $this->openGroup($conjunction);
        $this->groupFloor = $this->openGroups;
        $depth            = $this->openGroups;

        try {
            $callback($this);
        } finally {
            $this->groupLeftOpenInClosure = $this->groupLeftOpenInClosure || $this->openGroups !== $depth;
            $this->groupFloor             = $floor;

            while ($this->openGroups >= $depth) {
                $this->closeGroup();
            }
        }

        return $this;
    }

    /**
     * Reject a part of a condition that could never stand where a value does.
     *
     * The list comes from an array rather than from the signature, so the types
     * the where methods are held to have to be checked here instead.
     *
     * @param  mixed                                 $part  Part of a condition, as it was written in the list
     * @param  int                                   $index Position of the condition, for the message
     * @return string|int|float|bool|Expression|null The same part, known to be comparable
     * @throws InvalidArgumentException              When the part is neither scalar, null, nor an Expression
     */
    private static function toComparable(mixed $part, int $index): string|int|float|bool|Expression|null
    {
        if ($part !== null && !\is_scalar($part) && !$part instanceof Expression) {
            throw new InvalidArgumentException(
                'A condition compares against a scalar, null or an Expression, got '
                . get_debug_type($part) . ' at index ' . $index . '.',
            );
        }

        return $part;
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
