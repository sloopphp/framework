<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use LogicException;
use Sloop\Database\Connection;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Result;
use UnexpectedValueException;

/**
 * A SELECT statement being built.
 *
 * Obtained from Connection::select(), which hands over the connection to read
 * through and the grammar that writes the SQL. The columns are fixed when the
 * builder is made; everything after that is added by chaining.
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
     * Keyed rather than a list because a variadic collects named arguments
     * under their names. SelectSpec discards the keys when the statement is
     * compiled, so they never reach the SQL.
     *
     * @var array<array-key, string|Expression>
     */
    private readonly array $columns;

    /**
     * Table to read from, or null until from() names one.
     *
     * @var string|null
     */
    private ?string $from = null;

    /**
     * Start a SELECT over the given columns.
     *
     * @param Connection        $connection Connection the statement runs on
     * @param Grammar           $grammar    Grammar that turns the collected parts into SQL
     * @param string|Expression ...$columns Columns to select; none selects every column
     */
    public function __construct(Connection $connection, Grammar $grammar, string|Expression ...$columns)
    {
        parent::__construct($connection, $grammar);

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
     * Write this statement as SQL together with the values its placeholders need.
     *
     * @return CompiledSql
     * @throws LogicException           When no table has been named
     * @throws InvalidArgumentException When an identifier is malformed or the row window is inconsistent
     */
    public function compile(): CompiledSql
    {
        if ($this->from === null) {
            throw new LogicException('A SELECT reads from a table; call from() before compiling the statement.');
        }

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
     * @return Result                   Rows the statement read
     * @throws LogicException           When no table has been named
     * @throws InvalidArgumentException When an identifier is malformed or the row window is inconsistent
     * @throws DatabaseException        When the statement fails
     * @throws UnexpectedValueException When the driver returns a value outside the types it contracts to
     */
    public function execute(): Result
    {
        $compiled = $this->compile();

        return $this->connection->query($compiled->sql, $compiled->bindings);
    }
}
