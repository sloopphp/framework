<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * Turns the parts of a query into MySQL text and the bindings that go with it.
 *
 * A query builder collects what the caller asked for and hands it here, so the
 * dialect lives in one class instead of being spread across the builders. Each
 * clause has its own method, and the class is open for a subclass to replace a
 * single one of them rather than the whole statement.
 *
 * Identifiers are quoted with backticks and the table prefix is applied while
 * quoting, which is why a table name written inside an Expression never picks
 * one up: the SQL of an Expression is embedded as given.
 */
class Grammar
{
    /**
     * A table prefix goes straight into an identifier, so it is held to the
     * same shape as any other identifier the framework writes itself.
     *
     * Anchored with \A and \z rather than ^ and $, because $ also matches
     * before a trailing newline and would let one through.
     *
     * ConnectionConfigResolver applies the same rule to the `prefix` config
     * key. The check is repeated here because a Grammar can be built without
     * going through the config layer at all, and this is where the value
     * reaches an identifier. PrefixRuleAgreementTest holds the two to the same
     * answer for every ASCII character, so a change to either character class
     * shows up there.
     *
     * @var string
     */
    private const string PREFIX_PATTERN = '/\A[a-zA-Z0-9_]*\z/';

    /**
     * Comparison operators this grammar writes, and what each takes on the right.
     *
     * An operator ends up in the SQL as it is spelled here, so the set is fixed
     * rather than taken from the caller: a comparison built from outside the
     * application cannot carry a fragment of SQL in its operator.
     *
     * @var array<string, Operand>
     */
    private const array COMPARISON_OPERATORS = [
        '='           => Operand::Value,
        '<=>'         => Operand::ValueOrNull,
        '!='          => Operand::Value,
        '<>'          => Operand::Value,
        '<'           => Operand::Value,
        '<='          => Operand::Value,
        '>'           => Operand::Value,
        '>='          => Operand::Value,
        'LIKE'        => Operand::Value,
        'NOT LIKE'    => Operand::Value,
        'REGEXP'      => Operand::Value,
        'NOT REGEXP'  => Operand::Value,
        'RLIKE'       => Operand::Value,
        'SOUNDS LIKE' => Operand::Value,
        'IS'          => Operand::Keyword,
        'IS NOT'      => Operand::Keyword,
    ];

