<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\Rule;
use Sloop\Validation\ValidationError;

final class NumberRulesTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    // ---------------------------------------------------------------
    // Rule::int()
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function acceptedInts(): iterable
    {
        yield 'JSON int' => [42, 42];
        yield 'negative JSON int' => [-3, -3];
        yield 'digits' => ['42', 42];
        yield 'leading zeros' => ['042', 42];
        yield 'zero' => ['0', 0];
        yield 'zeros only' => ['000', 0];
        yield 'negative zero' => ['-0', 0];
        yield 'negative with leading zeros' => ['-007', -7];
        yield 'int max' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'int min' => [(string) PHP_INT_MIN, PHP_INT_MIN];
    }

    #[DataProvider('acceptedInts')]
    public function testIntAccepts(mixed $input, int $expected): void
    {
        $this->assertSame($expected, self::valueOf(Rule::int(), $input));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedInts(): iterable
    {
        yield 'surrounding space' => [' 42'];
        yield 'plus sign' => ['+42'];
        yield 'decimal point' => ['4.2'];
        yield 'exponent' => ['1e3'];
        yield 'above int max' => ['9223372036854775808'];
        yield 'below int min' => ['-9223372036854775809'];
        yield 'JSON float' => [42.0];
        yield 'bool' => [true];
        yield 'letters' => ['abc'];
        yield 'minus only' => ['-'];
        yield 'array' => [[1]];
    }

    #[DataProvider('rejectedInts')]
    public function testIntRejects(mixed $input): void
    {
        self::assertErrorSame(new ValidationError('int', [], 'The v field must be an integer.'), self::onlyError(Rule::int(), $input));
    }

    public function testIntBounds(): void
    {
        $this->assertSame([], self::failedRules(Rule::int()->min(10), 10));
        $this->assertSame(['min'], self::failedRules(Rule::int()->min(10), 9));
        $this->assertSame([], self::failedRules(Rule::int()->max(10), 10));
        $this->assertSame(['max'], self::failedRules(Rule::int()->max(10), 11));

        $between = Rule::int()->between(1, 3);
        $this->assertSame([], self::failedRules($between, 1));
        $this->assertSame([], self::failedRules($between, 3));
        $this->assertSame(['between'], self::failedRules($between, 0));
        $this->assertSame(['between'], self::failedRules($between, 4));
    }

    public function testIntBoundErrors(): void
    {
        self::assertErrorSame(new ValidationError('min', ['min' => 1000], 'The v field must be at least 1000.'), self::onlyError(Rule::int()->min(1000), 1));
        self::assertErrorSame(new ValidationError('max', ['max' => 1], 'The v field must not be greater than 1.'), self::onlyError(Rule::int()->max(1), 2));
        self::assertErrorSame(
            new ValidationError('between', ['min' => 1, 'max' => 3], 'The v field must be between 1 and 3.'),
            self::onlyError(Rule::int()->between(1, 3), 5),
        );
    }

    public function testIntBetweenWithEqualBoundsIsAccepted(): void
    {
        $this->assertSame([], self::failedRules(Rule::int()->between(2, 2), 2));
    }

    public function testIntBetweenWithReversedBoundsThrows(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::int()->between(3, 1));

        $this->assertSame('between() needs min <= max, got 3 and 1.', $e->getMessage());
    }

    public function testIntInAndNotIn(): void
    {
        $this->assertSame([], self::failedRules(Rule::int()->in([1, 2]), '2'));
        $this->assertSame(['in'], self::failedRules(Rule::int()->in([1, 2]), 3));
        $this->assertSame([], self::failedRules(Rule::int()->notIn([1, 2]), 3));
        $this->assertSame(['notIn'], self::failedRules(Rule::int()->notIn([1, 2]), '01'));
    }

    public function testIntListErrorsCarryTheValues(): void
    {
        self::assertErrorSame(new ValidationError('in', ['values' => [1, 2]], 'The selected v is invalid.'), self::onlyError(Rule::int()->in([1, 2]), 3));
        self::assertErrorSame(new ValidationError('notIn', ['values' => [1]], 'The selected v is invalid.'), self::onlyError(Rule::int()->notIn([1]), 1));
    }

    public function testIntEmptyListsThrow(): void
    {
        $this->assertSame('in() needs at least one value.', $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::int()->in([]))->getMessage());
        $this->assertSame('notIn() needs at least one value.', $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::int()->notIn([]))->getMessage());
    }

    // ---------------------------------------------------------------
    // Rule::float()
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function acceptedFloats(): iterable
    {
        yield 'JSON float' => [1.5, 1.5];
        yield 'JSON int' => [2, 2.0];
        yield 'digits' => ['3', 3.0];
        yield 'fraction' => ['-0.25', -0.25];
        yield 'leading zeros' => ['007.5', 7.5];
    }

    #[DataProvider('acceptedFloats')]
    public function testFloatAccepts(mixed $input, float $expected): void
    {
        $this->assertSame($expected, self::valueOf(Rule::float(), $input));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedFloats(): iterable
    {
        yield 'no integer part' => ['.5'];
        yield 'no fraction' => ['1.'];
        yield 'exponent' => ['1e3'];
        yield 'plus sign' => ['+1'];
        yield 'NAN' => [NAN];
        yield 'INF' => [INF];
        yield 'overflowing string' => [str_repeat('9', 400)];
        yield 'bool' => [false];
        yield 'letters' => ['abc'];
    }

    #[DataProvider('rejectedFloats')]
    public function testFloatRejects(mixed $input): void
    {
        self::assertErrorSame(new ValidationError('float', [], 'The v field must be a number.'), self::onlyError(Rule::float(), $input));
    }

    public function testFloatBounds(): void
    {
        $this->assertSame([], self::failedRules(Rule::float()->min(0.5), 0.5));
        $this->assertSame(['min'], self::failedRules(Rule::float()->min(0.5), 0.49));
        $this->assertSame([], self::failedRules(Rule::float()->max(1), 1));
        $this->assertSame(['max'], self::failedRules(Rule::float()->max(1), 1.01));

        $between = Rule::float()->between(-1, 1.5);
        $this->assertSame([], self::failedRules($between, -1));
        $this->assertSame([], self::failedRules($between, 1.5));
        $this->assertSame(['between'], self::failedRules($between, 1.6));
        $this->assertSame(['between'], self::failedRules($between, -1.1));
    }

    public function testFloatBoundErrorKeepsFractionDigits(): void
    {
        self::assertErrorSame(
            new ValidationError('min', ['min' => 0.0001], 'The v field must be at least 0.0001.'),
            self::onlyError(Rule::float()->min(0.0001), 0),
        );
        self::assertErrorSame(
            new ValidationError('between', ['min' => 0.5, 'max' => 2], 'The v field must be between 0.5 and 2.'),
            self::onlyError(Rule::float()->between(0.5, 2), 3),
        );
        self::assertErrorSame(
            new ValidationError('max', ['max' => 1.25], 'The v field must not be greater than 1.25.'),
            self::onlyError(Rule::float()->max(1.25), 2),
        );
        self::assertErrorSame(new ValidationError('in', ['values' => [1, 2.5]], 'The selected v is invalid.'), self::onlyError(Rule::float()->in([1, 2.5]), 3));
        self::assertErrorSame(new ValidationError('notIn', ['values' => [1]], 'The selected v is invalid.'), self::onlyError(Rule::float()->notIn([1]), 1));
    }

    public function testFloatBetweenWithEqualBoundsIsAccepted(): void
    {
        $this->assertSame([], self::failedRules(Rule::float()->between(1.5, 1.5), 1.5));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidFloatDeclarations(): iterable
    {
        yield 'NAN min' => [static fn () => Rule::float()->min(NAN), 'A bound must be a finite number.'];
        yield 'INF max' => [static fn () => Rule::float()->max(INF), 'A bound must be a finite number.'];
        yield 'NAN in between' => [static fn () => Rule::float()->between(0, NAN), 'A bound must be a finite number.'];
        yield 'reversed between' => [static fn () => Rule::float()->between(2, 1.5), 'between() needs min <= max, got 2 and 1.5.'];
        yield 'empty in' => [static fn () => Rule::float()->in([]), 'in() needs at least one value.'];
        yield 'empty notIn' => [static fn () => Rule::float()->notIn([]), 'notIn() needs at least one value.'];
        yield 'INF in list' => [static fn () => Rule::float()->in([1, INF]), 'A bound must be a finite number.'];
    }

    /**
     * @param \Closure(): mixed $declare
     */
    #[DataProvider('invalidFloatDeclarations')]
    public function testInvalidFloatDeclarationThrows(\Closure $declare, string $message): void
    {
        $this->assertSame($message, $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage());
    }

    public function testFloatInComparesIntCandidatesAsFloats(): void
    {
        $this->assertSame([], self::failedRules(Rule::float()->in([1, 2.5]), '1'));
        $this->assertSame(['in'], self::failedRules(Rule::float()->in([1, 2.5]), 2));
        $this->assertSame([], self::failedRules(Rule::float()->notIn([1]), 1.5));
        $this->assertSame(['notIn'], self::failedRules(Rule::float()->notIn([1]), '1.0'));
    }

    // ---------------------------------------------------------------
    // Rule::bool()
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function acceptedBools(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'string true' => ['true', true];
        yield 'string FALSE' => ['FALSE', false];
        yield 'string True' => ['True', true];
    }

    #[DataProvider('acceptedBools')]
    public function testBoolAccepts(mixed $input, bool $expected): void
    {
        $this->assertSame($expected, self::valueOf(Rule::bool(), $input));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedBools(): iterable
    {
        yield 'on' => ['on'];
        yield 'yes' => ['yes'];
        yield 'int 2' => [2];
        yield 'float 1' => [1.0];
        yield 'string with space' => ['true '];
    }

    #[DataProvider('rejectedBools')]
    public function testBoolRejects(mixed $input): void
    {
        self::assertErrorSame(new ValidationError('bool', [], 'The v field must be true or false.'), self::onlyError(Rule::bool(), $input));
    }

    public function testDefaultsOfEachType(): void
    {
        $this->assertSame(1.5, self::valueOf(Rule::float()->default(1.5), null));
        $this->assertTrue(self::valueOf(Rule::bool()->default(true), null));
        $this->assertSame('x', self::valueOf(Rule::string()->default('x'), ''));
    }
}
