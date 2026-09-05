<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Sloop\Database\CastMode;
use Sloop\Database\ConnectionRoute;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\Paginator;
use Sloop\Database\Result;
use UnexpectedValueException;

/**
 * A SELECT statement being built.
 *
 * Obtained from Connection::select() to read through one connection, or from
 * ConnectionManager::select() to read through a pool's read route; either way
 * what is handed over is a route and the grammar that writes the SQL. The
 * columns are named when the builder is made, and everything after that is
 * added by chaining.
 *
 * ```php
 * $rows = $connection->select('id', 'name')
 *     ->from('users')
 *     ->where('status', 'active')
 *     ->orderBy('created_at', 'DESC')
 *     ->limit(50)
 *     ->execute();
 * ```
 */
class Select extends BuilderWhere
{
    /**
     * Columns to select; empty selects every column.
     *
     * Keyed rather than a list because PHP hands a variadic whatever keys the
     * call produced. SelectSpec discards them when the statement is compiled,
     * so they never reach the SQL.
     *
     * @var array<array-key, string|Expression>
     */
    private array $columns;

    /**
     * Table to read from, or null until from() names one.
     *
     * @var string|null
     */
    private ?string $from = null;

    /**
     * Terms of the GROUP BY clause, in the order they were added.
     *
     * @var list<string|Expression>
     */
    private array $groupings = [];

    /**
     * Parts of the HAVING clause, in the order they were added.
     *
     * @var list<WherePart>
     */
    private array $having = [];

    /**
     * Groups opened by havingOpen() and not yet closed.
     *
     * @var int
     */
    private int $openHavingGroups = 0;

    /**
     * Depth of HAVING groups below which the code running now may not close.
     *
     * No HAVING method hands this builder to a callback, but where() and
     * when() do, and what they hand over is this same builder with every
     * HAVING method on it. Without a floor a callback could close a group the
     * chain that called it had opened, and the conditions that chain writes
     * afterwards would land inside the parentheses the callback opened — with
     * the count of parentheses still balanced, so nothing downstream reports
     * it. What changes is not only where the brackets sit but what the clause
     * means: `(a OR b) AND c` written that way reaches the server as
     * `a OR (b AND c)`, which holds for a different set of groups.
     *
     * @var int
     */
    private int $havingFloor = 0;

    /**
     * Whether a callback returned with a HAVING group of its own still open.
     *
     * Remembered rather than raised where it happens, for the reason
     * BuilderWhere gives about the WHERE clause: the failure the callback was
     * already carrying would be replaced by this one.
     *
     * @var bool
     */
    private bool $havingLeftOpenInCallback = false;

    /**
     * How to hold the rows read, or null to hold nothing.
     *
     * @var RowLock|null
     */
    private ?RowLock $lock = null;

    /**
     * Milliseconds this statement may run for, or null to leave it to the session.
     *
     * @var int|null
     */
    private ?int $timeoutMs = null;

    /**
     * Preset converting the values this statement reads, or null to use the connection's.
     *
     * @var CastMode|null
     */
    private ?CastMode $castMode = null;

    /**
     * Start a SELECT over the given columns.
     *
     * @param ConnectionRoute   $route      Route asked for a connection when the statement runs
     * @param Grammar           $grammar    Grammar that turns the collected parts into SQL
     * @param string|Expression ...$columns Columns to select; none selects every column
     */
    public function __construct(ConnectionRoute $route, Grammar $grammar, string|Expression ...$columns)
    {
        parent::__construct($route, $grammar);

        $this->columns = $columns;
    }

    /**
     * Name the table to read from.
     *
     * @param  string $table Table name, optionally schema qualified
     * @return static This builder
     */
    public function from(string $table): static
    {
        $this->from = $table;

        return $this;
    }

    /**
     * Add a column written as SQL to the select list.
     *
     * What is given goes into the statement as written, as with whereRaw();
     * values belong in the bindings.
     *
     * @param  string                   $sql      SQL of the column, with `?` where its values go
     * @param  array<int|string, mixed> $bindings Values for the placeholders, in order
     * @return static                   This builder
     * @throws InvalidArgumentException When the bindings are not a list
     */
    public function selectRaw(string $sql, array $bindings = []): static
    {
        $this->columns[] = Expression::of($sql, $bindings);

        return $this;
    }

    /**
     * Join a table, keeping only the rows that the ON clause pairs.
     *
     * The join becomes the one that on() and the methods beside it add
     * conditions to, until the next join() starts another. A join carries at
     * least one condition: one without any pairs every row with every other,
     * so the statement is refused when it is compiled rather than run.
     *
     * @param  string         $table Table to join, optionally schema qualified
     * @return static         This builder
     * @throws LogicException When the join before this one left a group of ON conditions open
     */
    public function join(string $table): static
    {
        return $this->addJoin(JoinType::Inner, $table);
    }

    /**
     * Join a table, keeping every row read so far whether it pairs or not.
     *
     * Columns of the joined table read back as null for a row that found no
     * match.
     *
     * @param  string         $table Table to join, optionally schema qualified
     * @return static         This builder
     * @throws LogicException When the join before this one left a group of ON conditions open
     */
    public function leftJoin(string $table): static
    {
        return $this->addJoin(JoinType::Left, $table);
    }

    /**
     * Join a table, keeping every row of it whether it pairs or not.
     *
     * @param  string         $table Table to join, optionally schema qualified
     * @return static         This builder
     * @throws LogicException When the join before this one left a group of ON conditions open
     */
    public function rightJoin(string $table): static
    {
        return $this->addJoin(JoinType::Right, $table);
    }

    /**
     * Pair rows on a comparison between two columns.
     *
     * Called with two arguments the second one is the column to compare
     * against and the comparison is `=`; called with three the second one is
     * the operator.
     *
     * Both sides name columns: an ON clause pairs rows by comparing what they
     * hold, so a string here is read as an identifier and never as a value. A
     * comparison against a value belongs in where(), which keeps it out of the
     * pairing; where the value has to take part in the pairing itself — the
     * usual reason being a left join that would otherwise lose its unmatched
     * rows — write it as an Expression carrying its own binding, as in
     * `Expression::of('?', [$status])`.
     *
     * @param  string|Expression        $column    Column on the left, or an expression standing in for one
     * @param  string|Expression        $operator  Operator when a column follows, otherwise the column to compare against
     * @param  string|Expression|null   $reference Column on the right, when an operator was given
     * @return static                   This builder
     * @throws LogicException           When no join has been started
     * @throws InvalidArgumentException When the operator is not a supported comparison, or reads a keyword rather than a column
     */
    public function on(
        string|Expression $column,
        string|Expression $operator,
        string|Expression|null $reference = null,
    ): static {
        return $this->addOn(Conjunction::And, $column, $operator, $reference, \func_num_args());
    }

