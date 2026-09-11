<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * What a statement returns in one position of its select list, and the name it
 * returns it under.
 *
 * A column name reaches the caller under its own name, so naming it again is
 * only worth doing when the caller wants a different one. An expression or a
 * statement has no name of its own: the server falls back to the text that
 * produced it, so a statement counting rows comes back under the whole
 * `(SELECT COUNT(*) FROM ...)` as the key. Both servers do this rather than
 * refusing the statement, which is why a name is required here instead.
 *
 * The name is checked here rather than where the clause is written, so a name
 * that cannot stand as one says so at the line that named it.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class SelectedColumn
{
    /**
     * Name one position of the select list.
     *
     * @param  string|Expression|SubQuery|WindowExpression $source Column name, optionally qualified, an expression, a statement returning one value, or a window call
     * @param  string                                      $alias  Name to return it under
     * @throws InvalidArgumentException                    When the name is not a single name
     */
    public function __construct(
        public string|Expression|SubQuery|WindowExpression $source,
        public string $alias,
    ) {
        if (\count(IdentifierQuoter::split($alias)) !== 1) {
            throw new InvalidArgumentException(
                'An alias is one name, so it cannot be qualified, got ' . $alias . '.',
            );
        }
    }
}
