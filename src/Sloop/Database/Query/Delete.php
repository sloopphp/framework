<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use LogicException;
use Sloop\Database\ConnectionRoute;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * A DELETE statement being built.
 *
 * Obtained from Connection::delete() to remove rows through one connection, or
 * from ConnectionManager::delete() to remove them through a pool's primary.
 * The table is named when the builder is made; the conditions that pick the
 * rows are added by chaining, as they are on a SELECT.
 *
 * ```php
 * $removed = $connection->delete('users')
 *     ->where('status', 'blocked')
 *     ->execute();
 * ```
 *
 * A statement with no conditions removes every row in the table. That is what
 * DELETE means and the builder writes it as asked; the guard that refuses it
 * unless it was asked for deliberately arrives with the `strict_mode` config
 * key.
 */
class Delete extends BuilderWhere
{
    /**
     * Start a DELETE against the given table.
     *
     * @param ConnectionRoute $route   Route asked for a connection when the statement runs
     * @param Grammar         $grammar Grammar that turns the collected parts into SQL
     * @param string          $from    Table to delete from, optionally schema qualified
     */
    public function __construct(ConnectionRoute $route, Grammar $grammar, private readonly string $from)
    {
        parent::__construct($route, $grammar);
    }

    /**
     * Write this statement as SQL together with the values its placeholders need.
     *
     * @return CompiledSql
     * @throws LogicException           When a group of conditions was left open, or an offset was set
     * @throws InvalidArgumentException When an identifier is malformed
     */
    public function compile(): CompiledSql
    {
        $this->requireGroupsClosed();

        if ($this->offset !== null) {
            throw new LogicException(
                'A DELETE takes no offset: MySQL orders the rows and removes the first LIMIT of them,'
                    . ' with nothing to skip past. Narrow the statement with where() instead.',
            );
        }

        return $this->grammar->compileDelete(new DeleteSpec(
            from:       $this->from,
            conditions: $this->conditions,
            orders:     $this->orders,
            limit:      $this->limit,
        ));
    }

    /**
     * Run this statement and return how many rows it removed.
     *
     * The connection is asked for here rather than when the builder was made,
     * so where this runs is whatever the route answers now.
     *
     * @return int                         Rows removed
     * @throws LogicException              When a group of conditions was left open, or an offset was set
     * @throws InvalidArgumentException    When an identifier is malformed
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     */
    public function execute(): int
    {
        $compiled = $this->compile();

        return $this->route->connection()->statement($compiled->sql, $compiled->bindings);
    }
}
