<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use LogicException;
use Sloop\Database\ConnectionRoute;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;
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
     * How to hold the rows read, or null to hold nothing.
     *
     * @var RowLock|null
     */
    private ?RowLock $lock = null;

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
     * a COUNT written by hand through selectRaw() reaches the same swallowed
     * abort with nothing in the way. It has to be the only aggregate in the
     * select list to do so — put a second one beside it and the statement
     * fails as it should — which is why the refusal is no wider than count().
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
     * builder describes — fewer columns, or one row instead of all of them.
     * They pass what they need here rather than changing the builder, so
     * calling a shortcut leaves the builder able to run its own statement.
     *
     * @param  array<array-key, string|Expression> $columns Columns to read
     * @param  int|null                            $limit   Most rows to read, or null for all of them
     * @param  int|null                            $offset  Rows to skip first, or null to start at the top
     * @return CompiledSql
     * @throws LogicException                      When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException            When an identifier is malformed or the row window is inconsistent
     */
    private function compileReading(array $columns, ?int $limit, ?int $offset): CompiledSql
    {
        if ($this->from === null) {
            throw new LogicException('A SELECT reads from a table; call from() before compiling the statement.');
        }

        $this->requireGroupsClosed();

        return $this->grammar->compileSelect(new SelectSpec(
            from:       $this->from,
            columns:    $columns,
            conditions: $this->conditions,
            orders:     $this->orders,
            limit:      $limit,
            offset:     $offset,
            lock:       $this->lock,
        ));
    }

    /**
     * Run a statement that reads the given columns and row count.
     *
     * @param  array<array-key, string|Expression> $columns Columns to read
     * @param  int|null                            $limit   Most rows to read, or null for all of them
     * @param  int|null                            $offset  Rows to skip first, or null to start at the top
     * @return Result                              Rows the statement read
     * @throws LogicException                      When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException            When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException              When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException         When the connection cannot be obtained
     * @throws DatabaseException                   When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     * @throws UnexpectedValueException            When the driver returns a value outside the types it contracts to
     */
    private function runReading(array $columns, ?int $limit, ?int $offset): Result
    {
        $compiled = $this->compileReading($columns, $limit, $offset);

        return $this->route->connection()->query($compiled->sql, $compiled->bindings);
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
     * @return array<array-key, int|float|string|null>|null The row, or null when nothing matched
     * @throws LogicException                               When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException                     When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException                       When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException                  When the connection cannot be obtained
     * @throws DatabaseException                            When the statement fails
     * @throws UnexpectedValueException                     When the driver returns a value outside the types it contracts to
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
     * @param  string                      $column Column to read
     * @return int|float|string|null       Its value, or null when nothing matched
     * @throws LogicException              When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException    When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails
     * @throws UnexpectedValueException    When the driver returns a value outside the types it contracts to
     */
    public function value(string $column): int|float|string|null
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
     * @return list<array<array-key, int|float|string|null>> Rows in the order they were read
     * @throws LogicException                                When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException                      When an identifier is malformed or the row window is inconsistent
     * @throws InvalidConfigException                        When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException                   When the connection cannot be obtained
     * @throws DatabaseException                             When the statement fails
     * @throws UnexpectedValueException                      When the driver returns a value outside the types it contracts to
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
     * A NOWAIT lock is refused here rather than counted. MySQL swallows the
     * NOWAIT abort inside a COUNT and answers with the rows it had counted
     * before it reached the held one, so what comes back is a plausible number
     * rather than an error: holding the first row of the scan answers 0, and
     * holding the last answers one less than the true count. Whether the
     * statement takes that path depends on the plan, and a SUM over the same
     * scan does fail, so this is COUNT keeping its own tally rather than
     * aggregates in general. MariaDB reports the failure properly, but the
     * refusal covers both so the same code means the same thing on either.
     *
     * The other locks are left alone: a plain FOR UPDATE waits and then
     * reports the wait, and SKIP LOCKED counts what it could take.
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
        if ($this->lock === RowLock::UpdateNoWait) {
            throw new LogicException(
                'count() cannot be taken with NOWAIT. MySQL swallows the abort inside a COUNT and '
                    . 'answers with the rows it reached before the held one, so the number looks ordinary '
                    . 'and nothing says it is short. Count the rows of get(), or take the lock without '
                    . 'NOWAIT.',
            );
        }

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
     * @param  string                                  $valueColumn Column whose values are returned
     * @param  string|null                             $keyColumn   Column whose values key them, or null for a list
     * @return array<array-key, int|float|string|null> Values, keyed when a key column was given
     * @throws LogicException                          When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException                When an identifier is malformed, the row window is inconsistent, or a key cannot be an array key
     * @throws InvalidConfigException                  When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException             When the connection cannot be obtained
     * @throws DatabaseException                       When the statement fails
     * @throws UnexpectedValueException                When the driver returns a value outside the types it contracts to
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
}
