<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use LogicException;

/**
 * The layer every kind of statement shares below Query.
 *
 * Query knows how to compile and run; this is where the parts that only a
 * builder needs belong — the helpers for clauses that appear in more than one
 * kind of statement, such as a join, which reads the same whether it is being
 * selected from or updated through.
 *
 * It sits between Query and BuilderWhere so that a statement without a WHERE
 * clause still reaches those helpers: an INSERT extends this class directly
 * rather than inheriting a clause it has no use for.
 *
 * What is collected here is reached through protected methods, and the
 * statements that can carry a join expose them under their own names. An INSERT
 * inherits the machinery without inheriting a join() it could not write.
 */
abstract class Builder extends Query
{
    /**
     * Joins in the order they were added.
     *
     * @var list<Join>
     */
    protected array $joins = [];

    /**
     * Groups opened on the ON clause of the last join and not yet closed.
     *
     * One counter serves every join because a join settles when the next one
     * starts: addJoin() refuses a group still open, so the count is only ever
     * about the join being written now.
     *
     * @var int
     */
    private int $openOnGroups = 0;

    /**
     * Start a join and make it the one that ON conditions are added to.
     *
     * @param  JoinType       $type  Which rows to keep when the conditions find no match
     * @param  string         $table Table to join, optionally schema qualified
     * @return static         This builder
     * @throws LogicException When the join before this one left a group of ON conditions open
     */
    protected function addJoin(JoinType $type, string $table): static
    {
        $this->requireOnGroupsClosed();

        $this->joins[] = new Join($type, $table);

        return $this;
    }

    /**
     * Add one comparison to the ON clause of the last join.
     *
     * Called with two arguments the second one is the column to compare against
     * and the comparison is `=`; called with three the second one is the
     * operator.
     *
     * @param  Conjunction              $conjunction   How the comparison joins to the one before it
     * @param  string|Expression        $column        Column on the left, or an expression standing in for one
     * @param  string|Expression        $operator      Operator when a column follows, otherwise the column itself
     * @param  string|Expression|null   $reference     Column on the right, when an operator was given
     * @param  int                      $argumentCount Number of arguments the caller passed
     * @return static                   This builder
     * @throws LogicException           When no join has been started
     * @throws InvalidArgumentException When the operator is not one this grammar writes, or reads a keyword rather than a column
     */
    protected function addOn(
        Conjunction $conjunction,
        string|Expression $column,
        string|Expression $operator,
        string|Expression|null $reference,
        int $argumentCount,
    ): static {
        return $argumentCount < 3
            ? $this->addOnPart($this->grammar->joinComparison($column, '=', $operator, $conjunction))
            : $this->addOnPart($this->toJoinCondition($conjunction, $column, $operator, $reference));
    }

    /**
     * Open a group of conditions on the ON clause of the last join.
     *
     * @param  Conjunction    $conjunction How the group joins to what precedes it
     * @return static         This builder
     * @throws LogicException When no join has been started
     */
    protected function openOnGroup(Conjunction $conjunction): static
    {
        $this->addOnPart(new GroupBoundary(GroupEdge::Open, $conjunction));
        $this->openOnGroups++;

        return $this;
    }

    /**
     * Close the group opened last on the ON clause of the last join.
     *
     * Refused here rather than when the statement is compiled, for the reason
     * BuilderWhere gives: a close with nothing to close is a mistake in the
     * chain, and the line that made it is still the line being executed.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    protected function closeOnGroup(): static
    {
        if ($this->openOnGroups === 0) {
            throw new LogicException('No group of ON conditions is open, so there is nothing to close.');
        }

        $this->addOnPart(new GroupBoundary(GroupEdge::Close));
        $this->openOnGroups--;

        return $this;
    }

    /**
     * Refuse a join that would pair every row with every other.
     *
     * Called where a statement is compiled, which is the last moment the chain
     * can still be wrong. A join whose ON clause holds nothing is valid SQL and
     * reads as a cross join, so nothing downstream would report it: the rows
     * would simply be multiplied.
     *
     * @return void
     * @throws LogicException When a group of ON conditions is still open, or a join carries no condition
     */
    protected function requireJoinsUsable(): void
    {
        $this->requireOnGroupsClosed();

        foreach ($this->joins as $join) {
            if (!$join->hasCondition()) {
                throw new LogicException(
                    'The join on `' . $join->table . '` carries no ON condition, so it would pair every row'
                    . ' with every other. Add one with on(), or write the cross join as a raw statement.',
                );
            }
        }
    }

    /**
     * Refuse a chain that opened a group of ON conditions and never closed it.
     *
     * @return void
     * @throws LogicException When a group is still open
     */
    private function requireOnGroupsClosed(): void
    {
        if ($this->openOnGroups > 0) {
            throw new LogicException(
                'A group of ON conditions was opened and not closed; call onClose() '
                . $this->openOnGroups . ' more time' . ($this->openOnGroups === 1 ? '' : 's') . '.',
            );
        }
    }

    /**
     * Record one part on the ON clause of the last join.
     *
     * @param  WherePart      $part Part to add
     * @return static         This builder
     * @throws LogicException When no join has been started
     */
    private function addOnPart(WherePart $part): static
    {
        $last = array_pop($this->joins)
            ?? throw new LogicException('An ON condition belongs to a join, so call join() before on().');

        $this->joins[] = $last->with($part);

        return $this;
    }

    /**
     * Read one ON condition out of the shape that names an operator.
     *
     * The number of arguments is what tells the two shapes apart rather than
     * the type of the second one, for the reason BuilderWhere gives: a caller
     * comparing against a column named `=` means that column.
     *
     * @param  Conjunction              $conjunction How the comparison joins to the one before it
     * @param  string|Expression        $column      Column on the left, or an expression standing in for one
     * @param  string|Expression        $operator    Comparison operator
     * @param  string|Expression|null   $reference   Column on the right
     * @return JoinCondition            The comparison to add
     * @throws InvalidArgumentException When the operator is an Expression, or the right-hand side is missing
     */
    private function toJoinCondition(
        Conjunction $conjunction,
        string|Expression $column,
        string|Expression $operator,
        string|Expression|null $reference,
    ): JoinCondition {
        if (!\is_string($operator)) {
            throw new InvalidArgumentException(
                'A comparison operator must be a string, got ' . get_debug_type($operator) . '.',
            );
        }

        if ($reference === null) {
            throw new InvalidArgumentException(
                'An ON condition compares two columns, so the right-hand side cannot be null.'
                . ' Write a column, or an Expression carrying the value it stands for.',
            );
        }

        return $this->grammar->joinComparison($column, $operator, $reference, $conjunction);
    }
}
