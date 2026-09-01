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
 * DELETE means and the builder writes it as asked, unless the pool sets
 * `strict_mode`, which refuses an unconditioned statement at the point it would
 * run. allowWithoutWhere() says that the whole table is what was meant.
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
     * Say that this statement is meant to address every row.
     *
     * Only has an effect where the connection runs in strict mode, which
     * otherwise refuses a DELETE carrying no WHERE clause. Saying it on a
     * statement that is narrowed anyway changes nothing.
     *
     * The opt-out is per statement rather than per connection so that a batch
     * job can name itself as one without the setting being lifted for
     * everything else running over the same pool.
     *
     * @return static This builder
     */
    public function allowWithoutWhere(): static
    {
        $this->allowWithoutWhere = true;

        return $this;
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
     * @throws LogicException              When a group of conditions was left open, an offset was set, or the connection is in strict mode and nothing narrows the statement
     * @throws InvalidArgumentException    When an identifier is malformed
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     */
    public function execute(): int
    {
        $compiled   = $this->compile();
        $connection = $this->route->connection();

        $this->requireWhereUnderStrictMode($connection, 'DELETE');

        return $connection->statement($compiled->sql, $compiled->bindings);
    }
}