    /**
     * Build a grammar for a connection.
     *
     * @param  string                   $prefix Prepended to every table name; empty for none
     * @throws InvalidArgumentException When the prefix is not alphanumeric and underscore
     */
    public function __construct(protected readonly string $prefix = '')
    {
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new InvalidArgumentException(
                'Table prefix must contain only alphanumeric and underscore characters, got "' . $prefix . '".',
            );
        }
    }

    /**
     * Compile a SELECT statement and the bindings its placeholders need.
     *
     * @param  SelectSpec               $spec Parts of the statement
     * @return CompiledSql              SQL and bindings, the bindings in placeholder order
     * @throws InvalidArgumentException When an identifier is malformed
     */
    public function compileSelect(SelectSpec $spec): CompiledSql
    {
        $columns = $this->compileColumns($spec->columns);
        $from    = $this->compileFrom($spec->from);
        $where   = $this->compileWhere($spec->conditions);
        $orderBy = $this->compileOrderBy($spec->orders);
        $limit   = $this->compileLimit($spec->limit, $spec->offset);
        $lock    = $this->compileLock($spec->lock);

        return new CompiledSql(
            'SELECT ' . $columns->sql . $from->sql . $where->sql . $orderBy->sql . $limit->sql . $lock->sql,
            array_merge(
                $columns->bindings,
                $from->bindings,
                $where->bindings,
                $orderBy->bindings,
                $limit->bindings,
                $lock->bindings,
            ),
        );
    }

    /**
     * Compile a DELETE statement and the bindings its placeholders need.
     *
     * ORDER BY and LIMIT are written for the same reason MySQL takes them
     * here: they say which rows go when only some of the matches are to be
     * removed. Without a limit an order changes nothing about the result and
     * is written anyway, since it is what the caller asked for.
     *
     * @param  DeleteSpec               $spec Parts of the statement
     * @return CompiledSql              SQL and bindings, the bindings in placeholder order
     * @throws InvalidArgumentException When an identifier is malformed
     */
    public function compileDelete(DeleteSpec $spec): CompiledSql
    {
        $from    = $this->compileFrom($spec->from);
        $where   = $this->compileWhere($spec->conditions);
        $orderBy = $this->compileOrderBy($spec->orders);
        $limit   = $this->compileLimit($spec->limit, null);

        return new CompiledSql(
            'DELETE' . $from->sql . $where->sql . $orderBy->sql . $limit->sql,
            array_merge($from->bindings, $where->bindings, $orderBy->bindings, $limit->bindings),
        );
    }

    /**
     * Quote a table name, applying the table prefix.
     *
     * The prefix names a table, so in a schema qualified name it goes on the
     * last segment.
     *
     * @param  string                   $table Table name, optionally schema qualified ('reporting.users')
     * @return string                   Backtick-quoted table name
     * @throws InvalidArgumentException When a segment is empty or the name has more than two segments
     */
    public function quoteTable(string $table): string
    {
        $segments = IdentifierQuoter::split($table);

        if (\count($segments) > 2) {
            throw new InvalidArgumentException(
                'A table name may have at most two segments (schema.table), got ' . $table . '.',
            );
        }

        return $this->quoteSegments($segments, \count($segments) - 1, allowStar: false);
    }

    /**
     * Quote an identifier, applying the table prefix to the table it names.
     *
     * An unqualified name is a column and gets no prefix. In a qualified name
     * the table is the second-to-last segment, so `users.id` and
     * `reporting.users.id` both prefix `users`.
     *
     * `*` only names every column where a list of columns is expected, so it is
     * rejected unless the caller says it stands in such a place.
     *
     * @param  string                   $identifier       Identifier, optionally qualified ('users.id')
     * @param  bool                     $allowEveryColumn Whether a trailing `*` stands for every column here
     * @return string                   Backtick-quoted identifier
     * @throws InvalidArgumentException When a segment is empty, the name has more than three segments, or `*` stands where it has no meaning
     */
    public function quoteIdentifier(string $identifier, bool $allowEveryColumn = false): string
    {
        $segments = IdentifierQuoter::split($identifier);

        if (\count($segments) > 3) {
            throw new InvalidArgumentException(
                'An identifier may have at most three segments (schema.table.column), got ' . $identifier . '.',
            );
        }

        return $this->quoteSegments($segments, \count($segments) - 2, allowStar: $allowEveryColumn);
    }

    /**
     * Compile the select list.
     *
     * @param  list<string|Expression>  $columns Columns to select; empty selects everything
     * @return CompiledSql              Select list and the bindings of any Expression in it
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileColumns(array $columns): CompiledSql
    {
        if ($columns === []) {
            return new CompiledSql('*');
        }

        $parts    = [];
        $bindings = [];

        foreach ($columns as $column) {
            $compiled = $this->compileColumnReference($column, allowEveryColumn: true);
            $parts[]  = $compiled->sql;
            $bindings = array_merge($bindings, $compiled->bindings);
        }

        return new CompiledSql(implode(', ', $parts), $bindings);
    }

    /**
     * Compile the FROM clause.
     *
     * @param  string                   $table Table to select from
     * @return CompiledSql              FROM clause, led by a space
     * @throws InvalidArgumentException When the table name is malformed
     */
    protected function compileFrom(string $table): CompiledSql
    {
        return new CompiledSql(' FROM ' . $this->quoteTable($table));
    }

    /**
     * Compile the WHERE clause.
     *
     * The conjunction of the first part is ignored, and so is that of the first
     * part inside a group: in both places there is nothing before it to join to.
     *
     * @param  list<WherePart>          $conditions Parts of the clause in the order they were added
     * @return CompiledSql              WHERE clause led by a space, empty when there are no conditions
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileWhere(array $conditions): CompiledSql
    {
        $parts = self::withoutEmptyGroups($conditions);

        if ($parts === []) {
            return new CompiledSql('');
        }

        $sql           = ' WHERE ';
        $bindings      = [];
        $atClauseStart = true;

        foreach ($parts as $part) {
            if ($part instanceof GroupBoundary && $part->edge === GroupEdge::Close) {
                $sql          .= ')';
                $atClauseStart = false;

                continue;
            }

            if (!$atClauseStart) {
                $sql .= ' ' . $part->conjunction->value . ' ';
            }

            if ($part instanceof GroupBoundary) {
                $sql          .= '(';
                $atClauseStart = true;

                continue;
            }

            $compiled      = $this->compileWherePart($part);
            $sql          .= $compiled->sql;
            $bindings      = array_merge($bindings, $compiled->bindings);
            $atClauseStart = false;
        }

        return new CompiledSql($sql, $bindings);
    }

    /**
     * Compile one part of a WHERE clause other than a parenthesis.
     *
     * @param  WherePart                $part Part to compile
     * @return CompiledSql              The part as SQL, with the bindings it needs
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileWherePart(WherePart $part): CompiledSql
    {
        return match (true) {
            $part instanceof Condition        => $this->compileComparison($part),
            $part instanceof InCondition      => $this->compileIn($part),
            $part instanceof BetweenCondition => $this->compileBetween($part),
            $part instanceof RawCondition     => new CompiledSql(
                $part->expression->sql(),
                $part->expression->bindings(),
            ),
            default                           => throw new InvalidArgumentException(
                'No rule for compiling ' . get_debug_type($part) . ' as part of a WHERE clause.',
            ),
        };
    }

    /**
     * Comparison operators this grammar accepts, and what each takes on the right.
     *
     * A subclass adds an operator by returning it alongside these. What it adds
     * is written into the SQL as spelled, so it is trusted the same way as the
     * operators the framework writes itself; a value taken from a request
     * belongs on the right-hand side, never here.
     *
     * Keys are matched as they are written when a comparison is compiled, and
     * a comparison is looked up by its operator upper-cased, so keep them
     * upper-case: an operator listed in any other case cannot be reached
     * through a builder.
     *
     * @return array<string, Operand> Operators in the spelling they are written with
     */
    protected function comparisonOperators(): array
    {
        return self::COMPARISON_OPERATORS;
    }

    /**
     * Build one comparison, refusing an operator this grammar does not write.
     *
     * The operator is matched without regard to case and kept in the spelling
     * listed by comparisonOperators(), so the SQL reads the same however the
     * caller spelled it.
     *
     * @param  string|Expression                     $column      Column to compare, or an expression standing in for one
     * @param  string                                $operator    Comparison operator; matched case-insensitively
     * @param  string|int|float|bool|Expression|null $value       Value to compare against; bound unless the operator reads it as a keyword
     * @param  Conjunction                           $conjunction How this joins to the preceding condition
     * @return Condition                             The comparison, ready for a builder to collect
     * @throws InvalidArgumentException              When the operator is not one this grammar writes, or the operator and the value do not go together
     */
    public function comparison(
        string|Expression $column,
        string $operator,
        string|int|float|bool|Expression|null $value,
        Conjunction $conjunction = Conjunction::And,
    ): Condition {
        $canonical = strtoupper($operator);
        $operand   = $this->comparisonOperators()[$canonical]
            ?? throw new InvalidArgumentException('Unsupported comparison operator "' . $operator . '".');

        if ($operand === Operand::Keyword) {
            if ($value !== null && !\is_bool($value)) {
                throw new InvalidArgumentException(
                    $canonical . ' tests against a keyword, so null, true and false are the only right-hand sides'
                    . ' it takes; got ' . get_debug_type($value) . '. Use = to compare against a value.',
                );
            }
        } else {
            self::refuseNullValue($operand, $value);
        }

        return new Condition($column, $canonical, $value, $conjunction);
    }

    /**
     * Compile one comparison.
     *
     * A test against a keyword writes the keyword rather than a placeholder:
     * what follows IS is read by the server as a keyword and not as an
     * expression, so a bound value there is a syntax error rather than a
     * comparison.
     *
     * @param  Condition                $condition Comparison to compile
     * @return CompiledSql              The comparison as SQL, with the bindings it needs
     * @throws InvalidArgumentException When an identifier is malformed, or the operator is not one this grammar writes
     */
    protected function compileComparison(Condition $condition): CompiledSql
    {
        $column  = $this->compileColumnReference($condition->column);
        $operand = $this->comparisonOperators()[$condition->operator]
            ?? throw new InvalidArgumentException(
                'Unsupported comparison operator "' . $condition->operator . '".',
            );

        if ($operand === Operand::Keyword) {
            return new CompiledSql(
                $column->sql . ' ' . $condition->operator . ' ' . self::keyword($condition->value),
                $column->bindings,
            );
        }

        self::refuseNullValue($operand, $condition->value);

        $value = $this->compileValue($condition->value);

        return new CompiledSql(
            $column->sql . ' ' . $condition->operator . ' ' . $value->sql,
            array_merge($column->bindings, $value->bindings),
        );
    }

    /**
     * Refuse null on the right of an operator that compares values.
     *
     * Checked where a comparison is built and again where it is compiled: a
     * Condition can be built without passing through comparison(), and letting
     * one through here would write a predicate that quietly matches no rows
     * rather than saying so.
     *
     * Written as "anything but the operand that reads null" rather than naming
     * the ones that refuse it, so that an operand added later refuses null
     * until it says otherwise.
     *
     * @param  Operand                               $operand What the operator reads on its right
     * @param  string|int|float|bool|Expression|null $value   Right-hand side of the comparison
     * @return void
     * @throws InvalidArgumentException              When null stands where the operator cannot read it
     */
    private static function refuseNullValue(
        Operand $operand,
        string|int|float|bool|Expression|null $value,
    ): void {
        if ($value === null && $operand !== Operand::ValueOrNull) {
            throw new InvalidArgumentException(
                'A comparison against null is never true, so it is rejected rather than matching no rows.'
                . ' Write IS or IS NOT to test for NULL.',
            );
        }
    }

    /**
     * Write the keyword an operator reads on its right-hand side.
     *
     * @param  string|int|float|bool|Expression|null $value Right-hand side of the comparison
     * @return string                                NULL, TRUE or FALSE
     * @throws InvalidArgumentException              When the value is not one the keyword operators read
     */
    private static function keyword(string|int|float|bool|Expression|null $value): string
    {
        return match ($value) {
            null    => 'NULL',
            true    => 'TRUE',
            false   => 'FALSE',
            default => throw new InvalidArgumentException(
                'An operator testing against a keyword reads null, true or false on the right, got '
                . get_debug_type($value) . '.',
            ),
        };
    }

    /**
     * Compile a test for membership in a set of values.
     *
     * @param  InCondition              $condition Membership test to compile
     * @return CompiledSql              The test as SQL, with the bindings it needs
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileIn(InCondition $condition): CompiledSql
    {
        $column   = $this->compileColumnReference($condition->column);
        $parts    = [];
        $bindings = $column->bindings;

        foreach ($condition->values as $value) {
            $compiled = $this->compileValue($value);
            $parts[]  = $compiled->sql;
            $bindings = array_merge($bindings, $compiled->bindings);
        }

        return new CompiledSql(
            $column->sql . ($condition->negated ? ' NOT IN (' : ' IN (') . implode(', ', $parts) . ')',
            $bindings,
        );
    }

    /**
     * Compile a test that a column falls within a range.
     *
     * @param  BetweenCondition         $condition Range test to compile
     * @return CompiledSql              The test as SQL, with the bindings it needs
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileBetween(BetweenCondition $condition): CompiledSql
    {
        $column = $this->compileColumnReference($condition->column);
        $min    = $this->compileValue($condition->min);
        $max    = $this->compileValue($condition->max);

        return new CompiledSql(
            $column->sql . ' BETWEEN ' . $min->sql . ' AND ' . $max->sql,
            array_merge($column->bindings, $min->bindings, $max->bindings),
        );
    }

    /**
     * Drop groups that ended up holding nothing.
     *
     * A group can be left empty by a when() that did not fire, and `()` is not
     * valid SQL. Dropping the pair keeps the rest of the clause as written,
     * which is what the caller who wrote the group would have got had the
     * condition inside been added.
     *
     * @param  list<WherePart> $conditions Parts of the clause in the order they were added
     * @return list<WherePart> The same parts, without any empty pair of parentheses
     */
    private static function withoutEmptyGroups(array $conditions): array
    {
        do {
            $kept    = [];
            $dropped = false;

            for ($index = 0, $total = \count($conditions); $index < $total; $index++) {
                $part = $conditions[$index];
                $next = $conditions[$index + 1] ?? null;

                if (
                    $part instanceof GroupBoundary && $part->edge === GroupEdge::Open
                    && $next instanceof GroupBoundary && $next->edge === GroupEdge::Close
                ) {
                    $index++;
                    $dropped = true;

                    continue;
                }

                $kept[] = $part;
            }

            $conditions = $kept;
        } while ($dropped);

        return $conditions;
    }

    /**
     * Compile the ORDER BY clause.
     *
     * @param  list<Order>              $orders Sort terms in the order they were added
     * @return CompiledSql              ORDER BY clause led by a space, empty when there is nothing to sort by
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileOrderBy(array $orders): CompiledSql
    {
        if ($orders === []) {
            return new CompiledSql('');
        }

        $parts    = [];
        $bindings = [];

        foreach ($orders as $order) {
            $column   = $this->compileColumnReference($order->column);
            $parts[]  = $column->sql . ($order->direction === null ? '' : ' ' . $order->direction->value);
            $bindings = array_merge($bindings, $column->bindings);
        }

        return new CompiledSql(' ORDER BY ' . implode(', ', $parts), $bindings);
    }

    /**
     * Compile the row limit.
     *
     * Both numbers are written into the SQL rather than bound, because they are
     * already integers by the time they get here. The return type still carries
     * bindings so that a dialect writing a placeholder here has somewhere to put
     * the value, instead of losing it silently.
     *
     * @param  int|null    $limit  Maximum number of rows, or null for no limit
     * @param  int|null    $offset Rows to skip, or null for none
     * @return CompiledSql LIMIT clause led by a space, empty when there is no limit
     */
    protected function compileLimit(?int $limit, ?int $offset): CompiledSql
    {
        if ($limit === null) {
            return new CompiledSql('');
        }

        return new CompiledSql(' LIMIT ' . $limit . ($offset === null ? '' : ' OFFSET ' . $offset));
    }

    /**
     * Compile the locking read clause.
     *
     * The shared case is written as LOCK IN SHARE MODE rather than FOR SHARE
     * because MariaDB 10.11 rejects the latter as a syntax error, while both
     * servers take this spelling.
     *
     * SKIP LOCKED and NOWAIT have a case each rather than being flags, because
     * the servers take one or the other and reject a statement carrying both.
     *
     * @param  RowLock|null $lock How to hold the rows read, or null to hold nothing
     * @return CompiledSql  Locking clause led by a space, empty when no lock was asked for
     */
    protected function compileLock(?RowLock $lock): CompiledSql
    {
        if ($lock === null) {
            return new CompiledSql('');
        }

        return new CompiledSql(' ' . match ($lock) {
            RowLock::Update           => 'FOR UPDATE',
            RowLock::UpdateSkipLocked => 'FOR UPDATE SKIP LOCKED',
            RowLock::UpdateNoWait     => 'FOR UPDATE NOWAIT',
            RowLock::Shared           => 'LOCK IN SHARE MODE',
        });
    }

    /**
     * Compile something that stands where a column may stand.
     *
     * @param  string|Expression        $column           Column name, or an Expression standing in for one
     * @param  bool                     $allowEveryColumn Whether a trailing `*` stands for every column here
     * @return CompiledSql              Quoted identifier, or the SQL of the Expression with its bindings
     * @throws InvalidArgumentException When the identifier is malformed
     */
    protected function compileColumnReference(string|Expression $column, bool $allowEveryColumn = false): CompiledSql
    {
        return $column instanceof Expression
            ? new CompiledSql($column->sql(), $column->bindings())
            : new CompiledSql($this->quoteIdentifier($column, $allowEveryColumn));
    }

    /**
     * Compile the right-hand side of a comparison.
     *
     * @param  string|int|float|bool|Expression|null $value Value to compare against
     * @return CompiledSql                           A placeholder with the value bound, or the SQL of the Expression
     */
    protected function compileValue(string|int|float|bool|Expression|null $value): CompiledSql
    {
        return $value instanceof Expression
            ? new CompiledSql($value->sql(), $value->bindings())
            : new CompiledSql('?', [$value]);
    }

    /**
     * Quote each segment, prefixing the one that names a table.
     *
     * `*` is left alone: it stands for every column rather than naming one, and
     * backticks around it would make it a column called `*`. It only means that
     * as the last segment of a column reference, so a caller that is naming a
     * table, or that puts it anywhere but last, is rejected rather than handed
     * a name nobody asked for.
     *
     * @param  list<string>             $segments     Segments of an identifier, none of them empty
     * @param  int                      $tableSegment Index of the segment naming a table; out of range applies no prefix
     * @param  bool                     $allowStar    Whether `*` may stand as the last segment
     * @return string                   Backtick-quoted identifier
     * @throws InvalidArgumentException When `*` stands where it does not mean every column
     */
    private function quoteSegments(array $segments, int $tableSegment, bool $allowStar): string
    {
        $lastIndex = \count($segments) - 1;
        $quoted    = [];

        foreach ($segments as $index => $segment) {
            if ($segment === '*') {
                if (!$allowStar) {
                    throw new InvalidArgumentException(
                        '* names every column, so it only stands where a list of columns does, got '
                        . implode('.', $segments) . '.',
                    );
                }

                if ($index !== $lastIndex) {
                    throw new InvalidArgumentException(
                        'Only the last part of a column reference may be *, got '
                        . implode('.', $segments) . '.',
                    );
                }

                $quoted[] = $segment;
            } else {
                $quoted[] = IdentifierQuoter::quoteSegment(
                    $index === $tableSegment ? $this->prefix . $segment : $segment,
                );
            }
        }

        return implode('.', $quoted);
    }
}
