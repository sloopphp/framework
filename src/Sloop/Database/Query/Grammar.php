<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use Sloop\Database\Dialect;

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
     * Name the row being inserted is given when the alias form is written.
     *
     * Prefixed rather than named for what it is, because the alias shares a
     * namespace with the table: MySQL answers an INSERT into a table of the
     * same name with 1066, and the alias is chosen here rather than by the
     * caller, who would have no way out but to rename the table. The prefix
     * makes that unlikely; rowAliasFor() is what makes it impossible.
     *
     * Backtick-quoted so a server that would otherwise read it as a keyword
     * takes it as the name it is.
     *
     * @var string
     */
    private const string ROW_ALIAS = '`sloop_upsert`';

    /**
     * Alias used where a table of the same name would otherwise be shadowed.
     *
     * Only ever needed against the one table the statement inserts into, so a
     * single stand-in is enough: it differs from ROW_ALIAS, and the table equals
     * ROW_ALIAS in the only case this is reached.
     *
     * @var string
     */
    private const string ROW_ALIAS_STAND_IN = '`sloop_upsert_row`';

    /**
     * First MySQL release that reads the row alias form of ON DUPLICATE KEY UPDATE.
     *
     * @var string
     */
    private const string ROW_ALIAS_MINIMUM = '8.0.19';

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
        $joins   = $this->compileJoin($spec->joins);
        $where   = $this->compileWhere($spec->conditions);
        $orderBy = $this->compileOrderBy($spec->orders);
        $limit   = $this->compileLimit($spec->limit, $spec->offset);
        $lock    = $this->compileLock($spec->lock);

        return new CompiledSql(
            'SELECT ' . $columns->sql . $from->sql . $joins->sql . $where->sql . $orderBy->sql
                . $limit->sql . $lock->sql,
            array_merge(
                $columns->bindings,
                $from->bindings,
                $joins->bindings,
                $where->bindings,
                $orderBy->bindings,
                $limit->bindings,
                $lock->bindings,
            ),
        );
    }

    /**
     * Compile an UPDATE statement and the bindings its placeholders need.
     *
     * The assignments come before the conditions, which is the order MySQL
     * reads the clauses in and so the order the placeholders stand in.
     *
     * ORDER BY and LIMIT are written for the same reason they are on a DELETE:
     * they say which rows go first when only some of the matches are to change.
     *
     * @param  UpdateSpec               $spec Parts of the statement
     * @return CompiledSql              SQL and bindings, the bindings in placeholder order
     * @throws InvalidArgumentException When an identifier is malformed
     */
    public function compileUpdate(UpdateSpec $spec): CompiledSql
    {
        $set     = $this->compileSet($spec->assignments);
        $where   = $this->compileWhere($spec->conditions);
        $orderBy = $this->compileOrderBy($spec->orders);
        $limit   = $this->compileLimit($spec->limit, null);

        return new CompiledSql(
            'UPDATE ' . $this->quoteTable($spec->table) . $set->sql . $where->sql . $orderBy->sql . $limit->sql,
            array_merge($set->bindings, $where->bindings, $orderBy->bindings, $limit->bindings),
        );
    }

    /**
     * Compile an INSERT statement and the bindings its placeholders need.
     *
     * The columns are named once and the rows follow as tuples in that order,
     * which is the form MySQL takes for several rows and reads the same for
     * one. The bindings run row by row, so they arrive in the order the
     * placeholders stand in.
     *
     * @param  InsertSpec               $spec Parts of the statement
     * @return CompiledSql              SQL and bindings, the bindings in placeholder order
     * @throws InvalidArgumentException When an identifier is malformed
     */
    public function compileInsert(InsertSpec $spec): CompiledSql
    {
        $table   = $this->quoteTable($spec->table);
        $columns = $this->compileInsertColumns($spec->columns);
        $rows    = $this->compileInsertRows($spec->rows);
        $upsert  = $this->compileUpsert($spec->upsert, $spec->rowAlias ? $this->rowAliasFor($table) : null);

        return new CompiledSql(
            'INSERT ' . ($spec->ignore ? 'IGNORE ' : '') . 'INTO ' . $table
                . $columns->sql . ' VALUES ' . $rows->sql . $upsert->sql,
            array_merge($columns->bindings, $rows->bindings, $upsert->bindings),
        );
    }

    /**
     * Pick the alias to give the incoming row, stepping aside from the table.
     *
     * An alias and the table it stands beside share a namespace, so a statement
     * inserting into a table of the alias's own name is refused (MySQL 1066).
     * The name reaching here is the quoted one, which is what the table prefix
     * has already been applied to: a caller who never writes the alias can
     * still land on it through `prefix` plus a table name.
     *
     * Names that differ only in case count as the same one, because whether
     * they do is the server's to decide: MySQL folds table names when
     * `lower_case_table_names` is not 0, and answers `Sloop_Upsert` beside this
     * alias with the same 1066 (measured on 8.0.46 with the setting at 1).
     * Stepping aside on a server that would have kept them apart costs nothing
     * but the longer alias.
     *
     * @param  string $quotedTable Table of the statement, quoted and prefixed
     * @return string The alias, quoted, differing from the table it stands beside
     */
    protected function rowAliasFor(string $quotedTable): string
    {
        $segments = explode('.', $quotedTable);

        return strcasecmp(end($segments), self::ROW_ALIAS) === 0
            ? self::ROW_ALIAS_STAND_IN
            : self::ROW_ALIAS;
    }

    /**
     * Whether the server reads the row alias form of ON DUPLICATE KEY UPDATE.
     *
     * MySQL takes `VALUES (...) AS alias ... = alias.col` from 8.0.19 and warns
     * on the older `VALUES(col)` from 8.0.20 (warning 1287, saying the function
     * will be removed). MariaDB 10.11 has no row alias at all — it answers that
     * form with a syntax error — and takes `VALUES(col)` without a warning, so
     * it stays on that one. What MariaDB added instead is the singular
     * `VALUE(col)`, which MySQL in turn refuses, so writing it would trade one
     * server for the other rather than serving both.
     *
     * A version this cannot read is treated as not supporting the alias, since
     * the older form runs on both servers and only warns.
     *
     * @param  Dialect $dialect       Server the statement is going to
     * @param  string  $serverVersion Raw `SELECT VERSION()` output of that server
     * @return bool    True to write the alias form
     */
    public function supportsRowAlias(Dialect $dialect, string $serverVersion): bool
    {
        if ($dialect !== Dialect::MySQL) {
            return false;
        }

        if (preg_match('/\A\d+\.\d+\.\d+/', $serverVersion, $matches) !== 1) {
            return false;
        }

        return version_compare($matches[0], self::ROW_ALIAS_MINIMUM, '>=');
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
     * Compile the joins of a statement, each with its ON clause.
     *
     * A join whose ON clause holds nothing is written without one, which the
     * server reads as a cross join. A builder refuses to compile such a join
     * before it reaches here, so what arrives with an empty clause is a spec
     * built by hand, and it is written as it was described rather than being
     * second-guessed.
     *
     * @param  list<Join>               $joins Joins in the order they were added
     * @return CompiledSql              Joins led by a space, empty when there are none
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileJoin(array $joins): CompiledSql
    {
        $sql      = '';
        $bindings = [];

        foreach ($joins as $join) {
            $on = $this->compileConditionList($join->conditions, ' ON ');

            $sql     .= ' ' . $join->type->value . ' ' . $this->quoteTable($join->table) . $on->sql;
            $bindings = array_merge($bindings, $on->bindings);
        }

        return new CompiledSql($sql, $bindings);
    }

    /**
     * Compile the column list of an INSERT.
     *
     * @param  list<string>             $columns Columns being written, in the order they are written in
     * @return CompiledSql              Parenthesised column list, led by a space
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileInsertColumns(array $columns): CompiledSql
    {
        $quoted = [];

        foreach ($columns as $column) {
            $quoted[] = $this->quoteIdentifier($column);
        }

        return new CompiledSql(' (' . implode(', ', $quoted) . ')');
    }

    /**
     * Compile the ON DUPLICATE KEY UPDATE clause of an INSERT.
     *
     * Each column is given the value this statement was carrying for it, read
     * back either through the row alias or through VALUES(), depending on what
     * the server takes. Nothing is bound here: the values are already in the
     * tuples and the clause only points at them.
     *
     * A column named with its table (`users.score`) stands on the left as it
     * was written, since that is the column of the target table. Behind the
     * alias only the name is written: the alias already stands for the row, so
     * qualifying again names a column of something else. MySQL 8.0.46 answers
     * `alias.users.score` with 1054, reading it as a column `score` of a table
     * `users` in a schema `alias`.
     *
     * @param  list<string>             $columns Columns to overwrite on a collision; empty for a plain INSERT
     * @param  string|null              $alias   Alias to give the incoming row, or null to reach it through VALUES()
     * @return CompiledSql              The clause led by a space, empty when there is nothing to overwrite
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileUpsert(array $columns, ?string $alias): CompiledSql
    {
        if ($columns === []) {
            return new CompiledSql('');
        }

        $assignments = [];

        foreach ($columns as $column) {
            $quoted = $this->quoteIdentifier($column);

            if ($alias === null) {
                $assignments[] = $quoted . ' = VALUES(' . $quoted . ')';

                continue;
            }

            $segments      = IdentifierQuoter::split($column);
            $assignments[] = $quoted . ' = ' . $alias . '.' . IdentifierQuoter::quoteSegment(end($segments));
        }

        return new CompiledSql(
            ($alias === null ? '' : ' AS ' . $alias)
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments),
        );
    }

    /**
     * Compile the tuples of an INSERT.
     *
     * @param  list<list<string|int|float|bool|Expression|null>> $rows Rows in the order they were added
     * @return CompiledSql                                       Tuples separated by commas, with the bindings they need
     */
    protected function compileInsertRows(array $rows): CompiledSql
    {
        $tuples   = [];
        $bindings = [];

        foreach ($rows as $row) {
            $parts = [];

            foreach ($row as $value) {
                $compiled = $this->compileValue($value);
                $parts[]  = $compiled->sql;

                // Appended rather than merged: this loop runs once per value
                // rather than once per clause, so copying the whole array each
                // time costs the square of the values written.
                foreach ($compiled->bindings as $binding) {
                    $bindings[] = $binding;
                }
            }

            $tuples[] = '(' . implode(', ', $parts) . ')';
        }

        return new CompiledSql(implode(', ', $tuples), $bindings);
    }

    /**
     * Compile the SET clause.
     *
     * A value is bound wherever it can be, so what a column is set to is read
     * as a value and never as SQL. An Expression is written out instead, which
     * is what lets a column be set from what it already holds.
     *
     * Null is written as a bound value rather than refused: setting a column to
     * NULL is what the statement is for, unlike a comparison against one, where
     * a placeholder would match no rows however the column stands.
     *
     * @param  list<Assignment>         $assignments Columns to write and the values going into them
     * @return CompiledSql              SET clause led by a space, empty when there is nothing to write
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileSet(array $assignments): CompiledSql
    {
        if ($assignments === []) {
            return new CompiledSql('');
        }

        $parts    = [];
        $bindings = [];

        foreach ($assignments as $assignment) {
            $column   = $this->compileColumnReference($assignment->column);
            $value    = $this->compileValue($assignment->value);
            $parts[]  = $column->sql . ' = ' . $value->sql;
            $bindings = array_merge($bindings, $column->bindings, $value->bindings);
        }

        return new CompiledSql(' SET ' . implode(', ', $parts), $bindings);
    }

    /**
     * Compile the WHERE clause.
     *
     * How the parts are read is described by compileConditionList(), which the
     * ON clause of a join goes through as well.
     *
     * @param  list<WherePart>          $conditions Parts of the clause in the order they were added
     * @return CompiledSql              WHERE clause led by a space, empty when there are no conditions
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileWhere(array $conditions): CompiledSql
    {
        return $this->compileConditionList($conditions, ' WHERE ');
    }

    /**
     * Compile a list of conditions under the keyword that introduces it.
     *
     * WHERE and ON are the same clause under two names: comparisons joined by
     * AND and OR, grouped by parentheses, in the order they were written. They
     * are compiled here together so that the two cannot come to disagree about
     * what `a OR b AND c` means, or about which parenthesis a conjunction
     * belongs to.
     *
     * The conjunction of the first part is ignored, and so is that of the first
     * part inside a group: in both places there is nothing before it to join to.
     *
     * @param  list<WherePart>          $conditions Parts of the clause in the order they were added
     * @param  string                   $lead       Keyword introducing the clause, spaced as it is written
     * @return CompiledSql              The clause led by the keyword, empty when nothing would reach the server
     * @throws InvalidArgumentException When an identifier is malformed
     */
    protected function compileConditionList(array $conditions, string $lead): CompiledSql
    {
        $parts = self::withoutEmptyGroups($conditions);

        if ($parts === []) {
            return new CompiledSql('');
        }

        $sql           = $lead;
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
            $part instanceof JoinCondition    => $this->compileJoinComparison($part),
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
     * Build one comparison between two columns, refusing an operator that
     * cannot stand between them.
     *
     * The operators are the ones comparisonOperators() lists, minus those that
     * read a keyword on the right: what follows IS is one of NULL, TRUE or
     * FALSE, so a column there is a syntax error rather than a comparison.
     *
     * @param  string|Expression        $column      Column on the left, or an expression standing in for one
     * @param  string                   $operator    Comparison operator; matched case-insensitively
     * @param  string|Expression        $reference   Column on the right, or an expression standing in for one
     * @param  Conjunction              $conjunction How this joins to the preceding condition
     * @return JoinCondition            The comparison, ready for a builder to collect
     * @throws InvalidArgumentException When the operator is not one this grammar writes, or reads a keyword rather than a column
     */
    public function joinComparison(
        string|Expression $column,
        string $operator,
        string|Expression $reference,
        Conjunction $conjunction = Conjunction::And,
    ): JoinCondition {
        $canonical = strtoupper($operator);

        self::requireColumnOperand($this->operandOf($canonical, $operator), $canonical);

        return new JoinCondition($column, $canonical, $reference, $conjunction);
    }

    /**
     * Compile one comparison between two columns.
     *
     * The operator is checked again here for the reason compileComparison()
     * gives: a JoinCondition can be built without passing through
     * joinComparison(), and one carrying an operator that reads a keyword would
     * write SQL the server refuses.
     *
     * @param  JoinCondition            $condition Comparison to compile
     * @return CompiledSql              The comparison as SQL, with the bindings of any Expression in it
     * @throws InvalidArgumentException When an identifier is malformed, or the operator is not one this grammar writes between columns
     */
    protected function compileJoinComparison(JoinCondition $condition): CompiledSql
    {
        self::requireColumnOperand(
            $this->operandOf($condition->operator, $condition->operator),
            $condition->operator,
        );

        $column    = $this->compileColumnReference($condition->column);
        $reference = $this->compileColumnReference($condition->reference);

        return new CompiledSql(
            $column->sql . ' ' . $condition->operator . ' ' . $reference->sql,
            array_merge($column->bindings, $reference->bindings),
        );
    }

    /**
     * Read what an operator takes on its right, refusing one this grammar does
     * not write.
     *
     * @param  string                   $canonical Operator upper-cased, as the operator table keys it
     * @param  string                   $asWritten Operator as the caller spelled it, for the message
     * @return Operand                  What the operator reads on its right
     * @throws InvalidArgumentException When the operator is not one this grammar writes
     */
    private function operandOf(string $canonical, string $asWritten): Operand
    {
        return $this->comparisonOperators()[$canonical]
            ?? throw new InvalidArgumentException('Unsupported comparison operator "' . $asWritten . '".');
    }

    /**
     * Refuse an operator that reads a keyword where a column has to stand.
     *
     * @param  Operand                  $operand  What the operator reads on its right
     * @param  string                   $operator Operator, upper-cased, for the message
     * @return void
     * @throws InvalidArgumentException When the operator reads a keyword
     */
    private static function requireColumnOperand(Operand $operand, string $operator): void
    {
        if ($operand === Operand::Keyword) {
            throw new InvalidArgumentException(
                $operator . ' tests against a keyword, so it compares a column against NULL, TRUE or FALSE'
                . ' rather than against another column; it has no meaning in an ON clause.',
            );
        }
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
