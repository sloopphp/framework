<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use Sloop\Database\Connection;
use Sloop\Database\Result;

/**
 * A statement under construction, and what every statement can do once built.
 *
 * Collecting and writing are kept apart: a builder records what the caller
 * asked for, and a Grammar turns that into text for one dialect. The Grammar
 * arrives from the connection rather than being created here, so the same
 * builder writes for another dialect by handing it a different one.
 */
abstract class Query
{
    /**
     * Bind a statement to the connection it runs on and the grammar that writes it.
     *
     * @param Connection $connection Connection the statement runs on
     * @param Grammar    $grammar    Grammar that turns the collected parts into SQL
     */
    public function __construct(
        protected readonly Connection $connection,
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
     * A statement answers either with the rows it read or with the number of
     * rows it changed, which is why both shapes appear here; each kind of
     * statement narrows the return type to the one it produces.
     *
     * @return Result|int
     */
    abstract public function execute(): Result|int;

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
     * A `?` written by hand inside an Expression cannot be told apart from a
     * placeholder, so an expression carrying one in a string literal shifts the
     * values that follow it in the rendering. The statement itself is unaffected,
     * since it is the bindings and not this text that reach the server.
     *
     * @return string
     */
    public function toRawSql(): string
    {
        $compiled = $this->compile();
        $bindings = $compiled->bindings;
        $rendered = '';

        foreach (explode('?', $compiled->sql) as $index => $segment) {
            if ($index > 0) {
                $rendered .= \array_key_exists($index - 1, $bindings)
                    ? $this->connection->quoteLiteral($bindings[$index - 1])
                    : '?';
            }

            $rendered .= $segment;
        }

        return $rendered;
    }
}
