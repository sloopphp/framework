<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Support;

use ArrayIterator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Sloop\Support\Collection;
use stdClass;
use UnexpectedValueException;

final class CollectionTest extends TestCase
{
    // An extractor that tests pass as an array callable ([self::class, 'halve']).
    public static function halve(int $n): float
    {
        return $n / 2;
    }

    // -------------------------------------------------------
    // from / count / isEmpty / asArray
    // -------------------------------------------------------

    public function testFromAcceptsArray(): void
    {
        $collection = Collection::from(['a', 'b', 'c']);

        $this->assertSame(['a', 'b', 'c'], $collection->asArray());
    }

    public function testFromAcceptsIterator(): void
    {
        $collection = Collection::from(new ArrayIterator(['x' => 1, 'y' => 2]));

        $this->assertSame(['x' => 1, 'y' => 2], $collection->asArray());
    }

    public function testCountReturnsNumberOfItems(): void
    {
        $this->assertCount(3, Collection::from([1, 2, 3]));
    }

    public function testCountReturnsZeroForEmpty(): void
    {
        $this->assertCount(0, Collection::from([]));
    }

    public function testIsEmptyReturnsTrueForEmpty(): void
    {
        $this->assertTrue(Collection::from([])->isEmpty());
    }

    public function testIsEmptyReturnsFalseForNonEmpty(): void
    {
        $this->assertFalse(Collection::from([1])->isEmpty());
    }

    public function testAsArrayPreservesKeys(): void
    {
        $this->assertSame(['a' => 1, 'b' => 2], Collection::from(['a' => 1, 'b' => 2])->asArray());
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

        $this->assertSame([2, 4, 6], $result->asArray());
    }

