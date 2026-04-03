<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Support\Arr;

final class ArrTest extends TestCase
{
    // -------------------------------------------------------
    // get
    // -------------------------------------------------------

    public function testGetReturnsTopLevelValue(): void
    {
        $this->assertSame('bar', Arr::get(['foo' => 'bar'], 'foo'));
    }

    public function testGetReturnsNestedValueWithDotNotation(): void
    {
        $array = ['user' => ['name' => 'Alice', 'age' => 30]];

        $this->assertSame('Alice', Arr::get($array, 'user.name'));
        $this->assertSame(30, Arr::get($array, 'user.age'));
    }

    public function testGetReturnsValueByIntegerKey(): void
    {
        $this->assertSame('a', Arr::get(['a', 'b', 'c'], 0));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('default', Arr::get([], 'missing', 'default'));
    }

    public function testGetReturnsNullForMissingNestedKey(): void
    {
        $this->assertNull(Arr::get(['a' => 1], 'a.b.c'));
    }

    public function testGetPrioritizesLiteralDotKey(): void
    {
        $array = ['user.name' => 'literal', 'user' => ['name' => 'nested']];

        $this->assertSame('literal', Arr::get($array, 'user.name'));
    }

    // -------------------------------------------------------
    // set
    // -------------------------------------------------------

    public function testSetSetsTopLevelValue(): void
    {
        $result = Arr::set([], 'key', 'value');

        $this->assertSame(['key' => 'value'], $result);
    }

    public function testSetCreatesNestedValue(): void
    {
        $result = Arr::set([], 'a.b.c', 'deep');

        $this->assertSame(['a' => ['b' => ['c' => 'deep']]], $result);
    }

    public function testSetOverwritesExistingValue(): void
    {
        $result = Arr::set(['a' => ['b' => 'old']], 'a.b', 'new');

        $this->assertSame(['a' => ['b' => 'new']], $result);
    }

    public function testSetConvertsNonArrayToArrayForNesting(): void
    {
        $result = Arr::set(['a' => 'string'], 'a.b', 'value');

        $this->assertSame(['a' => ['b' => 'value']], $result);
    }

    public function testSetDoesNotMutateOriginalArray(): void
    {
        $original = ['a' => 1];
        $result   = Arr::set($original, 'b', 2);

        $this->assertSame(['a' => 1], $original);
        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    // -------------------------------------------------------
    // has
    // -------------------------------------------------------

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->assertTrue(Arr::has(['foo' => 'bar'], 'foo'));
    }

    public function testHasChecksNestedKeys(): void
    {
        $array = ['a' => ['b' => ['c' => 1]]];

        $this->assertTrue(Arr::has($array, 'a.b.c'));
        $this->assertFalse(Arr::has($array, 'a.b.d'));
    }

    public function testHasWorksWithIntegerKeys(): void
    {
        $this->assertTrue(Arr::has(['x', 'y'], 0));
        $this->assertFalse(Arr::has(['x', 'y'], 5));
    }

    public function testHasReturnsTrueForNullValue(): void
    {
        $this->assertTrue(Arr::has(['key' => null], 'key'));
    }

    // -------------------------------------------------------
    // only
    // -------------------------------------------------------

    public function testOnlyExtractsSpecifiedKeys(): void
    {
        $array = ['a' => 1, 'b' => 2, 'c' => 3];

        $this->assertSame(['a' => 1, 'c' => 3], Arr::only($array, ['a', 'c']));
    }

    public function testOnlyIgnoresMissingKeys(): void
    {
        $this->assertSame(['a' => 1], Arr::only(['a' => 1, 'b' => 2], ['a', 'z']));
    }

    // -------------------------------------------------------
    // except
    // -------------------------------------------------------

    public function testExceptExcludesSpecifiedKeys(): void
    {
        $array = ['a' => 1, 'b' => 2, 'c' => 3];

        $this->assertSame(['b' => 2], Arr::except($array, ['a', 'c']));
    }

    // -------------------------------------------------------
    // first
    // -------------------------------------------------------

    public function testFirstReturnsFirstElement(): void
    {
        $this->assertSame(1, Arr::first([1, 2, 3]));
    }

    public function testFirstReturnsMatchingElementByCallback(): void
    {
        $result = Arr::first([1, 2, 3, 4], fn ($v) => $v > 2);

        $this->assertSame(3, $result);
    }

    public function testFirstReturnsDefaultForEmptyArray(): void
    {
        $this->assertSame('none', Arr::first([], default: 'none'));
    }