    /**
     * Pair rows on a comparison, joined to the one before it with AND.
     *
     * Same as on(); spelled out for a chain that reads better with the
     * conjunction named at every step.
     *
     * @param  string|Expression        $column    Column on the left, or an expression standing in for one
     * @param  string|Expression        $operator  Operator when a column follows, otherwise the column to compare against
     * @param  string|Expression|null   $reference Column on the right, when an operator was given
     * @return static                   This builder
     * @throws LogicException           When no join has been started
     * @throws InvalidArgumentException When the operator is not a supported comparison, or reads a keyword rather than a column
     */
    public function andOn(
        string|Expression $column,
        string|Expression $operator,
        string|Expression|null $reference = null,
    ): static {
        return $this->addOn(Conjunction::And, $column, $operator, $reference, \func_num_args());
    }

    /**
     * Pair rows on a comparison, joined to the one before it with OR.
     *
     * MySQL binds AND tighter than OR, so a comparison added after this one
     * joins to it rather than to the clause as a whole. Where the alternative
     * has to stay one whatever follows, open a group with orOnOpen().
     *
     * @param  string|Expression        $column    Column on the left, or an expression standing in for one
     * @param  string|Expression        $operator  Operator when a column follows, otherwise the column to compare against
     * @param  string|Expression|null   $reference Column on the right, when an operator was given
     * @return static                   This builder
     * @throws LogicException           When no join has been started
     * @throws InvalidArgumentException When the operator is not a supported comparison, or reads a keyword rather than a column
     */
    public function orOn(
        string|Expression $column,
        string|Expression $operator,
        string|Expression|null $reference = null,
    ): static {
        return $this->addOn(Conjunction::Or, $column, $operator, $reference, \func_num_args());
    }

    /**
     * Open a group of ON conditions, joined to what precedes it with AND.
     *
     * Everything added until the matching onClose() goes inside the
     * parentheses. The group belongs to the join being written now: the next
     * join() refuses to start while one is open.
     *
     * @return static         This builder
     * @throws LogicException When no join has been started
     */
    public function onOpen(): static
    {
        return $this->openOnGroup(Conjunction::And);
    }

    /**
     * Open a group of ON conditions, joined to what precedes it with AND.
     *
     * Same as onOpen(); spelled out for a chain that names the conjunction at
     * every step.
     *
     * @return static         This builder
     * @throws LogicException When no join has been started
     */
    public function andOnOpen(): static
    {
        return $this->openOnGroup(Conjunction::And);
    }

    /**
     * Open a group of ON conditions, joined to what precedes it with OR.
     *
     * @return static         This builder
     * @throws LogicException When no join has been started
     */
    public function orOnOpen(): static
    {
        return $this->openOnGroup(Conjunction::Or);
    }

    /**
     * Close the group of ON conditions opened last.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function onClose(): static
    {
        return $this->closeOnGroup();
    }

    /**
     * Close the group of ON conditions opened last.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function andOnClose(): static
    {
        return $this->closeOnGroup();
    }

    /**
     * Close the group of ON conditions opened last.
     *
     * The conjunction of a group is decided where it opens, so this reads the
     * same as onClose() and exists to end a chain that opened with orOnOpen()
     * in the matching spelling.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function orOnClose(): static
    {
        return $this->closeOnGroup();
    }

    /**
     * Fold the matching rows into one row per distinct set of these terms.
     *
     * The terms are added to whatever earlier calls collected, so grouping by
     * two columns can be written as one call or as two. Calling this with
     * nothing adds nothing, which lets a set of terms built up at runtime be
     * handed over without the caller checking whether anything ended up in it.
     *
     * A term may be an Expression where the grouping is over something the
     * grammar has no words for, such as `Expression::of('YEAR(created_at)')`.
     *
     * No sort direction is taken. MySQL 8.0 removed `GROUP BY x DESC` and
     * answers a statement carrying it with a syntax error while MariaDB still
     * takes it, so a builder offering the direction would compile on one server
     * and not on the other. Sort the groups with orderBy().
     *
     * @param  string|Expression ...$columns Columns to group by, or expressions standing in for them
     * @return static            This builder
     */
    public function groupBy(string|Expression ...$columns): static
    {
        foreach ($columns as $column) {
            $this->groupings[] = $column;
        }

        return $this;
    }

    /**
     * Narrow the groups down to those the comparison holds for.
     *
     * Called with two arguments the second one is the value and the comparison
     * is `=`; called with three the second one is the operator.
     *
     * Where a WHERE clause picks the rows that go into the groups, this picks
     * the groups themselves, so the left-hand side is usually an aggregate
     * written as an Expression: `having(Expression::of('COUNT(*)'), '>', 5)`. A
     * plain string is read as a column, and the server takes only one it was
     * told to group by.
     *
     * Without a GROUP BY the rows form a single group, which both servers
     * accept, so this can narrow an aggregate taken over the whole table.
     *
     * @param  string|Expression                     $column   Column or aggregate to compare
     * @param  string|int|float|bool|Expression|null $operator Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null $value    Value to compare against, when an operator was given
     * @return static                                This builder
     * @throws InvalidArgumentException              When a column stands alone, the operator is not a string or a supported comparison, or null stands where the operator cannot read it
     */
    public function having(
        string|Expression $column,
        string|int|float|bool|Expression|null $operator = null,
        string|int|float|bool|Expression|null $value = null,
    ): static {
        return $this->addHaving(Conjunction::And, $column, $operator, $value, \func_num_args());
    }

    /**
     * Narrow the groups on a comparison, joined to the one before it with AND.
     *
     * Same as having(); spelled out for a chain that reads better with the
     * conjunction named.
     *
     * @param  string|Expression                     $column   Column or aggregate to compare
     * @param  string|int|float|bool|Expression|null $operator Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null $value    Value to compare against, when an operator was given
     * @return static                                This builder
     * @throws InvalidArgumentException              When a column stands alone, the operator is not a string or a supported comparison, or null stands where the operator cannot read it
     */
    public function andHaving(
        string|Expression $column,
        string|int|float|bool|Expression|null $operator = null,
        string|int|float|bool|Expression|null $value = null,
    ): static {
        return $this->addHaving(Conjunction::And, $column, $operator, $value, \func_num_args());
    }

