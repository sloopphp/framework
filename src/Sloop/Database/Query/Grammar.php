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
     * @var string
     */
    private const string PREFIX_PATTERN = '/\A[A-Za-z0-9_]*\z/';

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

        return new CompiledSql(
            'SELECT ' . $columns->sql . $from->sql . $where->sql . $orderBy->sql . $limit->sql,
            array_merge(
                $columns->bindings,
                $from->bindings,
                $where->bindings,
                $orderBy->bindings,
                $limit->bindings,
            ),
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
     * @param  string                   $identifier Identifier, optionally qualified ('users.id')
     * @return string                   Backtick-quoted identifier
     * @throws InvalidArgumentException When a segment is empty or the name has more than three segments
     */
    public function quoteIdentifier(string $identifier): string
    {
        $segments = IdentifierQuoter::split($identifier);

        if (\count($segments) > 3) {
            throw new InvalidArgumentException(
                'An identifier may have at most three segments (schema.table.column), got ' . $identifier . '.',
            );
        }

        return $this->quoteSegments($segments, \count($segments) - 2, allowStar: true);
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
            $compiled = $this->compileColumnReference($column);
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
     * The conjunction of the first condition is ignored: there is nothing
     * before it to join to.
     *
     * @param  list<Condition>          $conditions Conditions in the order they were added
     * @return CompiledSql              WHERE clause led by a space, empty when there are no conditions
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileWhere(array $conditions): CompiledSql
    {
        $sql      = '';
        $bindings = [];

        foreach ($conditions as $index => $condition) {
            $column = $this->compileColumnReference($condition->column);
            $value  = $this->compileValue($condition->value);

            $sql .= ($index === 0 ? ' WHERE ' : ' ' . $condition->conjunction->value . ' ')
                . $column->sql . ' ' . $condition->operator . ' ' . $value->sql;

            $bindings = array_merge($bindings, $column->bindings, $value->bindings);
        }

        return new CompiledSql($sql, $bindings);
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
            $parts[]  = $column->sql . ' ' . $order->direction->value;
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
     * Compile something that stands where a column may stand.
     *
     * @param  string|Expression        $column Column name, or an Expression standing in for one
     * @return CompiledSql              Quoted identifier, or the SQL of the Expression with its bindings
     * @throws InvalidArgumentException When the identifier is malformed
     */
    protected function compileColumnReference(string|Expression $column): CompiledSql
    {
        return $column instanceof Expression
            ? new CompiledSql($column->sql(), $column->bindings())
            : new CompiledSql($this->quoteIdentifier($column));
    }

    /**
     * Compile the right-hand side of a comparison.
     *
     * @param  string|int|float|bool|Expression $value Value to compare against
     * @return CompiledSql                      A placeholder with the value bound, or the SQL of the Expression
     */
    protected function compileValue(string|int|float|bool|Expression $value): CompiledSql
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
                if (!$allowStar || $index !== $lastIndex) {
                    throw new InvalidArgumentException(
                        'A column reference may end in *, but nothing else may contain it, got '
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
