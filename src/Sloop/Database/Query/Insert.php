<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use LogicException;
use Sloop\Database\Connection;
use Sloop\Database\ConnectionRoute;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * An INSERT statement being built.
 *
 * Obtained from Connection::insert() to add rows through one connection, or
 * from ConnectionManager::insert() to add them through a pool's primary. The
 * table is named when the builder is made and the rows are given to values()
 * or to set().
 *
 * ```php
 * $id = $connection->insert('users')
 *     ->set(['name' => 'alice', 'email' => 'alice@example.com'])
 *     ->execute();
 * ```
 *
 * Every row carries the same columns, because MySQL names them once for the
 * whole statement. The first row settles which columns those are, and a later
 * row naming a different set is refused rather than written with a column left
 * to its default.
 *
 * A row that collides with one already there ends the statement, unless
 * upsert() named columns to overwrite instead, or executeIgnore() was used to
 * pass the row over.
 */
class Insert extends Builder
{
    /**
     * Columns being written, settled by the first row and held in its key order.
     *
     * @var list<string>
     */
    protected array $columns = [];

    /**
     * Rows to insert, each holding one value per column in the column order.
     *
     * @var list<list<string|int|float|bool|Expression|null>>
     */
    protected array $rows = [];

    /**
     * Columns to overwrite when a row collides with one already there.
     *
     * Empty until upsert() is called, which is what tells a plain INSERT from
     * one that updates on a collision.
     *
     * @var list<string>
     */
    protected array $upsert = [];

    /**
     * Start an INSERT against the given table.
     *
     * @param ConnectionRoute $route   Route asked for a connection when the statement runs
     * @param Grammar         $grammar Grammar that turns the collected parts into SQL
     * @param string          $into    Table to insert into, optionally schema qualified
     */
    public function __construct(ConnectionRoute $route, Grammar $grammar, private readonly string $into)
    {
        parent::__construct($route, $grammar);
    }

    /**
     * Add one row.
     *
     * Each call adds a row; nothing is merged. That is the opposite of what
     * set() does on an UPDATE, where naming a column again replaces the value
     * it was going to get — an UPDATE writes one row's worth of columns, and an
     * INSERT writes a row per call.
     *
     * @param  array<int|string, mixed> $row Column name to the value to write
     * @return static                   This builder
     * @throws InvalidArgumentException When the row names no column, a key does not name a column, a value cannot be written, or the row names different columns than the first
     */
    public function set(array $row): static
    {
        return $this->addRow($row, \count($this->rows));
    }

    /**
     * Add several rows at once.
     *
     * Takes a list of rows rather than one row: a single row goes to set(),
     * which reads the same and needs no wrapping. Passing one row here would
     * otherwise be told apart from a list of rows by inspecting the values,
     * which is a guess this makes unnecessary.
     *
     * @param  array<int|string, mixed> $rows Rows, each a column-name-to-value array
     * @return static                   This builder
     * @throws InvalidArgumentException When a row is not an array, names no column, has a key that does not name a column, holds a value that cannot be written, or names different columns than the first
     */
    public function values(array $rows): static
    {
        foreach ($rows as $row) {
            $index = \count($this->rows);

            if (!\is_array($row)) {
                throw new InvalidArgumentException(
                    'values() takes a list of rows, so each element must be an array, got '
                    . get_debug_type($row) . ' at index ' . $index . '. Pass one row to set() instead.',
                );
            }

            $this->addRow($row, $index);
        }

        return $this;
    }

    /**
     * Overwrite the named columns when a row collides with one already there.
     *
     * A collision is any unique key the row would duplicate, not a key named
     * here: MySQL and MariaDB both fire ON DUPLICATE KEY UPDATE for whichever
     * unique index the row ran into. There is no way to ask the server to
     * watch only one of them, which is why this takes no list of keys.
     *
     * Each named column is given the value this statement was carrying for it
     * in the colliding row. Writing something else on a collision — keeping
     * the larger of the two scores, say — is not expressible here; that is
     * planned for a later version.
     *
     * Calling this again replaces the columns rather than adding to them.
     *
     * @param  array<int|string, mixed> $update Columns to overwrite, named as they are in the rows
     * @return static                   This builder
     * @throws InvalidArgumentException When the list is empty or a name is not a string
     */
    public function upsert(array $update): static
    {
        if ($update === []) {
            throw new InvalidArgumentException(
                'An upsert names the columns to overwrite on a collision, and this one names none.'
                . ' To let the server pass over a colliding row instead, run the statement with executeIgnore().',
            );
        }

        $this->upsert = ClauseParts::toColumnNames($update);

        return $this;
    }

