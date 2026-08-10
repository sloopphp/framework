<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * SQL text together with the values its placeholders need, in placeholder order.
 *
 * A grammar returns one of these for a whole statement and for the individual
 * clauses it assembles, so the SQL and its bindings are always produced by the
 * same pass. Building them separately would let the two drift apart, and a
 * binding list that no longer matches the placeholders is a silent change of
 * meaning rather than an error.
 */
final readonly class CompiledSql
{
    /**
     * The values for the placeholders in the SQL.
     *
     * @var list<scalar|null>
     */
    public array $bindings;

    /**
     * Pair SQL with its bindings.
     *
     * @param  string                   $sql      SQL text
     * @param  array<int|string, mixed> $bindings Values for the placeholders in $sql, in order
     * @throws InvalidArgumentException When $bindings is not a list, or holds a value PDO cannot bind
     */
    public function __construct(
        public string $sql,
        array $bindings = [],
    ) {
        if (!array_is_list($bindings)) {
            throw new InvalidArgumentException(
                'Bindings must be a list, so that their order matches the placeholders in the SQL.',
            );
        }

        $bindable = [];

        foreach ($bindings as $index => $binding) {
            if ($binding !== null && !\is_scalar($binding)) {
                throw new InvalidArgumentException(
                    'Bindings must be scalar or null, got ' . get_debug_type($binding) . ' at index ' . $index . '.',
                );
            }

            $bindable[] = $binding;
        }

        $this->bindings = $bindable;
    }
}
