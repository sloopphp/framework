<?php

declare(strict_types=1);

namespace Sloop\Database;

/**
 * A route to one connection that was named up front.
 *
 * Connection::select() hands this over, because a statement started from a
 * connection runs on that connection and has nothing left to decide. The
 * indirection still buys something: the builder asks the same way whether it
 * came from a connection or from a pool, so neither the builder nor the reader
 * of its code has to know which one it was.
 */
final readonly class FixedConnectionRoute implements ConnectionRoute
{
    /**
     * Bind the route to the connection every statement on it will use.
     *
     * @param Connection $connection Connection to run on
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Return the connection this route was built with.
     *
     * @return Connection
     */
    public function connection(): Connection
    {
        return $this->connection;
    }
}