    /**
     * Narrow the groups on a comparison, joined to the one before it with OR.
     *
     * @param  string|Expression                     $column   Column or aggregate to compare
     * @param  string|int|float|bool|Expression|null $operator Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null $value    Value to compare against, when an operator was given
     * @return static                                This builder
     * @throws InvalidArgumentException              When a column stands alone, the operator is not a string or a supported comparison, or null stands where the operator cannot read it
     */
    public function orHaving(
        string|Expression $column,
        string|int|float|bool|Expression|null $operator = null,
        string|int|float|bool|Expression|null $value = null,
    ): static {
        return $this->addHaving(Conjunction::Or, $column, $operator, $value, \func_num_args());
    }

    /**
     * Narrow the groups on a condition written as SQL.
     *
     * What is given here goes into the statement as written, for the reason
     * whereRaw() gives. Values belong in the bindings.
     *
     * @param  string                   $sql      SQL of the condition, with `?` where its values go
     * @param  array<int|string, mixed> $bindings Values for the placeholders, in order
     * @return static                   This builder
     * @throws InvalidArgumentException When the bindings are not a list
     */
    public function havingRaw(string $sql, array $bindings = []): static
    {
        $this->having[] = new RawCondition(Expression::of($sql, $bindings), Conjunction::And);

        return $this;
    }

    /**
     * Narrow the groups on a condition written as SQL, joined with OR.
     *
     * @param  string                   $sql      SQL of the condition, with `?` where its values go
     * @param  array<int|string, mixed> $bindings Values for the placeholders, in order
     * @return static                   This builder
     * @throws InvalidArgumentException When the bindings are not a list
     */
    public function orHavingRaw(string $sql, array $bindings = []): static
    {
        $this->having[] = new RawCondition(Expression::of($sql, $bindings), Conjunction::Or);

        return $this;
    }

    /**
     * Open a group of HAVING conditions, joined to what precedes it with AND.
     *
     * Everything added until the matching havingClose() goes inside the
     * parentheses, as whereOpen() describes for the WHERE clause.
     *
     * @return static This builder
     */
    public function havingOpen(): static
    {
        return $this->openHavingGroup(Conjunction::And);
    }

    /**
     * Open a group of HAVING conditions, joined to what precedes it with AND.
     *
     * Same as havingOpen(); spelled out for a chain that reads better with the
     * conjunction named.
     *
     * @return static This builder
     */
    public function andHavingOpen(): static
    {
        return $this->openHavingGroup(Conjunction::And);
    }

    /**
     * Open a group of HAVING conditions, joined to what precedes it with OR.
     *
     * @return static This builder
     */
    public function orHavingOpen(): static
    {
        return $this->openHavingGroup(Conjunction::Or);
    }

    /**
     * Close the group of HAVING conditions opened last.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function havingClose(): static
    {
        return $this->closeHavingGroup();
    }

    /**
     * Close the group of HAVING conditions opened last.
     *
     * Same as havingClose(). How the group joins to what precedes it was
     * settled when it was opened, so naming the conjunction here only reads
     * back what the chain already said.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function andHavingClose(): static
    {
        return $this->closeHavingGroup();
    }

    /**
     * Close the group of HAVING conditions opened last.
     *
     * Same as havingClose(), for the reason andHavingClose() gives.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function orHavingClose(): static
    {
        return $this->closeHavingGroup();
    }

    /**
     * Hold every row this statement reads against reading and writing by others.
     *
     * The lock lasts as long as the transaction that took it, so this only
     * holds anything when the statement runs inside one; outside a transaction
     * the statement commits as it finishes and the lock goes with it. What
     * counts is whether the server has a transaction open, which is not always
     * what Connection::inTransaction() reports — a connection configured with
     * PDO::ATTR_AUTOCOMMIT off is in one from the start — so this is left to
     * the caller rather than checked here.
     *
     * Where the statement runs is the route's answer, so a builder started from
     * ConnectionManager::select() can take its lock on a replica when no
     * transaction is open on the primary; execute() describes that routing.
     *
     * The two arguments say what to do about a row someone else already holds.
     * They are alternatives rather than a pair: MySQL and MariaDB both reject a
     * statement asking for both.
     *
     * NOWAIT reaches the caller as a different exception on each server, and on
     * MariaDB that difference changes control flow: it reports the failure with
     * the code it uses for an ordinary lock wait, so transaction() counts it as
     * retryable and runs the callback again. Asking not to wait and then being
     * retried is the opposite of the intent, so pair NOWAIT with maxAttempts 1.
     * count() refuses NOWAIT outright, for the reason given there. That refusal
     * covers the statement count() writes, not the server behaviour behind it:
     * a COUNT written by hand through selectRaw() can reach the same swallowed
     * abort with nothing in the way. Which select lists do so is a property of
     * the server rather than of this class, and count() writes only one of
     * them, so the refusal is no wider than count().
     *
     * @param  bool                     $skipLocked Leave out the rows already held instead of waiting
     * @param  bool                     $noWait     Fail instead of waiting when a row is already held
     * @return static                   This builder
     * @throws InvalidArgumentException When both alternatives are asked for
     */
    public function forUpdate(bool $skipLocked = false, bool $noWait = false): static
    {
        if ($skipLocked && $noWait) {
            throw new InvalidArgumentException(
                'SKIP LOCKED and NOWAIT each say what to do about a row that is already locked, '
                    . 'so a statement takes one or the other.',
            );
        }

        $this->lock = match (true) {
            $skipLocked => RowLock::UpdateSkipLocked,
            $noWait     => RowLock::UpdateNoWait,
            default     => RowLock::Update,
        };

        return $this;
    }

    /**
     * Hold every row this statement reads against writing by others.
     *
     * Others can still read the rows and take the same lock. As with
     * forUpdate(), the lock lasts as long as the transaction that took it, and
     * where it runs is the route's answer rather than where the builder was
     * made.
     *
     * Landing on a replica matters more here than it does for forUpdate(). A
     * server kept read only refuses FOR UPDATE outright, so a misrouted
     * exclusive lock says so; it accepts LOCK IN SHARE MODE, so a misrouted
     * shared lock is taken on the wrong server and nothing reports it. Start
     * from Connection::select() when the lock has to be held where the writes
     * go.
     *
     * @return static This builder
     */
    public function sharedLock(): static
    {
        $this->lock = RowLock::Shared;

        return $this;
    }