    #[DataProvider('noMatchProvider')]
    public function testFirstReturnsDefaultWhenNoMatch(int $threshold): void
    {
        $result = Arr::first([1, 2, 3], fn (int $v) => $v > $threshold, 'nope');

        $this->assertSame('nope', $result);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function noMatchProvider(): array
    {
        return [
            'threshold exceeds all values' => [100],
        ];
    }

    // -------------------------------------------------------
    // last
    // -------------------------------------------------------

    public function testLastReturnsLastElement(): void
    {
        $this->assertSame(3, Arr::last([1, 2, 3]));
    }

    public function testLastReturnsLastMatchingElementByCallback(): void
    {
        $result = Arr::last([1, 2, 3, 4], fn ($v) => $v < 3);

        $this->assertSame(2, $result);
    }

    public function testLastReturnsDefaultForEmptyArray(): void
    {
        $this->assertSame('none', Arr::last([], default: 'none'));
    }

    // -------------------------------------------------------
    // flatten
    // -------------------------------------------------------

    public function testFlattenFlattensMultiDimensionalArray(): void
    {
        $this->assertSame([1, 2, 3, 4], Arr::flatten([[1, 2], [3, [4]]]));
    }

    public function testFlattenRespectsDepthLimit(): void
    {
        $this->assertSame([1, 2, 3, [4]], Arr::flatten([[1, 2], [3, [4]]], 1));
    }

    public function testFlattenReturnsFlatArrayAsIs(): void
    {
        $this->assertSame([1, 2, 3], Arr::flatten([1, 2, 3]));
    }

    // -------------------------------------------------------
    // pluck
    // -------------------------------------------------------

    public function testPluckExtractsValues(): void
    {
        $array = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $this->assertSame(['Alice', 'Bob'], Arr::pluck($array, 'name'));
    }

    public function testPluckExtractsValuesWithCustomKey(): void
    {
        $array = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $this->assertSame([1 => 'Alice', 2 => 'Bob'], Arr::pluck($array, 'name', 'id'));
    }

    public function testPluckSupportsNestedDotNotation(): void
    {
        $array = [
            ['user' => ['name' => 'Alice']],
            ['user' => ['name' => 'Bob']],
        ];

        $this->assertSame(['Alice', 'Bob'], Arr::pluck($array, 'user.name'));
    }

    public function testPluckThrowsExceptionWhenKeyIsMissing(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Pluck key \'id\' must resolve to a string or integer');

        Arr::pluck(
            [['id' => 1, 'name' => 'Alice'], ['name' => 'Bob']],
            'name',
            'id',
        );
    }

    // -------------------------------------------------------
    // merge
    // -------------------------------------------------------

    public function testMergeMergesRecursively(): void
    {
        $a = ['config' => ['debug' => true, 'log' => 'file']];
        $b = ['config' => ['log' => 'syslog', 'cache' => true]];

        $expected = ['config' => ['debug' => true, 'log' => 'syslog', 'cache' => true]];

        $this->assertSame($expected, Arr::merge($a, $b));
    }

    public function testMergeAppendsNumericKeys(): void
    {
        $this->assertSame([1, 2, 3, 4], Arr::merge([1, 2], [3, 4]));
    }

    public function testMergeMergesMultipleArrays(): void
    {
        $result = Arr::merge(['a' => 1], ['b' => 2], ['c' => 3]);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $result);
    }

    // -------------------------------------------------------
    // wrap
    // -------------------------------------------------------

    /**
     * @param array<mixed> $expected
     */
    #[DataProvider('wrapProvider')]
    public function testWrap(mixed $input, array $expected): void
    {
        $this->assertSame($expected, Arr::wrap($input));
    }

    /**
     * @return array<string, array{mixed, array<mixed>}>
     */
    public static function wrapProvider(): array
    {
        return [
            'array remains as is'        => [['a', 'b'], ['a', 'b']],
            'scalar wraps into array'    => ['hello', ['hello']],
            'null becomes empty array'   => [null, []],
            'integer wraps into array'   => [42, [42]],
        ];
    }

    // -------------------------------------------------------
    // Boundary values
    // -------------------------------------------------------

    public function testGetReturnsNullForEmptyStringKey(): void
    {
        $this->assertNull(Arr::get(['a' => 1], ''));
    }

    public function testGetReturnsValueForEmptyStringKey(): void
    {
        $this->assertSame('empty_key', Arr::get(['' => 'empty_key'], ''));
    }

    public function testHasReturnsFalseForEmptyArray(): void
    {
        $this->assertFalse(Arr::has([], 'a'));
    }

    public function testOnlyReturnsEmptyForEmptyArray(): void
    {
        $this->assertSame([], Arr::only([], ['a']));
    }

    public function testOnlyReturnsEmptyForEmptyKeys(): void
    {
        $this->assertSame([], Arr::only(['a' => 1], []));
    }

    public function testExceptReturnsEmptyForEmptyArray(): void
    {
        $this->assertSame([], Arr::except([], ['a']));
    }

    public function testExceptReturnsAllForEmptyKeys(): void
    {
        $this->assertSame(['a' => 1], Arr::except(['a' => 1], []));
    }

    public function testFlattenReturnsEmptyForEmptyArray(): void
    {
        $this->assertSame([], Arr::flatten([]));
    }

    public function testFlattenWithDepthZeroDoesNotFlatten(): void
    {
        $this->assertSame([[1, 2], [3]], Arr::flatten([[1, 2], [3]], 0));
    }

    public function testPluckReturnsNullsForMissingKey(): void
    {
        $result = Arr::pluck([['a' => 1], ['b' => 2]], 'x');

        $this->assertSame([null, null], $result);
    }

    public function testMergeReturnsEmptyForTwoEmptyArrays(): void
    {
        $this->assertSame([], Arr::merge([], []));
    }

    public function testFlattenThrowsExceptionForNegativeDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth must not be negative');

        Arr::flatten([[1, 2]], -1);
    }

    public function testFirstReturnsNullForEmptyArrayWithoutDefault(): void
    {
        $this->assertSame(null, Arr::first([]));
    }

    public function testLastReturnsNullForEmptyArrayWithoutDefault(): void
    {
        $this->assertSame(null, Arr::last([]));
    }
}
