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
     * Write this statement as SQL together with the values its placeholders need.
     *
     * @return CompiledSql
     * @throws LogicException           When no table has been named, or a group of conditions was left open
     * @throws InvalidArgumentException When an identifier is malformed or the row window is inconsistent
     */
    public function compile(): CompiledSql
    {
        if ($this->from === null) {
            throw new LogicException('A SELECT reads from a table; call from() before compiling the statement.');
        }

        $this->requireGroupsClosed();

        return $this->grammar->compileSelect(new SelectSpec(
            from:       $this->from,
            columns:    $this->columns,
            conditions: $this->conditions,
            orders:     $this->orders,
            limit:      $this->limit,
            offset:     $this->offset,
        ));
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
        $compiled = $this->compile();

        return $this->route->connection()->query($compiled->sql, $compiled->bindings);
    }
}