    /**
     * Write this statement as SQL together with the values its placeholders need.
     *
     * An upsert is written in the form both servers take, which is the one
     * MySQL warns about from 8.0.20 (`VALUES(col)`). Choosing the newer form
     * means knowing which server this is going to, and compiling asks for no
     * connection; execute() knows and does switch. So the text here is what
     * runs anywhere rather than what this particular statement will send.
     *
     * @return CompiledSql
     * @throws LogicException           When no row was given
     * @throws InvalidArgumentException When an identifier is malformed or a column to overwrite is not being written
     */
    public function compile(): CompiledSql
    {
        return $this->compileWith(ignore: false, connection: null);
    }

    /**
     * Run this statement and return the id the server gave the first row.
     *
     * What comes back is the connection's LAST_INSERT_ID(), which for a
     * statement inserting several rows names the first of them: the rest follow
     * it in order where the column is AUTO_INCREMENT. A table without such a
     * column has no id to report and the value is 0.
     *
     * The connection is asked for here rather than when the builder was made,
     * so where this runs is whatever the route answers now. The id is read back
     * from that same connection, since it is that connection's last id and not
     * the server's.
     *
     * Where upsert() named columns, the update clause is written in the form
     * this connection's server takes: the row alias from MySQL 8.0.19, and
     * VALUES() everywhere else. That is why the text sent can differ from what
     * compile() writes, which has no connection to ask.
     *
     * Under an upsert the id names a row the statement touched, which for a
     * single row is the one it wrote or overwrote. Which one it is where
     * several rows were given is the server's to decide and need not be the
     * first of them, so read the rows back rather than working from the id. A
     * collision that left every named column at the value it already held
     * touches no row and reports 0. The cases are measured in docs/ja/database.md.
     *
     * @return int|string                  Id of a row the statement wrote or overwrote, or 0 when there is none to report; see Connection::lastInsertId() for when it is a string
     * @throws LogicException              When no row was given
     * @throws InvalidArgumentException    When an identifier is malformed or a column to overwrite is not being written
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     */
    public function execute(): int|string
    {
        $this->requireRows();

        $connection = $this->route->connection();

        return $this->runOn($connection, $this->compileWith(ignore: false, connection: $connection));
    }

    /**
     * Run this statement as INSERT IGNORE and return the id the server gave the first row.
     *
     * A row the server would refuse for a duplicate key is skipped instead of
     * ending the statement, and the rows around it are still written.
     *
     * IGNORE does more than skip, which is the part worth knowing before
     * reaching for it: a value the column cannot hold is not refused either,
     * and the row is written with that value coerced to fit. A string longer
     * than the column is stored truncated, and null in a NOT NULL column is
     * stored as that column's empty value; both are pinned against MySQL and
     * MariaDB in the integration tests. Under plain execute() the same rows
     * end the statement instead.
     *
     * Both are reported as warnings, which this does not read, so neither what
     * was skipped nor what was coerced is visible in what comes back. Where
     * every row was skipped there is no new id and the value is 0.
     *
     * This and upsert() cannot be combined; see the message for why.
     *
     * @return int|string                  Id of the first row inserted, or 0 when no row was written or the table has no AUTO_INCREMENT column; see Connection::lastInsertId() for when it is a string
     * @throws LogicException              When no row was given, or upsert() named columns to overwrite
     * @throws InvalidArgumentException    When an identifier is malformed
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     */
    public function executeIgnore(): int|string
    {
        if ($this->upsert !== []) {
            throw new LogicException(
                'IGNORE and ON DUPLICATE KEY UPDATE ask for opposite things on a collision, and the servers do not'
                . ' agree on which wins: MySQL 8.0 passes the row over and MariaDB 10.11 applies the update.'
                . ' Run this with execute() to update on a collision, or drop the upsert() call to skip the row.',
            );
        }

        $this->requireRows();

        $connection = $this->route->connection();

        return $this->runOn($connection, $this->compileWith(ignore: true, connection: $connection));
    }

