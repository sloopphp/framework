<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use LogicException;

/**
 * A SELECT builder standing where a statement expects a set of values.
 *
 * The builder is held rather than compiled on the spot, so the statement that
 * runs is the one the builder describes when the outer statement is compiled.
 * A condition added to the inner builder after it was handed over is therefore
 * part of what runs, which is what a caller who still holds the builder would
 * expect.
 *
 * Wrapping it keeps a Grammar from having to know what a builder is: the
 * grammar asks for the SQL and the bindings, and this is what answers.
 */
final readonly class SubQuery
{
    /**
     * Hold the statement that stands as a set of values.
     *
     * @internal Built by the query builders; a Grammar subclass reads it.
     *
     * @param Select $query Statement whose rows make up the set
     */
    public function __construct(private Select $query)
    {
    }

    /**
     * Write the held statement as SQL together with the values it needs.
     *
     * @return CompiledSql              The statement and its bindings, the bindings in placeholder order
     * @throws LogicException           When the statement names no table, a group of conditions was left open, or an offset has no limit
     * @throws InvalidArgumentException When an identifier is malformed
     */
    public function compile(): CompiledSql
    {
        return $this->query->compile();
    }
}