    /**
     * Give the server a limit on how long to spend running this statement.
     *
     * Where the pool is configured with a timeout of its own, the one given
     * here is what applies to this statement. The limit is on the server's
     * side of the call, so it is the server that gives up. Whether it also
     * reports having done so is not something to rely on: both flavors were
     * measured staying silent, and a statement that was cut short can come
     * back as though it had finished. The database guide has the
     * measurements, and what to do instead.
     *
     * The limit belongs to the builder rather than to one way of running it,
     * so the shortcuts carry it too: count() and first() are limited by a
     * timeout set before them just as execute() is.
     *
     * Nothing about it appears in toSql() or toRawSql(). The limit is written
     * in when the statement is handed to a connection, since only then is it
     * known which server it is going to, and the two write it differently.
     *
     * @param  int                      $ms Milliseconds the statement may run for
     * @return static                   This builder
     * @throws InvalidArgumentException When the count of milliseconds is not positive
     */
    public function timeout(int $ms): static
    {
        if ($ms < 1) {
            throw new InvalidArgumentException(
                'A timeout is a count of milliseconds to run for, so it starts at 1; got ' . $ms
                    . '. Both servers read a zero as no limit at all, which is what leaving it unset already says.',
            );
        }

        $this->timeoutMs = $ms;

        return $this;
    }

    /**
     * Convert the values this statement reads under the given preset.
     *
     * What a pool is configured with applies to every statement on it; this
     * changes one. Passing Off is how a statement opts out of a pool that
     * converts, which a walk by a converted column has to do.
     *
     * The preset belongs to the builder rather than to one way of running it,
     * so the shortcuts carry it too: first() and value() read under it just as
     * execute() does.
     *
     * Nothing about it appears in toSql() or toRawSql(). The values are
     * converted after the rows come back, so there is nothing to write in.
     *
     * @param  CastMode $mode Preset to read this statement under
     * @return static   This builder
     */
    public function castMode(CastMode $mode): static
    {
        $this->castMode = $mode;

        return $this;
    }

    /**
     * Write this statement as SQL together with the values its placeholders need.
     *
     * @return CompiledSql
     * @throws LogicException           When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException When an identifier is malformed or the row window is inconsistent
     */
    public function compile(): CompiledSql
    {
        return $this->compileReading($this->columns, $this->limit, $this->offset);
    }

    /**
     * Write this statement as SQL, reading the given columns and row count.
     *
     * The shortcuts below each want a statement that differs from the one the
     * builder describes — fewer columns, one row instead of all of them, or a
     * condition and a sort of their own. They pass what they need here rather
     * than changing the builder, so calling a shortcut leaves the builder able
     * to run its own statement.
     *
     * An extra condition is joined to everything the builder collected as a
     * whole rather than appended to the end of it. Appending would put it
     * beside the last condition, and AND binds tighter than OR, so a WHERE the
     * caller wrote as `a OR b` would come back meaning `a OR (b AND extra)`.
     * The parentheses that avoid that are dropped again when the builder holds
     * no conditions, since an empty pair is not valid SQL.
     *
     * @param  array<array-key, string|Expression> $columns   Columns to read
     * @param  int|null                            $limit     Most rows to read, or null for all of them
     * @param  int|null                            $offset    Rows to skip first, or null to start at the top
     * @param  WherePart|null                      $alsoWhere Condition to require alongside the builder's own, or null for none
     * @param  Order|null                          $thenBy    Sort term to add after the builder's own, or null for none
     * @return CompiledSql
     * @throws LogicException                      When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException            When an identifier is malformed or the row window is inconsistent
     */
    private function compileReading(
        array $columns,
        ?int $limit,
        ?int $offset,
        ?WherePart $alsoWhere = null,
        ?Order $thenBy = null,
    ): CompiledSql {
        // Held rather than read again at the end: the guards below are methods,
        // and calling one declared on this class rather than inherited loses
        // what the check above established about the property.
        $from = $this->from
            ?? throw new LogicException('A SELECT reads from a table; call from() before compiling the statement.');

        $this->requireGroupsClosed();
        $this->requireJoinsUsable();
        $this->requireHavingGroupsClosed();

        $conditions = $alsoWhere === null ? $this->conditions : [
            new GroupBoundary(GroupEdge::Open),
            ...$this->conditions,
            new GroupBoundary(GroupEdge::Close),
            $alsoWhere,
        ];

        return $this->grammar->compileSelect(new SelectSpec(
            from:       $from,
            columns:    $columns,
            joins:      $this->joins,
            conditions: $conditions,
            groupings:  $this->groupings,
            having:     $this->having,
            orders:     $thenBy === null ? $this->orders : [...$this->orders, $thenBy],
            limit:      $limit,
            offset:     $offset,
            lock:       $this->lock,
        ));
    }

    /**
     * Run a statement that reads the given columns and row count.
     *
     * @param  array<array-key, string|Expression> $columns   Columns to read
     * @param  int|null                            $limit     Most rows to read, or null for all of them
     * @param  int|null                            $offset    Rows to skip first, or null to start at the top
     * @param  WherePart|null                      $alsoWhere Condition to require alongside the builder's own, or null for none
     * @param  Order|null                          $thenBy    Sort term to add after the builder's own, or null for none
     * @return Result                              Rows the statement read
     * @throws LogicException                      When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException            When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException              When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException         When the connection cannot be obtained
     * @throws DatabaseException                   When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     * @throws UnexpectedValueException            When the driver returns a value outside the types it contracts to
     */
    private function runReading(
        array $columns,
        ?int $limit,
        ?int $offset,
        ?WherePart $alsoWhere = null,
        ?Order $thenBy = null,
    ): Result {
        $compiled = $this->compileReading($columns, $limit, $offset, $alsoWhere, $thenBy);

        return $this->route->connection()->query($compiled->sql, $compiled->bindings, $this->timeoutMs, $this->castMode);
    }

    /**
     * Run this statement and return the rows it read.
     *
     * The connection is asked for here rather than when the builder was made,
     * so where this runs is whatever the route answers now. A builder started
     * from a connection stays on it; one started from ConnectionManager::select()
     * can land on either route, which that method describes.
     *
     * @return Result                      Rows the statement read
     * @throws LogicException              When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException    When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     * @throws UnexpectedValueException    When the driver returns a value outside the types it contracts to
     */
    public function execute(): Result
    {
        return $this->runReading($this->columns, $this->limit, $this->offset);
    }

