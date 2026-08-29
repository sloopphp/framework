<?php

declare(strict_types=1);

namespace Sloop\Database;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use ReflectionClass;
use RuntimeException;
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
     * Hydrate each row into an instance of the given class.
     *
     * Columns are matched to constructor parameters by name and passed as named
     * arguments, so the order of the SELECT list does not matter. A column with
     * no matching parameter is ignored, which lets one class read a subset of a
     * wider result set.
     *
     * Values are handed over as the driver returned them; converting a DATETIME
     * string into a date object, or a flag into an enum, is the constructor's
     * job. A value the constructor cannot accept surfaces as the TypeError it
     * raises, naming the parameter.
     *
     * @template T of object
     * @param  class-string<T>          $class Class to hydrate each row into
     * @return list<T>                  One instance per row, in the result's order
     * @throws InvalidArgumentException When the class cannot be hydrated into at all
     * @throws RuntimeException         When a row has no column for a required parameter
     */
    public function asObject(string $class): array
    {
        $parameters = self::constructorParameters($class);

        $objects = [];

        foreach ($this->rows as $index => $row) {
            $arguments = [];

            foreach ($parameters as $name => $isOptional) {
                if (\array_key_exists($name, $row)) {
                    $arguments[$name] = $row[$name];

                    continue;
                }

                if (!$isOptional) {
                    throw new RuntimeException(
                        'Row ' . $index . ' has no column "' . $name . '" for ' . $class
                            . '::__construct(). Columns present: '
                            . implode(', ', array_keys($row)) . '.',
                    );
                }
            }

            $objects[] = new $class(...$arguments);
        }

        return $objects;
    }

    /**
     * Read the constructor parameters a class is hydrated through.
     *
     * The result is cached per class name for the life of the process, since a
     * class signature cannot change once loaded. The cache is a static local
     * rather than a property because a readonly class cannot declare static
     * properties.
     *
     * @param  class-string             $class Class to reflect
     * @return array<string, bool>      Parameter name to whether it may be omitted
     * @throws InvalidArgumentException When the class cannot be hydrated into at all
     */
    private static function constructorParameters(string $class): array
    {
        /** @var array<class-string, array<string, bool>> $cache */
        static $cache = [];

        if (isset($cache[$class])) {
            return $cache[$class];
        }

        if (!class_exists($class)) {
            throw new InvalidArgumentException('Class "' . $class . '" does not exist.');
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException(
                'Class "' . $class . '" cannot be instantiated, so rows cannot be hydrated into it.',
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new InvalidArgumentException(
                'Class "' . $class . '" has no constructor, so there is nowhere to pass the columns.',
            );
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            // A variadic parameter collects whatever is left over, and named
            // arguments cannot reach it. Rejecting it here keeps the failure at
            // the class rather than at a row that happens to lack a column.
            if ($parameter->isVariadic()) {
                throw new InvalidArgumentException(
                    'Constructor of "' . $class . '" takes a variadic parameter ($'
                        . $parameter->getName() . '), which columns cannot be matched to.',
                );
            }

            $parameters[$parameter->getName()] = $parameter->isOptional();
        }

        return $cache[$class] = $parameters;
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
