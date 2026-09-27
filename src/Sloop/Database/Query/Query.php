<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use RuntimeException;
use Sloop\Database\ConnectionRoute;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\Result;

/**
 * A statement under construction, and what every statement can do once built.
 *
 * Collecting and writing are kept apart: a builder records what the caller
 * asked for, and a Grammar turns that into text for one dialect. The Grammar
 * arrives from whatever started the statement rather than being created here,
 * so the same builder writes for another dialect by handing it a different one.
 */
abstract class Query
{
    /**
     * Bind a statement to the route it runs over and the grammar that writes it.
     *
     * The route rather than a connection, so that a pool decides between its
     * primary and a replica when the statement runs. Building the statement
     * and compiling it need no connection at all.
     *
     * @param ConnectionRoute $route   Route asked for a connection when the statement runs
     * @param Grammar         $grammar Grammar that turns the collected parts into SQL
     */
    public function __construct(
        protected readonly ConnectionRoute $route,
        protected readonly Grammar $grammar,
    ) {
    }

    /**
     * Write this statement as SQL together with the values its placeholders need.
     *
     * @return CompiledSql
     */
    abstract public function compile(): CompiledSql;

    /**
     * Run this statement.
     *
     * A statement answers with the rows it read, with the number of rows it
     * changed, or with the id it gave a row it wrote, which is why these
     * shapes appear here; each kind of statement narrows the return type to
     * the shapes it produces.
     *
     * @return Result|int|string
     */
    abstract public function execute(): Result|int|string;

    /**
     * The SQL of this statement, with the placeholders left in place.
     *
     * @return string
     */
    public function toSql(): string
    {
        return $this->compile()->sql;
    }

    /**
     * The values for the placeholders of this statement, in placeholder order.
     *
     * @return list<scalar|null>
     */
    public function toBindings(): array
    {
        return $this->compile()->bindings;
    }

    /**
     * The SQL with the values written into it, for reading rather than running.
     *
     * What comes back never goes to the server: running it would skip the
     * prepared statement, which is the boundary that keeps a value from being
     * read as SQL. Use toSql() and toBindings() for anything but looking.
     *
     * A `?` inside a quoted name or a string literal is left as written, so
     * a value is not written into it. When the marks found outside quotes do
     * not number the values, which an unclosed quote in the SQL of an
     * Expression causes, every `?` in the text is taken in order instead, and
     * the rendering stops matching the statement from that point on. The
     * statement itself is unaffected, since it is the bindings and not this
     * text that reach the server.
     *
     * Quoting is the driver's, so this asks the route for a connection and
     * opens one if the pool has not connected yet. Which makes it the wrong
     * thing to call from the handler for a statement that just failed: if what
     * failed was obtaining the connection, nothing was cached, and this runs
     * the same lookup again and throws over the error being handled.
     *
     * @return string
     * @throws RuntimeException            When the driver declines to quote a string
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When a persistent connection carries a residual transaction that cannot be rolled back
     */
    public function toRawSql(): string
    {
        $compiled   = $this->compile();
        $connection = $this->route->connection();
        $offsets    = $this->grammar->placeholderOffsets($compiled->sql);

        if (\count($offsets) !== \count($compiled->bindings)) {
            $offsets = array_keys(str_split($compiled->sql), '?', true);
        }

        $rendered = '';
        $cursor   = 0;

        foreach ($offsets as $index => $offset) {
            $rendered .= substr($compiled->sql, $cursor, $offset - $cursor);
            $rendered .= \array_key_exists($index, $compiled->bindings)
                ? $connection->quoteLiteral($compiled->bindings[$index])
                : '?';
            $cursor    = $offset + 1;
        }

        return $rendered . substr($compiled->sql, $cursor);
    }
}