    /**
     * Read the first row this statement matches.
     *
     * Asks the server for one row rather than reading every match and keeping
     * the first, so an unbounded statement stays cheap here. Any row window
     * already set is narrowed to that one row; the offset is kept, so
     * offset(1)->first() reads the second row.
     *
     * @return array<array-key, int|float|string|bool|DateTimeImmutable|null>|null The row, or null when nothing matched
     * @throws LogicException                                                      When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException                                            When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException                                              When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException                                         When the connection cannot be obtained
     * @throws DatabaseException                                                   When the statement fails
     * @throws UnexpectedValueException                                            When the driver returns a value outside the types it contracts to
     */
    public function first(): ?array
    {
        return $this->runReading($this->columns, 1, $this->offset)->first();
    }

    /**
     * Read one column of the first row this statement matches.
     *
     * The select list is replaced by the named column, so nothing else is sent
     * back over the wire. The row window is treated as first() treats it: the
     * limit is narrowed to one row and the offset is kept, so
     * offset(1)->value('name') reads the second row's name.
     *
     * @param  string                                       $column Column to read
     * @return int|float|string|bool|DateTimeImmutable|null Its value, or null when nothing matched
     * @throws LogicException                               When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException                     When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException                       When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException                  When the connection cannot be obtained
     * @throws DatabaseException                            When the statement fails
     * @throws UnexpectedValueException                     When the driver returns a value outside the types it contracts to
     */
    public function value(string $column): int|float|string|bool|DateTimeImmutable|null
    {
        $row = $this->runReading([$column], 1, $this->offset)->first();

        // The select list held one column, so the row holds one value. Reading
        // it by position rather than by name keeps this working for a column
        // written as `users`.`name`, which comes back keyed as name. A row that
        // was never read stands in as an empty one and falls through to null.
        return array_values($row ?? [])[0] ?? null;
    }

    /**
     * Read every row this statement matches, as an array.
     *
     * The same rows execute() would return, without going through Result.
     *
     * @return list<array<array-key, int|float|string|bool|DateTimeImmutable|null>> Rows in the order they were read
     * @throws LogicException                                                       When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException                                             When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException                                               When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException                                          When the connection cannot be obtained
     * @throws DatabaseException                                                    When the statement fails
     * @throws UnexpectedValueException                                             When the driver returns a value outside the types it contracts to
     */
    public function get(): array
    {
        return $this->execute()->asArray();
    }

    /**
     * Count the rows this statement matches.
     *
     * The select list is replaced by COUNT(*), so the server counts rather
     * than sending the rows back, and the row window is dropped. LIMIT and
     * OFFSET apply to the single row COUNT(*) produces rather than to the rows
     * counted, so they can only throw that row away: limit(10)->count() would
     * still report every match, while offset(1)->count() would read no row at
     * all. Counting what the conditions match is the only reading of count()
     * the window can serve.
     *
     * A NOWAIT lock is refused rather than counted; requireCountableLock()
     * says why. The other locks are left alone: a plain FOR UPDATE waits and
     * then reports the wait, and SKIP LOCKED counts what it could take.
     *
     * @return int                         How many rows matched
     * @throws LogicException              When no table has been named, a group of conditions was left open, or the lock is NOWAIT
     * @throws InvalidArgumentException    When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails
     * @throws UnexpectedValueException    When the driver returns a value outside the types it contracts to, or a count that is not an integer
     */
    public function count(): int
    {
        $this->requireCountableLock('count');
        $this->requireUngrouped('count');

        $row = $this->runReading([Expression::of('COUNT(*)')], null, null)->first();

        $count = array_values($row ?? [])[0] ?? null;

        if (!\is_int($count)) {
            throw new UnexpectedValueException(
                'COUNT(*) returned ' . get_debug_type($count) . ' where an integer was expected.',
            );
        }

        return $count;
    }

    /**
     * Tell whether this statement matches any row.
     *
     * Asks for a single constant row rather than the columns the builder
     * names, so neither the row contents nor the remaining matches are sent.
     * The row window is dropped for the same reason count() drops it: whether
     * the conditions match anything does not depend on which slice of the
     * matches would have been returned.
     *
     * Under SKIP LOCKED this answers whether a row is there for the taking
     * rather than whether one exists: a match another session holds is passed
     * over, so a row that is present reads as absent while it is held.
     *
     * @return bool                        True when at least one row matched
     * @throws LogicException              When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException    When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails
     * @throws UnexpectedValueException    When the driver returns a value outside the types it contracts to
     */
    public function exists(): bool
    {
        return !$this->runReading([Expression::of('1')], 1, null)->isEmpty();
    }

    /**
     * Tell whether this statement matches no row.
     *
     * The negation of exists(), including its reading under SKIP LOCKED.
     *
     * @return bool                        True when nothing matched
     * @throws LogicException              When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException    When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails
     * @throws UnexpectedValueException    When the driver returns a value outside the types it contracts to
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Read one column across every matching row.
     *
     * With a key column, the two columns are read together and the first keys
     * the second; later rows overwrite earlier ones on a repeated key, as
     * Result::asMap() does. The select list is replaced by the columns named
     * here, so a builder that names other columns does not send them.
     *
     * @param  string                                                         $valueColumn Column whose values are returned
     * @param  string|null                                                    $keyColumn   Column whose values key them, or null for a list
     * @return array<array-key, int|float|string|bool|DateTimeImmutable|null> Values, keyed when a key column was given
     * @throws LogicException                                                 When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException                                       When an identifier is malformed, the row window is inconsistent, or a key cannot be an array key
     * @throws InvalidConfigException                                         When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException                                    When the connection cannot be obtained
     * @throws DatabaseException                                              When the statement fails
     * @throws UnexpectedValueException                                       When the driver returns a value outside the types it contracts to
     */
    public function pluck(string $valueColumn, ?string $keyColumn = null): array
    {
        $columns = $keyColumn === null ? [$valueColumn] : [$keyColumn, $valueColumn];
        $rows    = $this->runReading($columns, $this->limit, $this->offset)->asArray();

        $plucked = [];

        foreach ($rows as $row) {
            // Read by position for the same reason value() does: a column
            // written as `users`.`name` comes back keyed as name.
            $values = array_values($row);

            if ($keyColumn === null) {
                $plucked[] = $values[0] ?? null;

                continue;
            }

            if (!\array_key_exists(1, $values)) {
                // The server labelled both columns the same, so the driver
                // folded them into one and there is no value left to key.
                throw new InvalidArgumentException(
                    'Columns "' . $keyColumn . '" and "' . $valueColumn
                        . '" came back under one name, so there is no value to key.',
                );
            }

            $key = $values[0];

            if (!\is_int($key) && !\is_string($key)) {
                throw new InvalidArgumentException(
                    'Column "' . $keyColumn . '" holds ' . get_debug_type($key) . ', which cannot key an array.',
                );
            }

            $plucked[$key] = $values[1];
        }

        return $plucked;
    }

