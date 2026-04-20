<?php

declare(strict_types=1);

namespace Sloop\Database;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Thin bridge over the rows fetched from a database query.
 *
 * Implements IteratorAggregate so rows can be iterated with foreach, and
 * Countable so count() returns the number of rows without walking the array.
 *
 * Phase 5-1 ships only this minimal shape. Richer APIs (keyed maps, first(),
 * value(), pluck, chunk) are added in Phase 5-2 on top of the SELECT builder.
 *
 * @implements IteratorAggregate<array-key, array<array-key, mixed>>
 */
final readonly class Result implements IteratorAggregate, Countable
{
    /**
     * Create a Result from already-fetched rows.
     *
     * Row key/value types mirror PDOStatement::fetchAll(PDO::FETCH_ASSOC),
     * which PDO does not narrow further without a driver-specific stub.
     *
     * @param array<array-key, array<array-key, mixed>> $rows Fetched rows
     */
    public function __construct(private array $rows)
    {
    }

    /**
     * Iterate the rows in their original order.
     *
     * @return Traversable<array-key, array<array-key, mixed>>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    /**
     * Number of rows in the result set.
     *
     * @return int
     */
    public function count(): int
    {
        return \count($this->rows);
    }

    /**
     * Return the rows as a plain array.
     *
     * @return array<array-key, array<array-key, mixed>>
     */
    public function toArray(): array
    {
        return $this->rows;
    }
}
