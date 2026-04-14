<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Support;

use ArrayIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sloop\Support\Collection;
use stdClass;

final class CollectionTest extends TestCase
{
    // -------------------------------------------------------
    // from / count / isEmpty / toArray
    // -------------------------------------------------------

    public function testFromAcceptsArray(): void
    {
        $collection = Collection::from(['a', 'b', 'c']);

        $this->assertSame(['a', 'b', 'c'], $collection->toArray());
    }

    public function testFromAcceptsIterator(): void
    {
        $collection = Collection::from(new ArrayIterator(['x' => 1, 'y' => 2]));

        $this->assertSame(['x' => 1, 'y' => 2], $collection->toArray());
    }

    public function testCountReturnsNumberOfItems(): void
    {
        $this->assertSame(3, Collection::from([1, 2, 3])->count());
    }

    public function testCountReturnsZeroForEmpty(): void
    {
        $this->assertSame(0, Collection::from([])->count());
    }

    public function testIsEmptyReturnsTrueForEmpty(): void
    {
        $this->assertTrue(Collection::from([])->isEmpty());
    }

    public function testIsEmptyReturnsFalseForNonEmpty(): void
    {
        $this->assertFalse(Collection::from([1])->isEmpty());
    }

    public function testToArrayPreservesKeys(): void
    {
        $this->assertSame(['a' => 1, 'b' => 2], Collection::from(['a' => 1, 'b' => 2])->toArray());
    }

    // -------------------------------------------------------
    // first / last
    // -------------------------------------------------------

    public function testFirstReturnsFirstItem(): void
    {
        $this->assertSame(1, Collection::from([1, 2, 3])->first());
    }

    public function testFirstReturnsNullForEmpty(): void
    {
        $empty = Collection::from([1])->filter(fn (int $n): bool => false);

        $this->assertNull($empty->first());
    }

    public function testFirstWithAssociativeKeys(): void
    {
        $this->assertSame('alice', Collection::from(['a' => 'alice', 'b' => 'bob'])->first());
    }

    public function testLastReturnsLastItem(): void
    {
        $this->assertSame(3, Collection::from([1, 2, 3])->last());
    }

    public function testLastReturnsNullForEmpty(): void
    {
        $empty = Collection::from([1])->filter(fn (int $n): bool => false);

        $this->assertNull($empty->last());
    }

    public function testLastWithAssociativeKeys(): void
    {
        $this->assertSame('bob', Collection::from(['a' => 'alice', 'b' => 'bob'])->last());
    }

    // -------------------------------------------------------
    // map
    // -------------------------------------------------------

    public function testMapTransformsEachItem(): void
    {
        $result = Collection::from([1, 2, 3])->map(fn (int $n): int => $n * 2);

        $this->assertSame([2, 4, 6], $result->toArray());
    }