    /**
     * Read one page of rows, and count how many there are in all.
     *
     * Two statements go out: the page, and a COUNT over the same conditions.
     * They cannot be one, because the count has to see past the window the page
     * is cut from. Rows written between the two are therefore in one number and
     * not the other; Paginator says what that means for the page numbers it
     * works out.
     *
     * @param  int                         $perPage Most rows the page carries
     * @param  int                         $page    1-based number of the page to read
     * @return Paginator                   The page, with the size of the set it came from
     * @throws LogicException              When a row window is already set, no table has been named, a group of conditions was left open, or the rows are held with NOWAIT
     * @throws InvalidArgumentException    When the page size or number is below one, an identifier is malformed, or the row window is inconsistent
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When either statement fails
     * @throws UnexpectedValueException    When the driver returns a value outside the types it contracts to
     */
    public function paginate(int $perPage, int $page): Paginator
    {
        $this->requireNoRowWindow('paginate');

        if ($perPage < 1) {
            throw new InvalidArgumentException('paginate() reads at least one row per page, got ' . $perPage . '.');
        }

        if ($page < 1) {
            throw new InvalidArgumentException('paginate() counts pages from 1, got ' . $page . '.');
        }

        // Asked before the page goes out rather than left to count(): the page
        // would otherwise take its locks and only then be told the count cannot
        // be had, leaving the caller holding rows for a statement that failed.
        $this->requireCountableLock('paginate');
        $this->requireUngrouped('paginate');

        $items = $this->runReading($this->columns, $perPage, ($page - 1) * $perPage);

        // count() rather than a COUNT written here, so the check that the
        // server answered with an integer is not two things that have to agree.
        return new Paginator($items, $this->count(), $perPage, $page);
    }

    /**
     * Walk the matching rows a batch at a time, cut by position.
     *
     * Each batch is a statement of its own reading the next $size rows, so the
     * whole set never has to be held at once. What the batches are is decided
     * per statement rather than up front: a row written or removed while the
     * walk is running shifts every later position, so the same row can be seen
     * twice or missed. chunkById() does not have that problem and is the one to
     * reach for unless the order has to be the builder's own.
     *
     * The callback is given the batch and its 0-based number, and returning
     * false from it stops the walk.
     *
     * @param  int                         $size     Most rows a batch carries
     * @param  callable                    $callback Given (Result $batch, int $index); returning false stops the walk
     * @return bool                        False when the callback stopped the walk, true when the rows ran out
     * @throws LogicException              When a row window is already set, no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException    When the batch size is below one, an identifier is malformed, or the row window is inconsistent
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When a statement fails
     * @throws UnexpectedValueException    When the driver returns a value outside the types it contracts to
     */
    public function chunk(int $size, callable $callback): bool
    {
        $this->requireNoRowWindow('chunk');
        $this->requireBatchSize($size);

        $offset = 0;
        $index  = 0;

        while (true) {
            $batch = $this->runReading($this->columns, $size, $offset);

            if ($batch->isEmpty()) {
                return true;
            }

            if ($callback($batch, $index) === false) {
                return false;
            }

            if ($batch->count() < $size) {
                // The server had nothing more to give, so asking again would
                // only cost a round trip to learn the same thing.
                return true;
            }

            $offset += $size;
            $index++;
        }
    }

    /**
     * Walk the matching rows a batch at a time, cut by a column's value.
     *
     * Each batch asks for the rows whose $column is above the highest one the
     * last batch saw, sorted by that column. Nothing is addressed by position,
     * so rows written or removed while the walk is running do not shift the
     * batches that follow — which is why this is the one to reach for on a set
     * being written to.
     *
     * The column has to hold a distinct value per row for the walk to be
     * complete: rows sharing the value the batch ended on are above nothing and
     * are stepped over. A primary key is the usual choice, and is the default.
     *
     * A statement that groups its rows is refused: the cursor is a condition,
     * which the server reads before it groups, so it cuts the rows going into
     * the groups rather than the groups themselves. requireWalkableByCursor()
     * says what that does to the answer. Walk a grouped statement with
     * chunk().
     *
     * The callback is given the batch and its 0-based number, and returning
     * false from it stops the walk.
     *
     * @param  int                         $size     Most rows a batch carries
     * @param  callable                    $callback Given (Result $batch, int $index); returning false stops the walk
     * @param  string                      $column   Column to walk by; has to be selected and to hold a value per row
     * @return bool                        False when the callback stopped the walk, true when the rows ran out
     * @throws LogicException              When a row window or a sort is already set, no table has been named, a group of conditions was left open, or the statement groups its rows
     * @throws InvalidArgumentException    When the batch size is below one, or an identifier is malformed
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When a statement fails
     * @throws UnexpectedValueException    When the walked column is absent from the rows or holds no value, or the driver returns a value outside the types it contracts to
     */
    public function chunkById(int $size, callable $callback, string $column = 'id'): bool
    {
        $this->requireNoRowWindow('chunkById');
        $this->requireBatchSize($size);
        $this->requireWalkableByCursor();

        if ($this->orders !== []) {
            throw new LogicException(
                'chunkById() sorts by "' . $column . '" to walk the rows, so a sort already on the builder'
                    . ' would either come first and break the walk, or come second and never be reached.'
                    . ' Drop the orderBy() call, or walk the rows with chunk().',
            );
        }

        $above = null;
        $index = 0;

        while (true) {
            $batch = $this->runReading(
                $this->columns,
                $size,
                null,
                $above === null ? null : $this->grammar->comparison($column, '>', $above),
                new Order($column),
            );

            $rows = $batch->asArray();

            if ($rows === []) {
                return true;
            }

            // Read before the callback rather than after it: a column that was
            // never selected is a mistake in the chain, and finding it out only
            // once a batch has been handed over means the callback has already
            // done its work for a walk that cannot go on.
            $above = $this->valueWalked($rows[array_key_last($rows)], $column);

            if ($callback($batch, $index) === false) {
                return false;
            }

            if (\count($rows) < $size) {
                return true;
            }

            $index++;
        }
    }

