<?php

declare(strict_types=1);

namespace Sloop\Support;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * Immutable fluent collection.
 *
 * Transforming methods always return a new instance, in line with the
 * framework's no-reference-passing rule. Keys are preserved across map and
 * filter operations (matching Laravel's Collection semantics).
 *
 * @template T
 *
 * @implements IteratorAggregate<array-key, T>
 */
final readonly class Collection implements IteratorAggregate, Countable
{
    /**
     * Construct a Collection from an already-validated array.
     *
     * @param array<array-key, T> $items Items to wrap
     */
    private function __construct(private array $items)
    {
    }

    /**
     * Create a Collection from any iterable.
     *
     * @template U
     * @param  iterable<array-key, U> $items Source items
     * @return self<U>
     */
    public static function from(iterable $items): self
    {
        if (\is_array($items)) {
            return new self($items);
        }

        return new self(iterator_to_array($items));
    }

    /**
     * Return an iterator over the items for IteratorAggregate.
     *
     * @return Traversable<array-key, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Count the items in the collection.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return \count($this->items);
    }

    /**
     * Check whether the collection has no items.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Return the underlying array, preserving keys.
     *
     * @return array<array-key, T>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Return the first item, or null when the collection is empty.
     *
     * @return T|null
     */
    public function first(): mixed
    {
        if ($this->items === []) {
            return null;
        }

        $key = array_key_first($this->items);

        return $this->items[$key];
    }

    /**
     * Return the last item, or null when the collection is empty.
     *
     * @return T|null
     */
    public function last(): mixed
    {
        if ($this->items === []) {
            return null;
        }

        $key = array_key_last($this->items);

        return $this->items[$key];
    }

    /**
     * Transform every item through the given callback, preserving keys.
     *
     * @template U
     * @param  callable(T, array-key): U $fn Transformer
     * @return self<U>
     */
    public function map(callable $fn): self
    {
        $result = [];
        foreach ($this->items as $key => $value) {
            $result[$key] = $fn($value, $key);
        }

        return new self($result);
    }

    /**
     * Keep only items for which the predicate returns true. Keys are preserved.
     *
     * @param  callable(T, array-key): bool $fn Predicate
     * @return self<T>
     */
    public function filter(callable $fn): self
    {
        return new self(array_filter($this->items, $fn, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Fold the collection into a single value using the reducer.
     *
     * @template U
     * @param  callable(U, T, array-key): U $fn      Reducer
     * @param  U                            $initial Initial accumulator
     * @return U
     */
    public function reduce(callable $fn, mixed $initial): mixed
    {
        $acc = $initial;
        foreach ($this->items as $key => $value) {
            $acc = $fn($acc, $value, $key);
        }

        return $acc;
    }

    /**
     * Invoke the visitor on each item for side effects and return the same instance.
     *
     * @param  callable(T, array-key): void $fn Visitor
     * @return self<T>
     */
    public function each(callable $fn): self
    {
        foreach ($this->items as $key => $value) {
            $fn($value, $key);
        }

        return $this;
    }

    /**
     * Check whether the collection contains a value.
     *
     * Accepts either a literal value (strict comparison) or a predicate.
     *
     * @param  T|(callable(T, array-key): bool) $needle Value or predicate
     * @return bool
     */
    public function contains(mixed $needle): bool
    {
        if (\is_callable($needle)) {
            foreach ($this->items as $key => $value) {
                if ($needle($value, $key)) {
                    return true;
                }
            }

            return false;
        }

        return \in_array($needle, $this->items, true);
    }

    /**
     * Sum the items. When $by is null the items must be numeric; a string key
     * picks a field; a callable returns the numeric value per item.
     *
     * @param  string|callable|null $by Key, callable, or null for the items themselves
     * @return int|float
     */
    public function sum(string|callable|null $by = null): int|float
    {
        $total = 0;
        foreach ($this->items as $value) {
            $total += $this->extractNumeric($value, $by);
        }

        return $total;
    }

    /**
     * Compute the arithmetic mean. Returns null when the collection is empty.
     *
     * @param  string|callable|null $by Key, callable, or null for the items themselves
     * @return int|float|null
     */
    public function avg(string|callable|null $by = null): int|float|null
    {
        if ($this->items === []) {
            return null;
        }

        return $this->sum($by) / \count($this->items);
    }

    /**
     * Return the smallest numeric value. Returns null when the collection is empty.
     *
     * @param  string|callable|null $by Key, callable, or null for the items themselves
     * @return int|float|null
     */
    public function min(string|callable|null $by = null): int|float|null
    {
        if ($this->items === []) {
            return null;
        }

        $min = null;
        foreach ($this->items as $value) {
            $n = $this->extractNumeric($value, $by);
            if ($min === null || $n < $min) {
                $min = $n;
            }
        }

        return $min;
    }

    /**
     * Return the largest numeric value. Returns null when the collection is empty.
     *
     * @param  string|callable|null $by Key, callable, or null for the items themselves
     * @return int|float|null
     */
    public function max(string|callable|null $by = null): int|float|null
    {
        if ($this->items === []) {
            return null;
        }

        $max = null;
        foreach ($this->items as $value) {
            $n = $this->extractNumeric($value, $by);
            if ($max === null || $n > $max) {
                $max = $n;
            }
        }

        return $max;
    }

    /**
     * Re-key the collection by a field name or key extractor. Duplicate keys overwrite.
     *
     * @param  string|(callable(T, array-key): array-key) $key Key name or key extractor
     * @return self<T>
     */
    public function keyBy(string|callable $key): self
    {
        $result = [];
        foreach ($this->items as $k => $value) {
            $extracted = \is_callable($key) ? $key($value, $k) : $this->readMember($value, $key);
            $result[$this->coerceArrayKey($extracted)] = $value;
        }

        return new self($result);
    }

    /**
     * Group the items by a field name or key extractor.
     * Each group is itself a Collection.
     *
     * @param  string|(callable(T, array-key): array-key) $key Key name or key extractor
     * @return self<self<T>>
     */
    public function groupBy(string|callable $key): self
    {
        $groups = [];
        foreach ($this->items as $k => $value) {
            $extracted = \is_callable($key) ? $key($value, $k) : $this->readMember($value, $key);
            $groups[$this->coerceArrayKey($extracted)][] = $value;
        }

        return new self(array_map(fn (array $items): self => new self($items), $groups));
    }

    /**
     * Extract a named field from every item. Missing values become null.
     *
     * @param  string $key Member name to extract
     * @return self<mixed>
     */
    public function pluck(string $key): self
    {
        return new self(array_map(fn (mixed $value): mixed => $this->readMember($value, $key), $this->items));
    }

    /**
     * Split the collection into chunks of the given size. The final chunk may be smaller.
     *
     * @param  int $size Chunk size
     * @return self<self<T>>
     * @throws InvalidArgumentException If size is less than 1
     */
    public function chunk(int $size): self
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1, got ' . $size . '.');
        }

        $result = [];
        foreach (array_chunk($this->items, $size, preserve_keys: true) as $chunk) {
            $result[] = new self($chunk);
        }

        return new self($result);
    }

    /**
     * Return the first N items, preserving keys.
     *
     * @param  int $count Number of items to keep
     * @return self<T>
     */
    public function take(int $count): self
    {
        return new self(\array_slice($this->items, 0, $count, preserve_keys: true));
    }

    /**
     * Drop the first N items and return the rest, preserving keys.
     *
     * @param  int $count Number of items to drop
     * @return self<T>
     */
    public function skip(int $count): self
    {
        return new self(\array_slice($this->items, $count, preserve_keys: true));
    }

    /**
     * Sort items, preserving keys. Without a comparator uses asort (ascending).
     *
     * @param  (callable(T, T): int)|null $fn Comparator; null uses asort
     * @return self<T>
     */
    public function sort(?callable $fn = null): self
    {
        $items = $this->items;
        if ($fn === null) {
            asort($items);
        } else {
            uasort($items, $fn);
        }

        return new self($items);
    }

    /**
     * Sort items by a named field or a value extractor, preserving keys.
     *
     * @param  string|(callable(T): mixed) $by Key name or value extractor
     * @return self<T>
     */
    public function sortBy(string|callable $by): self
    {
        $items = $this->items;
        uasort($items, function (mixed $a, mixed $b) use ($by): int {
            $va = \is_callable($by) ? $by($a) : $this->readMember($a, $by);
            $vb = \is_callable($by) ? $by($b) : $this->readMember($b, $by);

            return $va <=> $vb;
        });

        return new self($items);
    }

    /**
     * Reverse the item order, preserving keys.
     *
     * @return self<T>
     */
    public function reverse(): self
    {
        return new self(array_reverse($this->items, preserve_keys: true));
    }

    /**
     * Return the items re-indexed as a sequential list, discarding string keys.
     *
     * @return self<T>
     */
    public function values(): self
    {
        return new self(array_values($this->items));
    }

    /**
     * Return the keys of the collection as a new Collection.
     *
     * @return self<array-key>
     */
    public function keys(): self
    {
        return new self(array_keys($this->items));
    }

    /**
     * Extract a numeric value from a single item for aggregation.
     *
     * @param  T                    $value Item
     * @param  string|callable|null $by    Extractor (null uses $value directly)
     * @return int|float
     * @throws InvalidArgumentException If the extracted value is not numeric
     */
    private function extractNumeric(mixed $value, string|callable|null $by): int|float
    {
        if ($by === null) {
            if (!\is_int($value) && !\is_float($value)) {
                throw new InvalidArgumentException('Collection aggregation without a key requires numeric items.');
            }

            return $value;
        }

        if (\is_callable($by)) {
            $result = $by($value);
            if (!\is_int($result) && !\is_float($result)) {
                throw new InvalidArgumentException('Aggregation callable must return int or float.');
            }

            return $result;
        }

        $extracted = $this->readMember($value, $by);
        if (!\is_int($extracted) && !\is_float($extracted)) {
            throw new InvalidArgumentException('Aggregation key "' . $by . '" must resolve to int or float.');
        }

        return $extracted;
    }

    /**
     * Read a named member from an array or object item. Returns null on miss.
     *
     * @param  mixed  $value Array or object
     * @param  string $key   Member name
     * @return mixed
     */
    private function readMember(mixed $value, string $key): mixed
    {
        if (\is_array($value)) {
            return $value[$key] ?? null;
        }

        if (\is_object($value) && isset($value->{$key})) {
            return $value->{$key};
        }

        return null;
    }

    /**
     * Narrow an arbitrary value to an array key for keyBy/groupBy.
     *
     * @param  mixed $value Extracted key candidate
     * @return int|string
     * @throws InvalidArgumentException If the extracted value cannot be used as an array key
     */
    private function coerceArrayKey(mixed $value): int|string
    {
        if (\is_int($value) || \is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('keyBy/groupBy key must resolve to int or string.');
    }
}