    /**
     * Write this statement as SQL, saying whether the server may skip a row it refuses.
     *
     * Which form the update clause of an upsert takes is settled here, since
     * it is the one part of the statement that depends on the server: with a
     * connection to ask, the newer form goes to a server that reads it, and
     * without one the portable form is written. A statement with nothing to
     * overwrite never asks, so compiling one costs no round trip.
     *
     * @param  bool                     $ignore     Whether to write INSERT IGNORE
     * @param  Connection|null          $connection Connection the statement is headed for, or null when compiling for no server in particular
     * @return CompiledSql              SQL and bindings, the bindings in placeholder order
     * @throws LogicException           When no row was given
     * @throws InvalidArgumentException When an identifier is malformed or a column to overwrite is not being written
     * @throws DatabaseException        When the server version cannot be read
     */
    private function compileWith(bool $ignore, ?Connection $connection): CompiledSql
    {
        $this->requireRows();

        $rowAlias = $this->upsert !== []
            && $connection !== null
            && $this->grammar->supportsRowAlias($connection->dialect(), $connection->serverVersion());

        return $this->grammar->compileInsert(new InsertSpec(
            table:    $this->into,
            columns:  $this->columns,
            rows:     $this->rows,
            ignore:   $ignore,
            upsert:   $this->upsert,
            rowAlias: $rowAlias,
        ));
    }

    /**
     * Run a compiled statement and read back the id it gave the first row.
     *
     * @param  Connection        $connection Connection the route answered with, already asked for by the caller
     * @param  CompiledSql       $compiled   Statement to run
     * @return int|string        Id of the first row inserted, or 0 when there is none to report
     * @throws DatabaseException When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     */
    private function runOn(Connection $connection, CompiledSql $compiled): int|string
    {
        $connection->statement($compiled->sql, $compiled->bindings);

        return $connection->lastInsertId();
    }

    /**
     * Refuse a statement that has no row to write.
     *
     * Called before the route is asked for a connection, so that a statement
     * which cannot be written is reported as such rather than behind whatever
     * obtaining the connection ran into.
     *
     * @return void
     * @throws LogicException When no row was given
     */
    private function requireRows(): void
    {
        if ($this->rows === []) {
            throw new LogicException(
                'An INSERT writes at least one row, and this one carries none; call set() or values() before running it.',
            );
        }
    }

    /**
     * Collect one row, settling the columns from it or checking it against them.
     *
     * @param  array<int|string, mixed> $row   Column name to the value to write
     * @param  int                      $index Position of the row, to name in a message
     * @return static                   This builder
     * @throws InvalidArgumentException When the row names no column, a key does not name a column, a value cannot be written, or the row names different columns than the first
     */
    private function addRow(array $row, int $index): static
    {
        $columns = [];
        $values  = [];

        foreach ($row as $column => $value) {
            if (!\is_string($column)) {
                throw new InvalidArgumentException(
                    'A value names the column it goes into, so its key must be a string, got '
                    . get_debug_type($column) . ' in the row at index ' . $index . '.',
                );
            }

            if ($value !== null && !\is_scalar($value) && !$value instanceof Expression) {
                throw new InvalidArgumentException(
                    'The value of a column must be a scalar, null or an Expression, got '
                    . get_debug_type($value) . ' for column "' . $column . '" in the row at index ' . $index . '.',
                );
            }

            $columns[] = $column;
            $values[]  = $value;
        }

        if ($columns === []) {
            throw new InvalidArgumentException(
                'A row names the columns it writes, and the row at index ' . $index . ' names none.'
                . ' MySQL reads an INSERT with no columns as a row of defaults, which is not what an empty'
                . ' row was likely meant to ask for.',
            );
        }

        if ($this->rows === []) {
            $this->columns = $columns;
        } elseif ($columns !== $this->columns) {
            throw new InvalidArgumentException(
                'Every row of an INSERT writes the same columns in the same order, because they are named once for'
                . ' the whole statement. The first row names ' . self::describe($this->columns)
                . ' and the row at index ' . $index . ' names ' . self::describe($columns) . '.',
            );
        }

        $this->rows[] = $values;

        return $this;
    }

    /**
     * Name a set of columns the way a message reads them.
     *
     * @param  list<string> $columns Column names in the order they were given, never empty
     * @return string       The names quoted and joined
     */
    private static function describe(array $columns): string
    {
        return '"' . implode('", "', $columns) . '"';
    }
}