    /**
     * Read the value the next batch has to start above.
     *
     * Given the last row of the batch, which the sort put highest. A row
     * without the column, or with no value in it, would leave the walk with
     * nothing to compare against and every later batch empty, so neither is
     * passed over quietly.
     *
     * @param  array<array-key, int|float|string|bool|DateTimeImmutable|null> $row    Last row of the batch just walked
     * @param  string                                                         $column Column being walked by
     * @return int|float|string                                               Highest value the batch reached
     * @throws UnexpectedValueException                                       When the column is absent from the row, or holds null
     */
    private function valueWalked(array $row, string $column): int|float|string
    {
        // A column written as `users`.`id` comes back keyed as id, the same
        // reason value() and pluck() do not look it up as written.
        $position = strrpos($column, '.');
        $key      = $position === false ? $column : substr($column, $position + 1);

        if (!\array_key_exists($key, $row)) {
            throw new UnexpectedValueException(
                'chunkById() walks by "' . $column . '", which is not among the columns read ('
                    . implode(', ', array_keys($row)) . '). Select it, or walk by one of them.',
            );
        }

        $value = $row[$key];

        if ($value === null) {
            throw new UnexpectedValueException(
                'chunkById() walks by "' . $column . '", and a row came back with no value in it. Nothing'
                    . ' compares above null, so the walk would stop there and leave the rest unseen. Walk by'
                    . ' a column that holds a value per row.',
            );
        }

        // The cursor is bound into the next statement, so it has to be a value
        // PDO can bind. A cast mode turns some columns into objects and bools,
        // which the walk cannot carry from one batch to the next.
        if (!\is_int($value) && !\is_float($value) && !\is_string($value)) {
            throw new UnexpectedValueException(
                'chunkById() walks by "' . $column . '", and the cast mode in effect turns it into '
                    . get_debug_type($value) . '. The walk binds this value into the statement that reads the'
                    . ' next batch, so it has to be a number or a string. Walk by a column the casts leave'
                    . ' alone, or read this statement under CastMode::Off.',
            );
        }

        return $value;
    }

    /**
     * Refuse a walk over a builder that already addresses a slice of the rows.
     *
     * The walks below set the window per batch, so one already on the builder
     * cannot also apply. Dropping it quietly would change which rows are walked
     * without saying so, which is the one outcome worth an exception here.
     *
     * @param  string         $method Name of the walk being asked for
     * @return void
     * @throws LogicException When a limit or an offset is already set
     */
    private function requireNoRowWindow(string $method): void
    {
        if ($this->limit === null && $this->offset === null) {
            return;
        }

        throw new LogicException(
            $method . '() addresses the rows a batch at a time, so it sets the limit and offset itself and'
                . ' cannot keep the ones already on this builder. Drop the limit()/offset() call, or read'
                . ' that slice on its own with execute().',
        );
    }

    /**
     * Hold the HAVING clause at the depth the callback finds it.
     *
     * @return int Floor that was in force before the callback was handed over
     */
    protected function enterCallbackScope(): int
    {
        $outerScope        = $this->havingFloor;
        $this->havingFloor = $this->openHavingGroups;

        return $outerScope;
    }

    /**
     * Put back the depth that stood before the callback was handed over.
     *
     * A group the callback left open is closed for it, so the depth is what it
     * was before it ran and a later close cannot take a group it did not open.
     * The mistake itself is remembered and reported when the statement is
     * compiled.
     *
     * @param  int  $outerScope Floor enterCallbackScope() answered with
     * @return void
     */
    protected function leaveCallbackScope(int $outerScope): void
    {
        $depth = $this->havingFloor;

        $this->havingLeftOpenInCallback = $this->havingLeftOpenInCallback || $this->openHavingGroups !== $depth;
        $this->havingFloor              = $outerScope;

        // Counted down on a local rather than read back off the property each
        // time. Both forms close the same groups, but with the property as the
        // condition, dropping the call inside leaves the loop with nothing to
        // end it, and a mutation that hangs is reported as a timeout instead of
        // as the failure the test below it already names.
        for ($open = $this->openHavingGroups; $open > $depth; $open--) {
            $this->closeHavingGroup();
        }
    }

    /**
     * Record the opening of a group of HAVING conditions.
     *
     * @param  Conjunction $conjunction How the group joins to what precedes it
     * @return static      This builder
     */
    private function openHavingGroup(Conjunction $conjunction): static
    {
        $this->having[] = new GroupBoundary(GroupEdge::Open, $conjunction);
        $this->openHavingGroups++;

        return $this;
    }

    /**
     * Record the closing of the group of HAVING conditions opened last.
     *
     * Refused here rather than when the statement is compiled, for the reason
     * BuilderWhere gives: a close with nothing to close is a mistake in the
     * chain, and the line that made it is still the line being executed.
     *
     * Inside a callback the refusal starts at the depth the callback found, as
     * it does on the WHERE clause. $havingFloor says why.
     *
     * @return static         This builder
     * @throws LogicException When no group is open, or the open group was not opened by the code closing it
     */
    private function closeHavingGroup(): static
    {
        if ($this->openHavingGroups <= $this->havingFloor) {
            throw new LogicException(
                $this->openHavingGroups === 0
                    ? 'No group of HAVING conditions is open, so there is nothing to close.'
                    : 'This group of HAVING conditions was opened outside the callback holding the builder,'
                        . ' so the callback cannot close it.',
            );
        }

        $this->having[] = new GroupBoundary(GroupEdge::Close);
        $this->openHavingGroups--;

        return $this;
    }

