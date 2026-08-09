<?php

declare(strict_types=1);

namespace Sloop\Database;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Sloop\Support\Collection;
use Traversable;

/**
 * Thin bridge over the rows fetched from a database query.
 *
 * Implements IteratorAggregate so rows can be iterated with foreach, and
 * Countable so count() returns the number of rows without walking the array.
 *
 * The accessors cover the shapes a result set is usually reshaped into: a plain
 * list, a lookup keyed by one column, a column-to-column map, and groups. Any
 * transformation beyond that belongs to Collection, which toCollection() hands
 * the rows to. Keeping map/filter/sum off this class is what makes the split
 * meaningful: Result stays a database bridge, Collection stays generic.
 *
 * Rows are held in memory rather than streamed, so the result can be iterated
 * more than once.
 *
 * @implements IteratorAggregate<int, array<array-key, int|float|string|null>>
 */
final readonly class Result implements IteratorAggregate, Countable
{
    /**
     * Create a Result from already-fetched rows.
     *
     * Values are narrowed by Connection::query() before they reach here; a
     * column name stays array-key because PHP casts numeric string keys such as
     * the one `SELECT 1` produces to int.
     *
     * @param list<array<array-key, int|float|string|null>> $rows Fetched rows
     */
    public function __construct(private array $rows)
    {
    }

    /**
     * Iterate the rows in their original order.
     *
     * @return Traversable<int, array<array-key, int|float|string|null>>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    /**
     * Number of rows in the result set.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return \count($this->rows);
    }

    /**
     * Whether the result set has no rows.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Return the rows as a plain list.
     *
     * @return list<array<array-key, int|float|string|null>>
     */
    public function asArray(): array
    {
        return $this->rows;
    }

    /**
     * Return the first row, or null when the result set is empty.
     *
     * Reads the leading key rather than index 0. For the list this class is
     * typed to hold the two are the same, so this is purely for parity with
     * Collection::first().
     *
     * @return array<array-key, int|float|string|null>|null
     */
    public function first(): ?array
    {
        $firstKey = array_key_first($this->rows);

        return $firstKey === null ? null : $this->rows[$firstKey];
    }

    /**
     * Return the rows keyed by one column.
     *
     * Later rows overwrite earlier ones when the key column is not unique, so
     * this suits lookups by primary or unique key. Pass $removeKey to drop the
     * key column from each row, which is redundant once it is the outer key.
     *
     * @param  string                                                    $keyColumn Column whose value becomes the outer key
     * @param  bool                                                      $removeKey Whether to drop the key column from each row
     * @return array<array-key, array<array-key, int|float|string|null>> Rows keyed by the column value
     * @throws InvalidArgumentException                                  When the column is absent or its value cannot be an array key
     */
    public function asArrayBy(string $keyColumn, bool $removeKey = false): array
    {
        $keyed = [];

        foreach ($this->rows as $row) {
            $key = $this->keyFor($row, $keyColumn);

            if ($removeKey) {
                unset($row[$keyColumn]);
            }

            $keyed[$key] = $row;
        }

        return $keyed;
    }

    /**
     * Return a map from one column's value to another's.
     *
     * Later rows overwrite earlier ones on duplicate keys, matching asArrayBy().
     *
     * @param  string                                  $keyColumn   Column whose value becomes the key
     * @param  string                                  $valueColumn Column whose value becomes the value
     * @return array<array-key, int|float|string|null> Key column value to value column value
     * @throws InvalidArgumentException                When either column is absent or the key cannot be an array key
     */
    public function asMap(string $keyColumn, string $valueColumn): array
    {
        $map = [];

        foreach ($this->rows as $row) {
            if (!\array_key_exists($valueColumn, $row)) {
                throw new InvalidArgumentException(
                    'Column "' . $valueColumn . '" is not in the result set.',
                );
            }

            $map[$this->keyFor($row, $keyColumn)] = $row[$valueColumn];
        }

        return $map;
    }

    /**
     * Group the rows by one column's value.
     *
     * Only single-column grouping is offered here. Composite keys and any
     * reshaping of the groups belong to Collection via toCollection().
     *
     * @param  string                                                          $column Column to group by
     * @return array<array-key, list<array<array-key, int|float|string|null>>> Groups in first-seen order
     * @throws InvalidArgumentException                                        When the column is absent or its value cannot be an array key
     */
    public function groupBy(string $column): array
    {
        $groups = [];

        foreach ($this->rows as $row) {
            $groups[$this->keyFor($row, $column)][] = $row;
        }

        return $groups;
    }

    /**
     * Hand the rows to the general-purpose Collection for further reshaping.
     *
     * @return Collection<array<array-key, int|float|string|null>> Collection wrapping the rows
     */
    public function toCollection(): Collection
    {
        return Collection::from($this->rows);
    }

    /**
     * Read one row's column value for use as an array key.
     *
     * A missing column means the column was not selected, and a value that is
     * neither int nor string would be coerced by PHP — null to '', a float to a
     * truncated int — silently merging rows that are not actually the same.
     *
     * @param  array<array-key, int|float|string|null> $row    Row to read from
     * @param  string                                  $column Column holding the key
     * @return int|string                              Value usable as an array key
     * @throws InvalidArgumentException                When the column is absent or its value cannot be an array key
     */
    private function keyFor(array $row, string $column): int|string
    {
        if (!\array_key_exists($column, $row)) {
            throw new InvalidArgumentException('Column "' . $column . '" is not in the result set.');
        }

        $key = $row[$column];

        if (!\is_int($key) && !\is_string($key)) {
            throw new InvalidArgumentException(
                'Column "' . $column . '" must hold an int or string to be used as a key, got '
                    . get_debug_type($key) . '.',
            );
        }

        return $key;
    }
}