    public function testMapPreservesKeys(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2])->map(fn (int $n): int => $n * 10);

        $this->assertSame(['a' => 10, 'b' => 20], $result->asArray());
    }

    public function testMapPassesKeyToCallback(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2])->map(
            fn (int $value, string $key): string => $key . '=' . $value,
        );

        $this->assertSame(['a' => 'a=1', 'b' => 'b=2'], $result->asArray());
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

        $this->assertSame([1 => 2, 3 => 4], $result->asArray());
    }

    public function testFilterPreservesKeys(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2, 'c' => 3])
            ->filter(fn (int $n): bool => $n !== 2);

        $this->assertSame(['a' => 1, 'c' => 3], $result->asArray());
    }

    public function testFilterPassesKeyToCallback(): void
    {
        $result = Collection::from(['keep1' => 1, 'drop' => 2, 'keep2' => 3])
            ->filter(fn (int $v, string $k): bool => str_starts_with($k, 'keep'));

        $this->assertSame(['keep1' => 1, 'keep2' => 3], $result->asArray());
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

    public function testContainsComparesStrictly(): void
    {
        $this->assertFalse(Collection::from([1, 'x', 3])->contains('1'));
    }

    public function testContainsComparesAPhpFunctionNameAsAValue(): void
    {
        $words = Collection::from(['count', 'x']);

        $this->assertTrue($words->contains('count'));
        $this->assertFalse($words->contains('strlen'));
    }

    public function testContainsComparesAClosureAsAValueWithoutCallingIt(): void
    {
        $present = static fn (): bool => true;
        $absent  = static fn (): bool => true;

        $items = Collection::from([$present, 1]);

        $this->assertTrue($items->contains($present));
        $this->assertFalse($items->contains($absent));
    }

    public function testContainsOnEmpty(): void
    {
        $empty = Collection::from([1])->filter(fn (int $n): bool => false);

        $this->assertFalse($empty->contains(1));
    }

    // -------------------------------------------------------
    // containsMatching
    // -------------------------------------------------------

    public function testContainsMatchingReturnsTrueWhenAnItemMatches(): void
    {
        $this->assertTrue(Collection::from([1, 2, 3])->containsMatching(fn (int $n): bool => $n > 2));
    }

    public function testContainsMatchingReturnsFalseWhenNoItemMatches(): void
    {
        $this->assertFalse(Collection::from([1, 2, 3])->containsMatching(fn (int $n): bool => $n > 10));
    }

    public function testContainsMatchingPassesKeyToPredicate(): void
    {
        $items = Collection::from(['a' => 1, 'b' => 2]);

        $this->assertTrue($items->containsMatching(fn (int $n, int|string $key): bool => $key === 'b'));
        $this->assertFalse($items->containsMatching(fn (int $n, int|string $key): bool => $key === 'c'));
    }

    public function testContainsMatchingStopsAtTheFirstMatch(): void
    {
        $matched = Collection::from([1, 2, 3])->containsMatching(
            fn (int $n): bool => $n === 3 ? throw new LogicException('read past the first match') : $n === 2,
        );

        $this->assertTrue($matched);
    }

    public function testContainsMatchingOnEmptyReturnsFalse(): void
    {
        $this->assertFalse(Collection::from([])->containsMatching(fn (mixed $v): bool => true));
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

    public function testAggregatesReadAFieldNamedLikeAPhpFunction(): void
    {
        $rows = Collection::from([['count' => 3], ['count' => 4], ['count' => 1]]);

        $this->assertSame(8, $rows->sum('count'));
        $this->assertSame(1, $rows->min('count'));
        $this->assertSame(4, $rows->max('count'));
        $this->assertSame(8 / 3, $rows->avg('count'));
    }

    public function testSumThrowsForAPhpFunctionNameThatIsNotAField(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Aggregation key "strlen" must resolve to int or float, got null.');

        Collection::from(['abc', 'de'])->sum('strlen');
    }

    public function testSumAcceptsAnArrayCallable(): void
    {
        $this->assertSame(3.0, Collection::from([2, 4])->sum([self::class, 'halve']));
    }

    public function testSumAcceptsAnObjectWithInvoke(): void
    {
        $double = new class () {
            public function __invoke(int $n): int
            {
                return $n * 2;
            }
        };

        $this->assertSame(12, Collection::from([2, 4])->sum($double));
    }

    public function testSumMixedIntFloat(): void
    {
        $this->assertSame(6.5, Collection::from([1, 2.5, 3])->sum());
    }

    public function testSumThrowsOnNonNumericItem(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Collection aggregation without a key requires numeric items, got string.');

        Collection::from(['a', 'b'])->sum();
    }

    public function testSumThrowsOnMissingKey(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Aggregation key "price" must resolve to int or float, got null.');

        Collection::from([['name' => 'x']])->sum('price');
    }

    public function testSumThrowsOnCallableReturningNonNumeric(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Aggregation callable must return int or float, got string.');

        Collection::from([1, 2])->sum(fn (int $n): string => (string) $n);
    }

    public function testSumThrowsOnNonNumericFieldValue(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Aggregation key "price" must resolve to int or float, got string.');

        Collection::from([['price' => '100']])->sum('price');
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

    public function testMinKeepsTheFirstOfTwoNumericallyEqualValues(): void
    {
        // int 1 and float 1.0 compare equal, so only the retained type reveals
        // whether the strict comparison kept the first occurrence.
        $this->assertSame(1, Collection::from([1, 1.0])->min());
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

    public function testMaxKeepsTheFirstOfTwoNumericallyEqualValues(): void
    {
        // int 1 and float 1.0 compare equal, so only the retained type reveals
        // whether the strict comparison kept the first occurrence.
        $this->assertSame(1, Collection::from([1, 1.0])->max());
    }

    public function testAvgThrowsOnMissingKey(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Aggregation key "score" must resolve to int or float, got null.');

        Collection::from([['name' => 'x']])->avg('score');
    }

    public function testMinThrowsOnNonNumericItem(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Collection aggregation without a key requires numeric items, got string.');

        Collection::from([1, 'a'])->min();
    }

    public function testMaxThrowsOnCallableReturningNonNumeric(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Aggregation callable must return int or float, got null.');

        Collection::from([1, 2])->max(fn (int $n): null => null);
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
            $result->asArray(),
        );
    }

    public function testKeyByCallable(): void
    {
        $items  = [['n' => 1], ['n' => 2]];
        $result = Collection::from($items)->keyBy(fn (array $row): string => 'key_' . $row['n']);

        $this->assertSame(
            ['key_1' => ['n' => 1], 'key_2' => ['n' => 2]],
            $result->asArray(),
        );
    }

    public function testKeyByThrowsForNonScalarKey(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('keyBy/groupBy key "id" must resolve to int or string, got null.');

        Collection::from([['id' => null]])->keyBy('id');
    }

    public function testKeyByThrowsForFloatKey(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('keyBy/groupBy key "id" must resolve to int or string, got float.');

        Collection::from([['id' => 1.5]])->keyBy('id');
    }

    public function testKeyByThrowsForMissingField(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('keyBy/groupBy key "missing" must resolve to int or string, got null.');

        Collection::from([['id' => 1]])->keyBy('missing');
    }

    public function testKeyByThrowsWhenCallableReturnsNonKey(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('keyBy/groupBy callable must return int or string, got float.');

        Collection::from([3])->keyBy(fn (int $n): mixed => $n / 2);
    }

    public function testKeyByReadsAFieldNamedLikeAPhpFunction(): void
    {
        $result = Collection::from([['key' => 'apple'], ['key' => 'banana']])->keyBy('key');

        $this->assertSame(['apple' => ['key' => 'apple'], 'banana' => ['key' => 'banana']], $result->asArray());
    }

    public function testKeyByThrowsForAPhpFunctionNameThatIsNotAField(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('keyBy/groupBy key "strlen" must resolve to int or string, got null.');

        Collection::from(['abc'])->keyBy('strlen');
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

        $this->assertInstanceOf(Collection::class, $result->asArray()['fruit']);
        $this->assertCount(2, $result->asArray()['fruit']);
        $this->assertCount(1, $result->asArray()['veg']);
    }

    public function testGroupByCallable(): void
    {
        $result = Collection::from([1, 2, 3, 4, 5])
            ->groupBy(fn (int $n): string => $n % 2 === 0 ? 'even' : 'odd');

        $groups = $result->asArray();

        $this->assertSame([1, 3, 5], $groups['odd']->asArray());
        $this->assertSame([2, 4], $groups['even']->asArray());
    }

    public function testGroupByReadsAFieldNamedLikeAPhpFunction(): void
    {
        $groups = Collection::from([['count' => 1], ['count' => 1], ['count' => 2]])->groupBy('count')->asArray();

        $this->assertSame([1, 2], array_keys($groups));
        $this->assertCount(2, $groups[1]);
    }

    public function testGroupByThrowsForBoolKey(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('keyBy/groupBy key "active" must resolve to int or string, got bool.');

        (void) Collection::from([['active' => true]])->groupBy('active');
    }

    public function testGroupByThrowsForMissingObjectProperty(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('keyBy/groupBy key "type" must resolve to int or string, got null.');

        (void) Collection::from([new stdClass()])->groupBy('type');
    }

    // -------------------------------------------------------
    // pluck
    // -------------------------------------------------------

    public function testPluckExtractsArrayKey(): void
    {
        $items = [['name' => 'a'], ['name' => 'b'], ['name' => 'c']];

        $this->assertSame(['a', 'b', 'c'], Collection::from($items)->pluck('name')->asArray());
    }

    public function testPluckExtractsObjectProperty(): void
    {
        $o1       = new stdClass();
        $o1->name = 'alice';
        $o2       = new stdClass();
        $o2->name = 'bob';

        $this->assertSame(['alice', 'bob'], Collection::from([$o1, $o2])->pluck('name')->asArray());
    }

    public function testPluckReturnsNullForMissingKey(): void
    {
        $this->assertSame([null, null], Collection::from([['x' => 1], ['x' => 2]])->pluck('y')->asArray());
    }

    public function testPluckReturnsNullForMissingObjectPropertyWithoutEmittingAWarning(): void
    {
        $object       = new stdClass();
        $object->name = 'alice';

        $this->assertSame([null], Collection::from([$object])->pluck('missing')->asArray());
    }

    // -------------------------------------------------------
    // chunk / take / skip
    // -------------------------------------------------------

    public function testChunkSplitsIntoGroups(): void
    {
        $result = Collection::from([1, 2, 3, 4, 5])->chunk(2);
        $chunks = array_map(fn (Collection $c): array => $c->asArray(), $result->asArray());

        $this->assertSame([[1, 2], [2 => 3, 3 => 4], [4 => 5]], $chunks);
    }

    public function testChunkThrowsForZeroSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Chunk size must be at least 1, got 0.');

        Collection::from([1, 2, 3])->chunk(0);
    }

    public function testChunkThrowsForNegativeSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Chunk size must be at least 1, got -1.');

        Collection::from([1, 2, 3])->chunk(-1);
    }

    public function testChunkSizeOneDoesNotThrow(): void
    {
        $result = Collection::from([1, 2])->chunk(1);
        $chunks = array_map(fn (Collection $c): array => $c->asArray(), $result->asArray());

        $this->assertSame([[1], [1 => 2]], $chunks);
    }

    public function testTakeReturnsFirstN(): void
    {
        $this->assertSame([1, 2, 3], Collection::from([1, 2, 3, 4, 5])->take(3)->asArray());
    }

    public function testTakeMoreThanSizeReturnsAll(): void
    {
        $this->assertSame([1, 2], Collection::from([1, 2])->take(10)->asArray());
    }

    public function testSkipReturnsAfterN(): void
    {
        $this->assertSame([3 => 4, 4 => 5], Collection::from([1, 2, 3, 4, 5])->skip(3)->asArray());
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
        $this->assertSame([0 => 1, 1 => 2], Collection::from([1, 2, 3, 4])->take(-2)->asArray());
    }

    public function testSkipWithNegativeCountDelegatesToArraySlice(): void
    {
        // Pin current behavior: array_slice($arr, -2) skips all but the last 2.
        $this->assertSame([2 => 3, 3 => 4], Collection::from([1, 2, 3, 4])->skip(-2)->asArray());
    }

    // -------------------------------------------------------
    // sort / sortBy / reverse
    // -------------------------------------------------------

    public function testSortDefaultAscending(): void
    {
        $result = Collection::from([3, 1, 2])->sort();

        $this->assertSame([1 => 1, 2 => 2, 0 => 3], $result->asArray());
    }

    public function testSortWithCustomComparator(): void
    {
        $result = Collection::from([1, 2, 3])->sort(fn (int $a, int $b): int => $b <=> $a);

        $this->assertSame([2 => 3, 1 => 2, 0 => 1], $result->asArray());
    }

    public function testSortByKey(): void
    {
        $items  = [['n' => 3], ['n' => 1], ['n' => 2]];
        $result = Collection::from($items)->sortBy('n');

        $this->assertSame([1, 2, 3], array_column($result->asArray(), 'n'));
    }

    public function testSortByCallable(): void
    {
        $result = Collection::from(['banana', 'apple', 'cherry'])
            ->sortBy(fn (string $s): int => \strlen($s));

        $this->assertSame(['apple', 'banana', 'cherry'], array_values($result->asArray()));
    }

    public function testSortByReadsAFieldNamedLikeAPhpFunction(): void
    {
        $result = Collection::from([['count' => 3], ['count' => 1], ['count' => 2]])->sortBy('count');

        $this->assertSame([1, 2, 3], array_column($result->asArray(), 'count'));
    }

    public function testSortByPlacesANullFieldFirst(): void
    {
        $result = Collection::from([['n' => 3], ['n' => null], ['n' => 1]])->sortBy('n');

        $this->assertSame([1 => ['n' => null], 2 => ['n' => 1], 0 => ['n' => 3]], $result->asArray());
    }

    public function testSortByReadsANullPublicProperty(): void
    {
        $three    = new stdClass();
        $three->n = 3;
        $none     = new stdClass();
        $none->n  = null;

        $this->assertSame([1 => $none, 0 => $three], Collection::from([$three, $none])->sortBy('n')->asArray());
    }

    public function testSortByReadsAPropertyThatTheObjectReportsAsSet(): void
    {
        $one    = new stdClass();
        $one->n = 1;

        $two = new class () {
            public function __isset(string $name): bool
            {
                return $name === 'n';
            }

            public function __get(string $name): int
            {
                return 2;
            }
        };

        $this->assertSame([1 => $one, 0 => $two], Collection::from([$two, $one])->sortBy('n')->asArray());
    }

    public function testSortByThrowsWhenAnArrayItemLacksTheField(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('sortBy key "n" is not set on an item of type array.');

        Collection::from([['n' => 1], ['m' => 2]])->sortBy('n');
    }

    public function testSortByThrowsWhenAnObjectItemLacksTheProperty(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('sortBy key "n" is not set on an item of type stdClass.');

        Collection::from([new stdClass()])->sortBy('n');
    }

    public function testSortByThrowsWhenTheOnlyMatchingPropertyIsPrivate(): void
    {
        $item = new class () {
            private int $n = 1;

            public function n(): int
            {
                return $this->n;
            }
        };

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('sortBy key "n" is not set on an item of type class@anonymous.');

        Collection::from([$item])->sortBy('n');
    }

    public function testSortByThrowsForScalarItemsInsteadOfCallingAPhpFunction(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('sortBy key "strlen" is not set on an item of type string.');

        Collection::from(['ccc', 'a', 'bb'])->sortBy('strlen');
    }

    public function testSortByThrowsWhenIssetReportsANullMagicPropertyAsUnset(): void
    {
        $item = new readonly class (null) {
            public function __construct(private ?int $stored)
            {
            }

            public function __isset(string $name): bool
            {
                return $name === 'n' && $this->stored !== null;
            }

            public function __get(string $name): ?int
            {
                return $this->stored;
            }
        };

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('sortBy key "n" is not set on an item of type class@anonymous.');

        Collection::from([$item])->sortBy('n');
    }

    public function testSortByThrowsForASingleItemThatLacksTheField(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('sortBy key "n" is not set on an item of type array.');

        Collection::from([['m' => 1]])->sortBy('n');
    }

    public function testReverseFlipsOrder(): void
    {
        $this->assertSame([2 => 3, 1 => 2, 0 => 1], Collection::from([1, 2, 3])->reverse()->asArray());
    }

    // -------------------------------------------------------
    // values / keys
    // -------------------------------------------------------

    public function testValuesDiscardsStringKeys(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2])->values();

        $this->assertSame([1, 2], $result->asArray());
    }

    public function testKeysReturnsKeys(): void
    {
        $result = Collection::from(['a' => 1, 'b' => 2])->keys();

        $this->assertSame(['a', 'b'], $result->asArray());
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

        $this->assertSame([1, 2, 3, 4], $original->asArray());
    }

    public function testSortDoesNotMutateOriginal(): void
    {
        $original = Collection::from([3, 1, 2]);
        $original->sort();

        $this->assertSame([3, 1, 2], $original->asArray());
    }
}