    public function testMapPreservesKeys(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2])->map(fn (int $n): int => $n * 10);

        $this->assertSame(['a' => 10, 'b' => 20], $result->toArray());
    }

    public function testMapPassesKeyToCallback(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2])->map(
            fn (int $value, string $key): string => $key . '=' . $value,
        );

        $this->assertSame(['a' => 'a=1', 'b' => 'b=2'], $result->toArray());
    }

    public function testMapOnEmptyReturnsEmpty(): void
    {
        $this->assertTrue(Collection::from([])->map(fn (mixed $x): mixed => $x)->isEmpty());
    }

    public function testMapReturnsNewInstance(): void
    {
        $original = Collection::from([1, 2]);
        $mapped   = $original->map(fn (int $n): int => $n);

        $this->assertNotSame($original, $mapped);
    }

    // -------------------------------------------------------
    // filter
    // -------------------------------------------------------

    public function testFilterKeepsMatchingItems(): void
    {
        $result = Collection::from([1, 2, 3, 4])->filter(fn (int $n): bool => $n % 2 === 0);

        $this->assertSame([1 => 2, 3 => 4], $result->toArray());
    }

    public function testFilterPreservesKeys(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2, 'c' => 3])
            ->filter(fn (int $n): bool => $n !== 2);

        $this->assertSame(['a' => 1, 'c' => 3], $result->toArray());
    }

    public function testFilterPassesKeyToCallback(): void
    {
        $result = Collection::from(['keep1' => 1, 'drop' => 2, 'keep2' => 3])
            ->filter(fn (int $v, string $k): bool => str_starts_with($k, 'keep'));

        $this->assertSame(['keep1' => 1, 'keep2' => 3], $result->toArray());
    }

    public function testFilterOnEmptyReturnsEmpty(): void
    {
        $this->assertTrue(Collection::from([])->filter(fn (): bool => true)->isEmpty());
    }

    // -------------------------------------------------------
    // reduce
    // -------------------------------------------------------

    public function testReduceAccumulates(): void
    {
        $sum = Collection::from([1, 2, 3, 4])->reduce(
            fn (int $acc, int $n): int => $acc + $n,
            0,
        );

        $this->assertSame(10, $sum);
    }

    public function testReduceReturnsInitialForEmpty(): void
    {
        $this->assertSame('seed', Collection::from([])->reduce(
            fn (string $acc, mixed $v): string => $acc . '!',
            'seed',
        ));
    }

    public function testReducePassesKey(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2])->reduce(
            fn (string $acc, int $v, string $k): string => $acc . $k . $v,
            '',
        );

        $this->assertSame('a1b2', $result);
    }

    // -------------------------------------------------------
    // each
    // -------------------------------------------------------

    public function testEachVisitsAllItems(): void
    {
        $seen = new \ArrayObject();
        Collection::from([1, 2, 3])->each(function (int $n) use ($seen): void {
            $seen->append($n);
        });

        $this->assertSame([1, 2, 3], $seen->getArrayCopy());
    }

    public function testEachReturnsSelf(): void
    {
        $collection = Collection::from([1]);

        $this->assertSame($collection, $collection->each(fn (): null => null));
    }

    // -------------------------------------------------------
    // contains
    // -------------------------------------------------------

    public function testContainsReturnsTrueForMatchingValue(): void
    {
        $this->assertTrue(Collection::from([1, 2, 3])->contains(2));
    }

    public function testContainsReturnsFalseForMissingValue(): void
    {
        $this->assertFalse(Collection::from([1, 2, 3])->contains(99));
    }

    public function testContainsWithCallable(): void
    {
        $this->assertTrue(Collection::from([1, 2, 3])->contains(fn (int $n): bool => $n > 2));
    }

    public function testContainsCallableReturnsFalseWhenNoMatch(): void
    {
        $this->assertFalse(Collection::from([1, 2, 3])->contains(fn (int $n): bool => $n > 10));
    }

    public function testContainsOnEmpty(): void
    {
        $empty = Collection::from([1])->filter(fn (int $n): bool => false);

        $this->assertFalse($empty->contains(1));
    }

    // -------------------------------------------------------
    // sum
    // -------------------------------------------------------

    public function testSumOfNumericItems(): void
    {
        $this->assertSame(10, Collection::from([1, 2, 3, 4])->sum());
    }

    public function testSumOfEmptyIsZero(): void
    {
        $this->assertSame(0, Collection::from([])->sum());
    }

    public function testSumByStringKey(): void
    {
        $items = [['price' => 100], ['price' => 250], ['price' => 50]];

        $this->assertSame(400, Collection::from($items)->sum('price'));
    }

    public function testSumByCallable(): void
    {
        $total = Collection::from([1, 2, 3])->sum(fn (int $n): int => $n * 10);

        $this->assertSame(60, $total);
    }

    public function testSumMixedIntFloat(): void
    {
        $this->assertSame(6.5, Collection::from([1, 2.5, 3])->sum());
    }

    public function testSumThrowsOnNonNumericItem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection aggregation without a key requires numeric items.');

        Collection::from(['a', 'b'])->sum();
    }

    public function testSumThrowsOnMissingKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Aggregation key "price" must resolve to int or float.');

        Collection::from([['name' => 'x']])->sum('price');
    }

    public function testSumThrowsOnCallableReturningNonNumeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Aggregation callable must return int or float.');

        Collection::from([1, 2])->sum(fn (int $n): string => (string) $n);
    }

    // -------------------------------------------------------
    // avg / min / max
    // -------------------------------------------------------

    public function testAvgOfNumericItems(): void
    {
        $this->assertSame(2.5, Collection::from([1, 2, 3, 4])->avg());
    }

    public function testAvgReturnsNullForEmpty(): void
    {
        $this->assertNull(Collection::from([])->avg());
    }

    public function testAvgByKey(): void
    {
        $items = [['score' => 10], ['score' => 20], ['score' => 30]];

        $this->assertSame(20, Collection::from($items)->avg('score'));
    }

    public function testMinReturnsSmallest(): void
    {
        $this->assertSame(1, Collection::from([3, 1, 4, 1, 5])->min());
    }

    public function testMinReturnsNullForEmpty(): void
    {
        $this->assertNull(Collection::from([])->min());
    }

    public function testMinByCallable(): void
    {
        $this->assertSame(3, Collection::from([10, 3, 7])->min(fn (int $n): int => $n));
    }

    public function testMinKeepsFirstOccurrenceOnDuplicates(): void
    {
        $items = ['first' => 1, 'second' => 2, 'third' => 1];
        $min   = Collection::from($items)->min(fn (int $n): int => $n);

        $this->assertSame(1, $min);
    }

    public function testMaxReturnsLargest(): void
    {
        $this->assertSame(5, Collection::from([3, 1, 4, 1, 5])->max());
    }

    public function testMaxReturnsNullForEmpty(): void
    {
        $this->assertNull(Collection::from([])->max());
    }

    public function testMaxByKey(): void
    {
        $items = [['price' => 100], ['price' => 500], ['price' => 200]];

        $this->assertSame(500, Collection::from($items)->max('price'));
    }

    public function testMaxKeepsFirstOccurrenceOnDuplicates(): void
    {
        $items = ['first' => 5, 'second' => 2, 'third' => 5];
        $max   = Collection::from($items)->max(fn (int $n): int => $n);

        $this->assertSame(5, $max);
    }

    // -------------------------------------------------------
    // keyBy
    // -------------------------------------------------------

    public function testKeyByStringKey(): void
    {
        $items  = [['id' => 'a', 'v' => 1], ['id' => 'b', 'v' => 2]];
        $result = Collection::from($items)->keyBy('id');

        $this->assertSame(
            ['a' => ['id' => 'a', 'v' => 1], 'b' => ['id' => 'b', 'v' => 2]],
            $result->toArray(),
        );
    }

    public function testKeyByCallable(): void
    {
        $items  = [['n' => 1], ['n' => 2]];
        $result = Collection::from($items)->keyBy(fn (array $row): string => 'key_' . $row['n']);

        $this->assertSame(
            ['key_1' => ['n' => 1], 'key_2' => ['n' => 2]],
            $result->toArray(),
        );
    }

    public function testKeyByThrowsForNonScalarKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keyBy/groupBy key must resolve to int or string.');

        Collection::from([['id' => null]])->keyBy('id');
    }

    public function testKeyByThrowsForFloatKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keyBy/groupBy key must resolve to int or string.');

        Collection::from([['id' => 1.5]])->keyBy('id');
    }

    public function testKeyByTreatsPhpFunctionNameAsCallable(): void
    {
        // Regression: 'count' is a valid PHP function name, so is_callable() returns true
        // and keyBy evaluates it as a callable instead of a field name. count() on each row
        // returns 1, collapsing both items into key 1 with last-write-wins. This test pins
        // the current behavior so future changes are noticed.
        $result = Collection::from([['count' => 'apple'], ['count' => 'banana']])->keyBy('count');

        $this->assertSame([1 => ['count' => 'banana']], $result->toArray());
    }

    // -------------------------------------------------------
    // groupBy
    // -------------------------------------------------------

    public function testGroupByStringKey(): void
    {
        $items  = [
            ['type' => 'fruit', 'name' => 'apple'],
            ['type' => 'fruit', 'name' => 'banana'],
            ['type' => 'veg',   'name' => 'carrot'],
        ];
        $result = Collection::from($items)->groupBy('type');

        $this->assertInstanceOf(Collection::class, $result->toArray()['fruit']);
        $this->assertSame(2, $result->toArray()['fruit']->count());
        $this->assertSame(1, $result->toArray()['veg']->count());
    }

    public function testGroupByCallable(): void
    {
        $result = Collection::from([1, 2, 3, 4, 5])
            ->groupBy(fn (int $n): string => $n % 2 === 0 ? 'even' : 'odd');

        $groups = $result->toArray();

        $this->assertSame([1, 3, 5], $groups['odd']->toArray());
        $this->assertSame([2, 4], $groups['even']->toArray());
    }

    // -------------------------------------------------------
    // pluck
    // -------------------------------------------------------

    public function testPluckExtractsArrayKey(): void
    {
        $items = [['name' => 'a'], ['name' => 'b'], ['name' => 'c']];

        $this->assertSame(['a', 'b', 'c'], Collection::from($items)->pluck('name')->toArray());
    }

    public function testPluckExtractsObjectProperty(): void
    {
        $o1       = new stdClass();
        $o1->name = 'alice';
        $o2       = new stdClass();
        $o2->name = 'bob';

        $this->assertSame(['alice', 'bob'], Collection::from([$o1, $o2])->pluck('name')->toArray());
    }

    public function testPluckReturnsNullForMissingKey(): void
    {
        $this->assertSame([null, null], Collection::from([['x' => 1], ['x' => 2]])->pluck('y')->toArray());
    }

    // -------------------------------------------------------
    // chunk / take / skip
    // -------------------------------------------------------

    public function testChunkSplitsIntoGroups(): void
    {
        $result = Collection::from([1, 2, 3, 4, 5])->chunk(2);
        $chunks = array_map(fn (Collection $c): array => $c->toArray(), $result->toArray());

        $this->assertSame([[1, 2], [2 => 3, 3 => 4], [4 => 5]], $chunks);
    }

    public function testChunkThrowsForZeroSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be at least 1, got 0.');

        Collection::from([1, 2, 3])->chunk(0);
    }

    public function testChunkThrowsForNegativeSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be at least 1, got -1.');

        Collection::from([1, 2, 3])->chunk(-1);
    }

    public function testChunkSizeOneDoesNotThrow(): void
    {
        $result = Collection::from([1, 2])->chunk(1);
        $chunks = array_map(fn (Collection $c): array => $c->toArray(), $result->toArray());

        $this->assertSame([[1], [1 => 2]], $chunks);
    }

    public function testTakeReturnsFirstN(): void
    {
        $this->assertSame([1, 2, 3], Collection::from([1, 2, 3, 4, 5])->take(3)->toArray());
    }

    public function testTakeMoreThanSizeReturnsAll(): void
    {
        $this->assertSame([1, 2], Collection::from([1, 2])->take(10)->toArray());
    }

    public function testSkipReturnsAfterN(): void
    {
        $this->assertSame([3 => 4, 4 => 5], Collection::from([1, 2, 3, 4, 5])->skip(3)->toArray());
    }

    public function testSkipMoreThanSizeReturnsEmpty(): void
    {
        $this->assertTrue(Collection::from([1, 2])->skip(10)->isEmpty());
    }

    public function testTakeWithNegativeCountDelegatesToArraySlice(): void
    {
        // Pin current behavior: negative count is delegated to array_slice with
        // length=-N, which returns all elements except the last N. This differs
        // from Laravel Collection's take(-N) which takes the last N. Changing
        // this should be a conscious decision.
        $this->assertSame([0 => 1, 1 => 2], Collection::from([1, 2, 3, 4])->take(-2)->toArray());
    }

    public function testSkipWithNegativeCountDelegatesToArraySlice(): void
    {
        // Pin current behavior: array_slice($arr, -2) skips all but the last 2.
        $this->assertSame([2 => 3, 3 => 4], Collection::from([1, 2, 3, 4])->skip(-2)->toArray());
    }

    // -------------------------------------------------------
    // sort / sortBy / reverse
    // -------------------------------------------------------

    public function testSortDefaultAscending(): void
    {
        $result = Collection::from([3, 1, 2])->sort();

        $this->assertSame([1 => 1, 2 => 2, 0 => 3], $result->toArray());
    }

    public function testSortWithCustomComparator(): void
    {
        $result = Collection::from([1, 2, 3])->sort(fn (int $a, int $b): int => $b <=> $a);

        $this->assertSame([2 => 3, 1 => 2, 0 => 1], $result->toArray());
    }

    public function testSortByKey(): void
    {
        $items  = [['n' => 3], ['n' => 1], ['n' => 2]];
        $result = Collection::from($items)->sortBy('n');

        $this->assertSame([1, 2, 3], array_column($result->toArray(), 'n'));
    }

    public function testSortByCallable(): void
    {
        $result = Collection::from(['banana', 'apple', 'cherry'])
            ->sortBy(fn (string $s): int => \strlen($s));

        $this->assertSame(['apple', 'banana', 'cherry'], array_values($result->toArray()));
    }

    public function testReverseFlipsOrder(): void
    {
        $this->assertSame([2 => 3, 1 => 2, 0 => 1], Collection::from([1, 2, 3])->reverse()->toArray());
    }

    // -------------------------------------------------------
    // values / keys
    // -------------------------------------------------------

    public function testValuesDiscardsStringKeys(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2])->values();

        $this->assertSame([1, 2], $result->toArray());
    }

    public function testKeysReturnsKeys(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2])->keys();

        $this->assertSame(['a', 'b'], $result->toArray());
    }

    // -------------------------------------------------------
    // IteratorAggregate
    // -------------------------------------------------------

    public function testCanIterateWithForeach(): void
    {
        $collected = [];
        foreach (Collection::from(['a' => 1, 'b' => 2]) as $key => $value) {
            $collected[$key] = $value;
        }

        $this->assertSame(['a' => 1, 'b' => 2], $collected);
    }

    // -------------------------------------------------------
    // Immutability
    // -------------------------------------------------------

    public function testFilterDoesNotMutateOriginal(): void
    {
        $original = Collection::from([1, 2, 3, 4]);
        $original->filter(fn (int $n): bool => $n > 2);

        $this->assertSame([1, 2, 3, 4], $original->toArray());
    }

    public function testSortDoesNotMutateOriginal(): void
    {
        $original = Collection::from([3, 1, 2]);
        $original->sort();

        $this->assertSame([3, 1, 2], $original->toArray());
    }
}
