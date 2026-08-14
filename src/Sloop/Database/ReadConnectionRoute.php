<?php

declare(strict_types=1);

namespace Sloop\Database;

use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * A route that asks a pool for its read connection each time it is used.
 *
 * ConnectionManager::select() hands this over. Every call goes back through
 * ConnectionManager::connection(writable: false), so what that method decides
 * per call decides where the statement runs — an open transaction on the
 * primary wins over the read route. What it decides once per pool it keeps:
 * the replica is chosen on the first read and reused for the rest of the
 * request, so the selector and the dead-replica cache do not run again here.
 */
final readonly class ReadConnectionRoute implements ConnectionRoute
{
    /**
     * Bind the route to the pool it reads from.
     *
     * @param ConnectionManager $connections Manager owning the pool to read from
     */
    public function __construct(private ConnectionManager $connections)
    {
    }

    /**
     * Ask the pool for the connection its read route currently points at.
     *
     * @return Connection
     * @throws InvalidConfigException      When the default pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When max_connection_attempts is exhausted on the replica path
     * @throws DatabaseException           When a persistent primary carries a residual transaction that cannot be rolled back
     */
    public function connection(): Connection
    {
        return $this->connections->connection(writable: false);
    }
}
