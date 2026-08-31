<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * One column of a statement and the value being written to it.
 *
 * The pair a SET clause is made of. The value is kept as it was given rather
 * than turned into text here, so that a Grammar decides between binding it and
 * writing it out: a plain value becomes a placeholder, and an Expression is
 * written as the caller spelled it, which is how a column is set from what it
 * already holds.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class Assignment
{
    /**
     * Pair a column with the value to write to it.
     *
     * @param string                                $column Column to write to, optionally table qualified
     * @param string|int|float|bool|Expression|null $value  Value to write, or an expression standing for one
     */
    public function __construct(
        public string $column,
        public string|int|float|bool|Expression|null $value,
    ) {
    }
}
