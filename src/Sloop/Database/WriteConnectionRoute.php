<?php

declare(strict_types=1);

namespace Sloop\Database;

use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * A route that asks a pool for its primary each time it is used.
 *
 * ConnectionManager::delete() hands this over. A write has no replica to
 * choose between, so what this buys is the same thing the read route does:
 * the connection is decided when the statement runs. A builder made before a
 * transaction opened runs inside it, and one made while a pool was still
 * unconnected does not open anything until it is executed.
 */
final readonly class WriteConnectionRoute implements ConnectionRoute
{
    /**
     * Bind the route to the pool it writes to.
     *
     * @param ConnectionManager $connections Manager owning the pool to write to
     */
    public function __construct(private ConnectionManager $connections)
    {
    }

    /**
     * Ask the pool for its primary connection.
     *
     * @return Connection
     * @throws InvalidConfigException      When the default pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When a persistent primary carries a residual transaction that cannot be rolled back
     */
    public function connection(): Connection
    {
        return $this->connections->connection(writable: true);
    }
}
