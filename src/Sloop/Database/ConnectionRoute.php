<?php

declare(strict_types=1);

namespace Sloop\Database;

use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * The connection a statement runs on, asked for at the moment it runs.
 *
 * A query builder holds one of these instead of a Connection, so that whoever
 * started the statement keeps the choice until it runs. What gets chosen is up
 * to the implementation: FixedConnectionRoute has nothing to choose, while
 * ReadConnectionRoute asks a pool every time — see ConnectionManager::select()
 * for what that means for a pool with replicas.
 *
 * An implementation must not remember the connection it resolved. Holding one
 * that was named up front is what FixedConnectionRoute does and is fine; what
 * would defeat the seam is answering a later call with an earlier lookup,
 * since the first toRawSql() or execute() would then decide where every later
 * one runs.
 */
interface ConnectionRoute
{
    /**
     * Return the connection to run on now.
     *
     * @return Connection
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When a persistent connection carries a residual transaction that cannot be rolled back
     */
    public function connection(): Connection;
}
