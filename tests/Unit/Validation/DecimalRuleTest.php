<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use BcMath\Number;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\Rule;
use Sloop\Validation\Sanitize;
use Sloop\Validation\ValidationError;
use Sloop\Validation\Validator;

final class DecimalRuleTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function acceptedValues(): iterable
    {
        yield 'string padded to scale' => ['19.9', '19.90'];
        yield 'string at scale' => ['19.99', '19.99'];
        yield 'integer string' => ['5', '5.00'];
        yield 'leading zeros' => ['019.90', '19.90'];
        yield 'zero fraction only' => ['0.5', '0.50'];
        yield 'negative' => ['-3.5', '-3.50'];
        yield 'negative zero' => ['-0.00', '0.00'];
        yield 'negative zero integer' => ['-0', '0.00'];
        yield 'max integer digits' => ['99999999.99', '99999999.99'];
        yield 'JSON int' => [7, '7.00'];
        yield 'negative JSON int' => [-7, '-7.00'];
        yield 'JSON float' => [19.9, '19.90'];
        yield 'JSON float at scale' => [0.01, '0.01'];
        yield 'JSON float negative zero' => [-0.0, '0.00'];
    }

    #[DataProvider('acceptedValues')]
    public function testAcceptsAndPadsToScale(mixed $input, string $expected): void
    {
        $this->assertSame($expected, self::valueOf(Rule::decimal(10, 2), $input));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'too many fraction digits' => ['19.999'];
        yield 'trailing zero beyond scale' => ['1.500'];
        yield 'too many integer digits' => ['100000000.00'];
        yield 'no integer part' => ['.5'];
        yield 'no fraction' => ['1.'];
        yield 'exponent' => ['1e3'];
        yield 'plus sign' => ['+1'];
        yield 'space' => [' 1'];
        yield 'JSON float beyond scale' => [19.999];
        yield 'JSON float rounding up' => [0.005];
        yield 'NAN' => [NAN];
        yield 'INF' => [INF];
        yield 'bool' => [true];
        yield 'array' => [['1']];
        yield 'JSON int with too many digits' => [123456789];
    }

    #[DataProvider('rejectedValues')]
    public function testRejects(mixed $input): void
    {
        $this->assertEquals(
            new ValidationError('decimal', ['precision' => 10, 'scale' => 2], 'The v field must be a number with at most 10 digits, 2 of them after the decimal point.'),
            self::onlyError(Rule::decimal(10, 2), $input),
        );
    }

    public function testJsonFloatIsRejectedAbovePrecisionFifteen(): void
    {
        $this->assertSame('1.50', self::valueOf(Rule::decimal(15, 2), 1.5));
        $this->assertSame(['decimal'], self::failedRules(Rule::decimal(16, 2), 1.5));
        $this->assertSame('1.50', self::valueOf(Rule::decimal(16, 2), '1.5'));
    }

    public function testJsonFloatNeedingMoreDigitsThanTheFloatHoldsIsRejected(): void
    {
        $this->assertSame(['decimal'], self::failedRules(Rule::decimal(15, 1), 0.1 + 0.2));
    }

    public function testScaleZero(): void
    {
        $this->assertSame('42', self::valueOf(Rule::decimal(5, 0), '042'));
        $this->assertSame('3', self::valueOf(Rule::decimal(5, 0), 3.0));
        $this->assertSame(['decimal'], self::failedRules(Rule::decimal(5, 0), '1.0'));
    }

    public function testLongValuesKeepAllDigits(): void
    {
        $this->assertSame('12345678901234567.89', self::valueOf(Rule::decimal(19, 2), '12345678901234567.89'));
    }

    public function testSanitizersFollowTheTypeArguments(): void
    {
        $this->assertSame('1.50', self::valueOf(Rule::decimal(10, 2, Sanitize::Trim), ' 1.5 '));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'precision zero' => [0, 0];
        yield 'negative scale' => [5, -1];
        yield 'scale above precision' => [2, 3];
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitsThrow(int $precision, int $scale): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::decimal($precision, $scale));

        $this->assertSame(
            'decimal() needs precision >= 1 and 0 <= scale <= precision, got ' . $precision . ' and ' . $scale . '.',
            $e->getMessage(),
        );
    }

    public function testPrecisionOneIsAccepted(): void
    {
        $this->assertSame('7', self::valueOf(Rule::decimal(1, 0), '7'));
    }

    public function testScaleEqualToPrecisionIsAccepted(): void
    {
        $this->assertSame('0.25', self::valueOf(Rule::decimal(2, 2), '0.25'));
        $this->assertSame(['decimal'], self::failedRules(Rule::decimal(2, 2), '1.00'));
    }

    // ---------------------------------------------------------------
    // Bounds
    // ---------------------------------------------------------------

    public function testMinAndMaxCompareExactly(): void
    {
        $min = Rule::decimal(20, 2)->min('0.01');
        $this->assertSame([], self::failedRules($min, '0.01'));
        $this->assertSame(['min'], self::failedRules($min, '0.00'));
        $this->assertSame(['min'], self::failedRules($min, '-5'));

        $max = Rule::decimal(20, 2)->max(100);
        $this->assertSame([], self::failedRules($max, '100.00'));
        $this->assertSame(['max'], self::failedRules($max, '100.01'));
        $this->assertSame([], self::failedRules($max, '-100.01'));
    }

    public function testSignDecidesBeforeMagnitude(): void
    {
        $this->assertSame(['min'], self::failedRules(Rule::decimal(10, 2)->min('5'), '-0.01'));
        $this->assertSame([], self::failedRules(Rule::decimal(10, 2)->max('-5'), '-5.01'));
        $this->assertSame(['max'], self::failedRules(Rule::decimal(10, 2)->max('-5'), '0.01'));
    }

    public function testBoundsBeyondFloatPrecisionAreCompared(): void
    {
        $rule = Rule::decimal(20, 2)->max('12345678901234567.89');

        $this->assertSame([], self::failedRules($rule, '12345678901234567.89'));
        $this->assertSame(['max'], self::failedRules($rule, '12345678901234567.90'));
    }

    public function testNegativeBounds(): void
    {
        $rule = Rule::decimal(10, 2)->between('-10.5', '-1');

        $this->assertSame([], self::failedRules($rule, '-10.50'));
        $this->assertSame([], self::failedRules($rule, '-1'));
        $this->assertSame(['between'], self::failedRules($rule, '-10.51'));
        $this->assertSame(['between'], self::failedRules($rule, '-0.99'));
        $this->assertSame(['between'], self::failedRules($rule, '0'));
    }

    public function testBoundErrorsKeepTheDeclaredForm(): void
    {
        $this->assertEquals(
            new ValidationError('between', ['min' => '0.01', 'max' => 100], 'The v field must be between 0.01 and 100.'),
            self::onlyError(Rule::decimal(10, 2)->between('0.01', 100), '0'),
        );
        $this->assertEquals(
            new ValidationError('min', ['min' => '0.5'], 'The v field must be at least 0.5.'),
            self::onlyError(Rule::decimal(10, 2)->min('0.5'), '0'),
        );
        $this->assertEquals(
            new ValidationError('max', ['max' => 1], 'The v field must not be greater than 1.'),
            self::onlyError(Rule::decimal(10, 2)->max(1), '2'),
        );
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidBounds(): iterable
    {
        yield 'min' => [static fn () => Rule::decimal(10, 2)->min('1e3'), 'A decimal bound must be of the form -?\d+(\.\d+)?, got 1e3.'];
        yield 'max' => [static fn () => Rule::decimal(10, 2)->max('.5'), 'A decimal bound must be of the form -?\d+(\.\d+)?, got .5.'];
        yield 'between lower' => [static fn () => Rule::decimal(10, 2)->between('x', 1), 'A decimal bound must be of the form -?\d+(\.\d+)?, got x.'];
        yield 'between upper' => [static fn () => Rule::decimal(10, 2)->between(1, 'x'), 'A decimal bound must be of the form -?\d+(\.\d+)?, got x.'];
        yield 'reversed between' => [static fn () => Rule::decimal(10, 2)->between('1.01', 1), 'between() needs min <= max, got 1.01 and 1.'];
    }

    /**
     * @param \Closure(): mixed $declare
     */
    #[DataProvider('invalidBounds')]
    public function testInvalidBoundThrows(\Closure $declare, string $message): void
    {
        $this->assertSame($message, $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage());
    }

    public function testBetweenWithEqualBoundsWrittenDifferently(): void
    {
        $this->assertSame([], self::failedRules(Rule::decimal(10, 2)->between('1.0', 1), '1'));
    }

    // ---------------------------------------------------------------
    // Default and asNumber()
    // ---------------------------------------------------------------

    public function testDefaultIsPaddedToScale(): void
    {
        $this->assertSame('0.00', self::valueOf(Rule::decimal(10, 2)->default(0), null));
        $this->assertSame('1.50', self::valueOf(Rule::decimal(10, 2)->default('1.5'), ''));
        $this->assertSame('1.25', self::valueOf(Rule::decimal(10, 2)->default('1.25'), null));
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function invalidDefaults(): iterable
    {
        yield 'not a decimal' => ['abc'];
        yield 'too many fraction digits' => ['1.234'];
    }

    #[DataProvider('invalidDefaults')]
    public function testInvalidDefaultThrows(int|string $default): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::decimal(10, 2)->default($default));

        $this->assertSame(
            'The default of decimal(10, 2) must be a decimal with at most 2 fraction digits, got ' . $default . '.',
            $e->getMessage(),
        );
    }

    public function testAsNumberReturnsBcMathNumberWithTheScale(): void
    {
        $value = self::valueOf(Rule::decimal(10, 2)->asNumber(), '19.9');

        $this->assertInstanceOf(Number::class, $value);
        $this->assertSame('19.90', (string) $value);
        $this->assertSame(2, $value->scale);
    }

    public function testAsNumberAppliesToTheDefault(): void
    {
        $value = self::valueOf(Rule::decimal(10, 2)->default('5')->asNumber(), null);

        $this->assertInstanceOf(Number::class, $value);
        $this->assertSame('5.00', (string) $value);
    }

    public function testAsNumberLeavesTheOriginalReturningStrings(): void
    {
        $base = Rule::decimal(10, 2);
        $base->asNumber();

        $this->assertSame('1.00', self::valueOf($base, 1));
    }

    public function testEmptyAsNumberFieldWithoutDefaultIsNull(): void
    {
        $this->assertNull(self::valueOf(Rule::decimal(10, 2)->asNumber(), null));
    }

    public function testSameComparesDecimalsByValue(): void
    {
        $result = new Validator([
            'a' => Rule::decimal(10, 2)->asNumber()->same('b'),
            'b' => Rule::decimal(10, 2)->asNumber(),
        ])->validate(['a' => '1.5', 'b' => 1.5]);

        $this->assertFalse($result->failed());
    }

    public function testSameComparesADefaultDecimalByValue(): void
    {
        $result = new Validator([
            'a' => Rule::decimal(10, 2)->asNumber()->same('b'),
            'b' => Rule::decimal(10, 2)->asNumber()->default('2'),
        ])->validate(['a' => '2']);

        $this->assertFalse($result->failed());
    }
}
