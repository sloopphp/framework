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

    public function testGetReturnsValueByIntegerKeyIgnoringDefault(): void
    {
        $this->assertSame('b', Arr::get(['a', 'b', 'c'], 1, 'fallback'));
    }

    public function testGetReturnsDefaultForMissingIntegerKey(): void
    {
        $this->assertSame('fallback', Arr::get(['a', 'b'], 99, 'fallback'));
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

    public function testHasPrioritizesLiteralDotKey(): void
    {
        $array = ['a.b' => 'literal'];

        $this->assertTrue(Arr::has($array, 'a.b'));
    }

    public function testHasChecksNestedKeys(): void
    {
        $array = ['a' => ['b' => ['c' => 1]]];

        $this->assertTrue(Arr::has($array, 'a.b.c'));
        $this->assertFalse(Arr::has($array, 'a.b.d'));
    }

    public function testHasReturnsTrueForDeepNestedKey(): void
    {
        $array = ['a' => ['b' => 'value']];

        $this->assertTrue(Arr::has($array, 'a.b'));
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

    public function testLastReturnsLastElementWithStringKeys(): void
    {
        $this->assertSame('c', Arr::last(['x' => 'a', 'y' => 'b', 'z' => 'c']));
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

    public function testFlattenDepthOneDiffersFromDepthTwo(): void
    {
        $nested = [[[1, 2], [3]], [[4]]];

        $depth1 = Arr::flatten($nested, 1);
        $depth2 = Arr::flatten($nested, 2);

        $this->assertSame([[1, 2], [3], [4]], $depth1);
        $this->assertSame([1, 2, 3, 4], $depth2);
    }

    public function testFlattenDepthTwoPreservesThirdLevel(): void
    {
        $nested = [[[[1, 2]], [3]], [4]];

        $result = Arr::flatten($nested, 2);

        $this->assertSame([[1, 2], 3, 4], $result);
    }

    public function testFlattenDepthOnePreservesValuesOrder(): void
    {
        $array = [['a', 'b'], ['c']];

        $this->assertSame(['a', 'b', 'c'], Arr::flatten($array, 1));
    }

    public function testFlattenDepthOneDropsStringKeys(): void
    {
        $array = [['x' => 1, 'y' => 2], ['z' => 3]];

        $this->assertSame([1, 2, 3], Arr::flatten($array, 1));
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
        $this->expectExceptionMessage(
            'Pluck key \'id\' must resolve to a string or integer, got null.'
        );

        Arr::pluck(
            [['id' => 1, 'name' => 'Alice'], ['name' => 'Bob']],
            'name',
            'id',
        );
    }

    public function testPluckThrowsExceptionWhenKeyIsArray(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Pluck key \'meta\' must resolve to a string or integer, got array.'
        );

        Arr::pluck(
            [['meta' => ['nested'], 'name' => 'Alice']],
            'name',
            'meta',
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

    public function testMergeOverwritesWhenValueIsNotArray(): void
    {
        $result = Arr::merge(['key' => ['a' => 1]], ['key' => 'scalar']);

        $this->assertSame(['key' => 'scalar'], $result);
    }

    public function testMergeOverwritesWhenBaseKeyIsNotArray(): void
    {
        $result = Arr::merge(['key' => 'scalar'], ['key' => ['a' => 1]]);

        $this->assertSame(['key' => ['a' => 1]], $result);
    }

    public function testMergeOverwritesWhenBaseKeyDoesNotExist(): void
    {
        $result = Arr::merge([], ['key' => ['a' => 1]]);

        $this->assertSame(['key' => ['a' => 1]], $result);
    }

    // -------------------------------------------------------
    // getString
    // -------------------------------------------------------

    public function testGetStringReturnsStringValue(): void
    {
        $this->assertSame('hello', Arr::getString(['key' => 'hello'], 'key'));
    }

    public function testGetStringReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('fallback', Arr::getString([], 'missing', 'fallback'));
    }

    public function testGetStringReturnsEmptyStringDefault(): void
    {
        $this->assertSame('', Arr::getString([], 'missing'));
    }

    public function testGetStringReturnsDefaultForNonStringValue(): void
    {
        $this->assertSame('fallback', Arr::getString(['key' => 42], 'key', 'fallback'));
        $this->assertSame('fallback', Arr::getString(['key' => null], 'key', 'fallback'));
        $this->assertSame('fallback', Arr::getString(['key' => true], 'key', 'fallback'));
        $this->assertSame('fallback', Arr::getString(['key' => ['nested']], 'key', 'fallback'));
    }

    public function testGetStringSupportsDotNotation(): void
    {
        $this->assertSame('TestApp', Arr::getString(['app' => ['name' => 'TestApp']], 'app.name'));
    }

    public function testGetStringPreservesEmptyStringValue(): void
    {
        $this->assertSame('', Arr::getString(['key' => ''], 'key', 'fallback'));
    }

    public function testGetStringReturnsDefaultForFloatValue(): void
    {
        $this->assertSame('fallback', Arr::getString(['key' => 3.14], 'key', 'fallback'));
    }

    public function testGetStringReturnsDefaultForMissingNestedKey(): void
    {
        $this->assertSame('fallback', Arr::getString(['app' => []], 'app.name', 'fallback'));
    }

    public function testGetStringAcceptsIntegerKey(): void
    {
        $this->assertSame('value', Arr::getString([0 => 'value'], 0));
    }

    // -------------------------------------------------------
    // getInt
    // -------------------------------------------------------

    public function testGetIntReturnsIntValue(): void
    {
        $this->assertSame(42, Arr::getInt(['key' => 42], 'key'));
    }

    public function testGetIntReturnsDefaultForMissingKey(): void
    {
        $this->assertSame(99, Arr::getInt([], 'missing', 99));
    }

    public function testGetIntReturnsZeroDefault(): void
    {
        $this->assertSame(0, Arr::getInt([], 'missing'));
    }

    public function testGetIntReturnsDefaultForNonIntValue(): void
    {
        $this->assertSame(99, Arr::getInt(['key' => '42'], 'key', 99));
        $this->assertSame(99, Arr::getInt(['key' => 3.14], 'key', 99));
        $this->assertSame(99, Arr::getInt(['key' => true], 'key', 99));
        $this->assertSame(99, Arr::getInt(['key' => null], 'key', 99));
    }

    public function testGetIntPreservesZero(): void
    {
        $this->assertSame(0, Arr::getInt(['key' => 0], 'key', 99));
    }

    public function testGetIntPreservesNegativeValue(): void
    {
        $this->assertSame(-1, Arr::getInt(['key' => -1], 'key', 99));
    }

    public function testGetIntReturnsDefaultForArrayValue(): void
    {
        $this->assertSame(99, Arr::getInt(['key' => [1, 2, 3]], 'key', 99));
    }

    public function testGetIntSupportsDotNotation(): void
    {
        $this->assertSame(86400, Arr::getInt(['cors' => ['max_age' => 86400]], 'cors.max_age'));
    }

    public function testGetIntAcceptsIntegerKey(): void
    {
        $this->assertSame(42, Arr::getInt([0 => 42], 0));
    }

    // -------------------------------------------------------
    // getFloat
    // -------------------------------------------------------

    public function testGetFloatReturnsFloatValue(): void
    {
        $this->assertSame(3.14, Arr::getFloat(['key' => 3.14], 'key'));
    }

    public function testGetFloatPromotesIntToFloat(): void
    {
        $this->assertSame(42.0, Arr::getFloat(['key' => 42], 'key'));
    }

    public function testGetFloatReturnsDefaultForMissingKey(): void
    {
        $this->assertSame(1.5, Arr::getFloat([], 'missing', 1.5));
    }

    public function testGetFloatReturnsZeroDefault(): void
    {
        $this->assertSame(0.0, Arr::getFloat([], 'missing'));
    }

    public function testGetFloatReturnsDefaultForNonNumericValue(): void
    {
        $this->assertSame(1.5, Arr::getFloat(['key' => '3.14'], 'key', 1.5));
        $this->assertSame(1.5, Arr::getFloat(['key' => true], 'key', 1.5));
        $this->assertSame(1.5, Arr::getFloat(['key' => null], 'key', 1.5));
    }

    public function testGetFloatPreservesZero(): void
    {
        $this->assertSame(0.0, Arr::getFloat(['key' => 0.0], 'key', 1.5));
    }

    public function testGetFloatPreservesNegativeValue(): void
    {
        $this->assertSame(-2.5, Arr::getFloat(['key' => -2.5], 'key', 1.5));
    }

    public function testGetFloatReturnsDefaultForArrayValue(): void
    {
        $this->assertSame(1.5, Arr::getFloat(['key' => [1.0, 2.0]], 'key', 1.5));
    }

    public function testGetFloatSupportsDotNotation(): void
    {
        $this->assertSame(0.95, Arr::getFloat(['stats' => ['rate' => 0.95]], 'stats.rate'));
    }

    public function testGetFloatAcceptsIntegerKey(): void
    {
        $this->assertSame(3.14, Arr::getFloat([0 => 3.14], 0));
    }

    // -------------------------------------------------------
    // getBool
    // -------------------------------------------------------

    public function testGetBoolReturnsTrueValue(): void
    {
        $this->assertTrue(Arr::getBool(['key' => true], 'key'));
    }

    public function testGetBoolReturnsFalseValue(): void
    {
        $this->assertFalse(Arr::getBool(['key' => false], 'key', true));
    }

    public function testGetBoolReturnsDefaultForMissingKey(): void
    {
        $this->assertTrue(Arr::getBool([], 'missing', true));
    }

    public function testGetBoolReturnsFalseDefault(): void
    {
        $this->assertFalse(Arr::getBool([], 'missing'));
    }

    public function testGetBoolReturnsDefaultForNonBoolValue(): void
    {
        $this->assertTrue(Arr::getBool(['key' => 1], 'key', true));
        $this->assertTrue(Arr::getBool(['key' => 'true'], 'key', true));
        $this->assertTrue(Arr::getBool(['key' => 'yes'], 'key', true));
        $this->assertTrue(Arr::getBool(['key' => null], 'key', true));
    }

    public function testGetBoolReturnsDefaultForIntZero(): void
    {
        // Strict semantic: int 0 is not bool false, returns default (true here proves strictness)
        $this->assertTrue(Arr::getBool(['key' => 0], 'key', true));
    }

    public function testGetBoolReturnsDefaultForFloatValue(): void
    {
        $this->assertTrue(Arr::getBool(['key' => 1.0], 'key', true));
    }

    public function testGetBoolReturnsDefaultForArrayValue(): void
    {
        $this->assertTrue(Arr::getBool(['key' => []], 'key', true));
    }

    public function testGetBoolSupportsDotNotation(): void
    {
        $this->assertTrue(Arr::getBool(['cors' => ['allow_credentials' => true]], 'cors.allow_credentials'));
    }

    public function testGetBoolAcceptsIntegerKey(): void
    {
        $this->assertTrue(Arr::getBool([0 => true], 0));
    }

    // -------------------------------------------------------
    // toStringList
    // -------------------------------------------------------

    public function testToStringListReturnsListFromArrayOfStrings(): void
    {
        $this->assertSame(['php', 'api', 'sloop'], Arr::toStringList(['php', 'api', 'sloop']));
    }

    public function testToStringListFiltersOutNonStringElements(): void
    {
        $this->assertSame(['a', 'b'], Arr::toStringList(['a', 1, 'b', null, true]));
    }

    public function testToStringListReturnsListWithSequentialKeys(): void
    {
        $this->assertSame(['a', 'b', 'c'], Arr::toStringList([10 => 'a', 20 => 'b', 30 => 'c']));
    }

    public function testToStringListReturnsEmptyListForEmptyArray(): void
    {
        $this->assertSame([], Arr::toStringList([], ['default']));
    }

    /**
     * @param mixed $value Invalid (non-array) value
     */
    #[DataProvider('nonArrayValueProvider')]
    public function testToStringListReturnsDefaultForNonArrayValue(mixed $value): void
    {
        $this->assertSame(['fallback'], Arr::toStringList($value, ['fallback']));
    }

    public function testToStringListReturnsEmptyDefaultWhenNoDefaultGiven(): void
    {
        $this->assertSame([], Arr::toStringList(null));
    }

    // -------------------------------------------------------
    // stringList
    // -------------------------------------------------------

    public function testStringListReturnsStringsFromTopLevelKey(): void
    {
        $result = Arr::stringList(['tags' => ['php', 'api', 'sloop']], 'tags');

        $this->assertSame(['php', 'api', 'sloop'], $result);
    }

    public function testStringListFiltersOutNonStringElements(): void
    {
        $result = Arr::stringList(['mixed' => ['a', 1, 'b', null, 'c', true]], 'mixed');

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testStringListReturnsListWithSequentialKeys(): void
    {
        $result = Arr::stringList(['items' => [10 => 'a', 20 => 'b', 30 => 'c']], 'items');

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testStringListReturnsDefaultForMissingKey(): void
    {
        $result = Arr::stringList([], 'missing', ['fallback']);

        $this->assertSame(['fallback'], $result);
    }

    /**
     * @param mixed $value Invalid (non-array) value stored at the config key
     */
    #[DataProvider('nonArrayValueProvider')]
    public function testStringListReturnsDefaultForNonArrayValue(mixed $value): void
    {
        $result = Arr::stringList(['key' => $value], 'key', ['fallback']);

        $this->assertSame(['fallback'], $result);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonArrayValueProvider(): array
    {
        return [
            'string'  => ['not an array'],
            'integer' => [42],
            'float'   => [3.14],
            'true'    => [true],
            'false'   => [false],
            'null'    => [null],
            'object'  => [new \stdClass()],
        ];
    }

    public function testStringListAcceptsIntegerKey(): void
    {
        $result = Arr::stringList([0 => ['a', 'b', 'c']], 0);

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testStringListConvertsStringKeyedArrayToList(): void
    {
        $result = Arr::stringList([
            'tags' => ['first' => 'php', 'second' => 'api', 'third' => 'sloop'],
        ], 'tags');

        $this->assertSame(['php', 'api', 'sloop'], $result);
    }

    public function testStringListReturnsEmptyListWhenAllElementsAreNonString(): void
    {
        $result = Arr::stringList(['items' => [1, 2, 3, true, null]], 'items', ['default']);

        $this->assertSame([], $result);
    }

    public function testStringListReturnsDefaultForMissingNestedDotNotationKey(): void
    {
        $result = Arr::stringList(['a' => []], 'a.b.c', ['default']);

        $this->assertSame(['default'], $result);
    }

    public function testStringListReturnsEmptyDefaultWhenNoDefaultGiven(): void
    {
        $this->assertSame([], Arr::stringList([], 'missing'));
    }

    public function testStringListSupportsDotNotation(): void
    {
        $config = ['cors' => ['allowed_origins' => ['https://example.com', 'https://api.example.com']]];
        $result = Arr::stringList($config, 'cors.allowed_origins');

        $this->assertSame(['https://example.com', 'https://api.example.com'], $result);
    }

    public function testStringListReturnsEmptyListForEmptyArray(): void
    {
        $result = Arr::stringList(['items' => []], 'items', ['default']);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------
    // wrap
    // -------------------------------------------------------

    /**
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('wrapProvider')]
    public function testWrap(mixed $input, array $expected): void
    {
        $this->assertSame($expected, Arr::wrap($input));
    }

    /**
     * @return array<string, array{mixed, array<array-key, mixed>}>
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
        $this->expectExceptionMessage('Depth must not be negative, got -1.');

        Arr::flatten([[1, 2]], -1);
    }

    public function testFlattenThrowsExceptionForNegativeDepthIncludesValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth must not be negative, got -5.');

        Arr::flatten([[1, 2]], -5);
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
