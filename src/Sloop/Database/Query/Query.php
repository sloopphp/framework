<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use RuntimeException;
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
     * A `?` between backticks is part of a column or table name rather than a
     * placeholder, so those are stepped over. The SQL of an Expression is
     * written by hand and can hold anything, including a `?` in a string
     * literal or a backtick that opens nothing: either shifts the values that
     * follow in the rendering. The statement itself is unaffected, since it is
     * the bindings and not this text that reach the server.
     *
     * @return string
     * @throws RuntimeException When the driver declines to quote a string
     */
    public function toRawSql(): string
    {
        $compiled     = $this->compile();
        $sql          = $compiled->sql;
        $length       = \strlen($sql);
        $rendered     = '';
        $consumed     = 0;
        $inIdentifier = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];

            if ($character === '`') {
                // A doubled backtick stands for one inside a name, and toggling
                // twice lands back inside the name, which is where it belongs.
                $inIdentifier = !$inIdentifier;
            }

            if ($character === '?'
                && !$inIdentifier
                && \array_key_exists($consumed, $compiled->bindings)) {
                $rendered .= $this->connection->quoteLiteral($compiled->bindings[$consumed]);
                $consumed++;

                continue;
            }

            $rendered .= $character;
        }

        return $rendered;
    }
}