    /**
     * Read what the having methods were given and record it.
     *
     * The number of arguments is what tells the two shapes apart rather than
     * the type of the second one, for the reason BuilderWhere gives: a caller
     * comparing against a value that happens to spell an operator means that
     * value.
     *
     * @param  Conjunction                           $conjunction   How what is added joins to what precedes it
     * @param  string|Expression                     $column        Column or aggregate to compare
     * @param  string|int|float|bool|Expression|null $operator      Operator when a value follows, otherwise the value itself
     * @param  string|int|float|bool|Expression|null $value         Value to compare against, when an operator was given
     * @param  int                                   $argumentCount Number of arguments the caller passed
     * @return static                                This builder
     * @throws InvalidArgumentException              When a column stands alone, the operator is not a string or a supported comparison, or null stands where the operator cannot read it
     */
    private function addHaving(
        Conjunction $conjunction,
        string|Expression $column,
        string|int|float|bool|Expression|null $operator,
        string|int|float|bool|Expression|null $value,
        int $argumentCount,
    ): static {
        if ($argumentCount < 2) {
            throw new InvalidArgumentException(
                'A comparison needs something to compare against, but only a column was given.'
                . ' Pass a value, or an operator and a value.',
            );
        }

        if ($argumentCount < 3) {
            $this->having[] = $this->grammar->comparison($column, '=', $operator, $conjunction);

            return $this;
        }

        if (!\is_string($operator)) {
            throw new InvalidArgumentException(
                'A comparison operator must be a string, got ' . get_debug_type($operator) . '.',
            );
        }

        $this->having[] = $this->grammar->comparison($column, $operator, $value, $conjunction);

        return $this;
    }

    /**
     * Refuse a chain that opened a group of HAVING conditions and never closed it.
     *
     * @return void
     * @throws LogicException When a group is still open
     */
    private function requireHavingGroupsClosed(): void
    {
        if ($this->havingLeftOpenInCallback) {
            throw new LogicException(
                'A callback opened a group of HAVING conditions and returned without closing it.'
                . ' Close it inside the callback.',
            );
        }

        if ($this->openHavingGroups > 0) {
            throw new LogicException(
                'A group of HAVING conditions was opened and not closed; call havingClose() '
                . $this->openHavingGroups . ' more time' . ($this->openHavingGroups === 1 ? '' : 's') . '.',
            );
        }
    }

    /**
     * Refuse to count a statement whose rows are folded into groups.
     *
     * COUNT(*) beside a GROUP BY is taken per group, so the server answers with
     * one row for each of them and the first of those rows holds the size of
     * the first group — not the number of groups, and not the number of rows
     * the statement matches. Nothing in that answer says it is one of many, so
     * a caller reading it is told a smaller number with nothing to say it is
     * the wrong one.
     *
     * Counting the groups means wrapping the grouped statement in one that
     * counts its rows, which is a subquery and waits for the builder to be able
     * to write one.
     *
     * A HAVING clause with no GROUP BY folds the rows into a single group,
     * which has the same effect on the count, so it is refused here as well.
     *
     * @param  string         $method Name of the method being asked for
     * @return void
     * @throws LogicException When the statement folds its rows into groups
     */
    private function requireUngrouped(string $method): void
    {
        if (!$this->groupsRows()) {
            return;
        }

        throw new LogicException(
            $method . '() counts the rows a statement matches, but this one groups them, so the server would'
                . ' answer with one count per group and the first of those would be read as the whole.'
                . ' Count the rows of get(), or read the groups and count those.',
        );
    }

    /**
     * Refuse to walk a statement whose rows are folded into groups by a cursor.
     *
     * chunkById() carries its place between batches as a condition on the
     * column it walks, and a condition is read before the rows are grouped.
     * The cursor therefore cuts the rows going into the groups rather than the
     * groups themselves, and a group whose rows fall on both sides of the cut
     * is read twice.
     *
     * Walking by a grouping term does not save it. With more than one term the
     * cursor moves past every group sharing the value it stopped at, so a
     * batch boundary inside such a run drops the rest of them: over the groups
     * (a=1,b=1) (a=1,b=2) (a=2,b=1), walking `a` a batch at a time reads two
     * groups where a batch of two reads all three. Which groups come back
     * depends on the batch size, and nothing in the answer says any are
     * missing.
     *
     * MySQL refuses the usual shape of this outright, because walking a column
     * outside the grouping puts it in the ORDER BY as well and its sql_mode
     * carries ONLY_FULL_GROUP_BY. MariaDB's does not, so there it runs and
     * answers with the duplicates.
     *
     * chunk() walks a grouped statement correctly: it cuts with LIMIT and
     * OFFSET, which the server applies to the groups.
     *
     * @return void
     * @throws LogicException When the statement folds its rows into groups
     */
    private function requireWalkableByCursor(): void
    {
        if (!$this->groupsRows()) {
            return;
        }

        throw new LogicException(
            'chunkById() carries its place between batches as a condition, which the server reads before it'
                . ' groups the rows, so a group split across a batch boundary comes back twice and grouping'
                . ' by more than one term drops whole groups. Walk a grouped statement with chunk().',
        );
    }

    /**
     * Tell whether this statement folds its rows into groups.
     *
     * A HAVING clause with no GROUP BY folds them into a single group, so it
     * counts here as well.
     *
     * @return bool True when the rows reach the caller as groups
     */
    private function groupsRows(): bool
    {
        return $this->groupings !== [] || $this->having !== [];
    }

    /**
     * Refuse a count over rows this statement holds with NOWAIT.
     *
     * MySQL swallows the NOWAIT abort inside a COUNT and answers with the rows
     * it had counted before it reached the held one, so what comes back is a
     * plausible number rather than an error: holding the first row of the scan
     * answers 0, and holding the last answers one less than the true count.
     * Whether the statement takes that path depends on the plan, and a SUM over
     * the same scan does fail, so this is COUNT keeping its own tally rather
     * than aggregates in general. MariaDB reports the failure properly, but the
     * refusal covers both so the same code means the same thing on either.
     *
     * @param  string         $method Name of the method being asked for
     * @return void
     * @throws LogicException When the rows are held with NOWAIT
     */
    private function requireCountableLock(string $method): void
    {
        if ($this->lock !== RowLock::UpdateNoWait) {
            return;
        }

        throw new LogicException(
            $method . '() cannot count rows held with NOWAIT. MySQL swallows the abort inside a COUNT and'
                . ' answers with the rows it reached before the held one, so the number looks ordinary and'
                . ' nothing says it is short. Count the rows of get(), or take the lock without NOWAIT.',
        );
    }

    /**
     * Refuse a batch that could not carry a row.
     *
     * @param  int                      $size Batch size asked for
     * @return void
     * @throws InvalidArgumentException When the size is below one
     */
    private function requireBatchSize(int $size): void
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Batch size must be at least 1, got ' . $size . '.');
        }
    }
}
