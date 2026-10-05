<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Support\TimeOfDay;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\Rule;
use Sloop\Validation\Sanitize;
use Sloop\Validation\ValidationError;
use Sloop\Validation\Validator;

final class TimeRuleTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    private static function timeOf(mixed $value): string
    {
        self::assertInstanceOf(TimeOfDay::class, $value);

        return (string) $value;
    }

    // -------------------------------------------------------
    // reading the input
    // -------------------------------------------------------

    public function testValueIsATimeOfDayReadWithTheDefaultFormat(): void
    {
        $this->assertSame('09:30:00', self::timeOf(self::valueOf(Rule::time(), '09:30')));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedByHourAndMinute(): iterable
    {
        yield 'unpadded hour' => ['9:30'];
        yield 'hour 24' => ['24:00'];
        yield 'hour 25' => ['25:00'];
        yield 'minute 60' => ['09:60'];
        yield 'seconds the format does not write' => ['09:30:00'];
        yield 'trailing text' => ['09:30 x'];
        yield 'leading space' => [' 09:30'];
        yield 'not a time' => ['abc'];
        yield 'null byte' => ["09:30\0"];
        yield 'integer' => [930];
        yield 'array' => [['09:30']];
    }

    #[DataProvider('rejectedByHourAndMinute')]
    public function testInputOutsideTheDeclaredFormatIsATypeFailure(mixed $input): void
    {
        self::assertErrorSame(
            new ValidationError('time', [], 'The v field must be a valid time.'),
            self::onlyError(Rule::time(), $input),
        );
    }

    public function testLeapSecondIsATypeFailure(): void
    {
        $this->assertSame(['time'], self::failedRules(Rule::time('H:i:s'), '23:59:60'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function acceptedFormats(): iterable
    {
        yield 'unpadded hour' => ['G:i', '9:30', '09:30:00'];
        yield '12-hour, afternoon' => ['g:i A', '3:30 PM', '15:30:00'];
        yield '12-hour, midnight' => ['h:i a', '12:05 am', '00:05:00'];
        yield 'with seconds' => ['H:i:s', '23:59:59', '23:59:59'];
        yield 'escaped letters' => ['H\hi', '09h30', '09:30:00'];
        yield 'escaped u is a letter' => ['H:i\u', '09:30u', '09:30:00'];
        yield 'hour only' => ['H', '07', '07:00:00'];
        yield 'two-digit hour run into the minute' => ['Hi', '0930', '09:30:00'];
        yield 'one-digit hour alone' => ['G', '7', '07:00:00'];
        yield 'no time written' => ['\T\B\D', 'TBD', '00:00:00'];
    }

    #[DataProvider('acceptedFormats')]
    public function testFormatArgumentChangesWhatIsAccepted(string $format, string $input, string $expected): void
    {
        $this->assertSame($expected, self::timeOf(self::valueOf(Rule::time($format), $input)));
    }

    public function testSanitizersRunBeforeTheFormatIsChecked(): void
    {
        $this->assertSame('09:30:00', self::timeOf(self::valueOf(Rule::time('H:i', Sanitize::Trim), "  09:30\n")));
    }

    // -------------------------------------------------------
    // fractions of a second
    // -------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function acceptedFractions(): iterable
    {
        yield 'u, one digit' => ['H:i:s.u', '09:30:00.5', '09:30:00.500000'];
        yield 'u, leading zero' => ['H:i:s.u', '09:30:00.05', '09:30:00.050000'];
        yield 'u, three digits' => ['H:i:s.u', '09:30:00.123', '09:30:00.123000'];
        yield 'u, six digits' => ['H:i:s.u', '09:30:00.123456', '09:30:00.123456'];
        yield 'u, trailing zeros' => ['H:i:s.u', '09:30:00.500', '09:30:00.500000'];
        yield 'v, one digit' => ['H:i:s.v', '09:30:00.5', '09:30:00.500000'];
        yield 'v, three digits' => ['H:i:s.v', '09:30:00.123', '09:30:00.123000'];
        yield 'u, then text' => ['H:i:s.u\Z', '09:30:00.25Z', '09:30:00.250000'];
        yield 'v, then text' => ['H:i:s.v\Z', '09:30:00.25Z', '09:30:00.250000'];
    }

    #[DataProvider('acceptedFractions')]
    public function testFractionTakesAsManyDigitsAsPhpReads(string $format, string $input, string $expected): void
    {
        $this->assertSame($expected, self::timeOf(self::valueOf(Rule::time($format), $input)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedFractions(): iterable
    {
        yield 'u, seven digits' => ['H:i:s.u', '09:30:00.1234567'];
        yield 'u, no digits' => ['H:i:s.u', '09:30:00.'];
        yield 'u, missing' => ['H:i:s.u', '09:30:00'];
        yield 'v, four digits' => ['H:i:s.v', '09:30:00.1234'];
        yield 'u, unpadded hour' => ['H:i:s.u', '9:30:00.5'];
    }

    #[DataProvider('rejectedFractions')]
    public function testFractionOutsideWhatPhpReadsIsATypeFailure(string $format, string $input): void
    {
        $this->assertSame(['time'], self::failedRules(Rule::time($format), $input));
    }

    // -------------------------------------------------------
    // declaring the format
    // -------------------------------------------------------

    public function testEmptyFormatIsRejectedWhenDeclared(): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::time(''));

        $this->assertSame('time() needs a format.', $thrown->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedFormats(): iterable
    {
        $dateOrZone = static fn (string $format): string => 'time() is for a time of day, and \'' . $format
            . '\' carries a date or a time zone. Use date() or dateTime().';

        yield 'date and time' => ['Y-m-d H:i', $dateOrZone('Y-m-d H:i')];
        yield 'zone abbreviation' => ['H:i T', $dateOrZone('H:i T')];
        yield 'offset' => ['H:iP', $dateOrZone('H:iP')];
        yield 'unix timestamp' => ['U', $dateOrZone('U')];
        yield 'fraction written twice' => ['H:i:s.u v', 'time() needs a format that reads back what it writes, and \'H:i:s.u v\' does not.'];
        yield 'one-digit hour run into the minute' => ['Gi', 'time() needs a format that reads back what it writes, and \'Gi\' does not.'];
        yield 'one-digit hour run into the minute and second' => ['Gis', 'time() needs a format that reads back what it writes, and \'Gis\' does not.'];
        yield 'one-digit 12-hour run into the minute' => ['gi', 'time() needs a format that reads back what it writes, and \'gi\' does not.'];
        yield 'one-digit 12-hour with meridiem run into the minute' => ['gis A', 'time() needs a format that reads back what it writes, and \'gis A\' does not.'];
        yield 'one-digit hour run into a 12-hour hour, wrong only at midnight' => ['Gha', 'time() needs a format that reads back what it writes, and \'Gha\' does not.'];
        yield 'lone backslash' => ['H:i\\', 'time() needs a format that reads back what it writes, and \'H:i\\\' does not.'];
    }

    #[DataProvider('refusedFormats')]
    public function testFormatIsRefusedWhenTheFieldCouldNotHonourIt(string $format, string $message): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::time($format));

        $this->assertSame($message, $thrown->getMessage());
    }

    // -------------------------------------------------------
    // comparisons
    // -------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'before, earlier' => ['before', '09:29', true];
        yield 'before, same' => ['before', '09:30', false];
        yield 'before, later' => ['before', '09:31', false];
        yield 'beforeOrEqual, earlier' => ['beforeOrEqual', '09:29', true];
        yield 'beforeOrEqual, same' => ['beforeOrEqual', '09:30', true];
        yield 'beforeOrEqual, later' => ['beforeOrEqual', '09:31', false];
        yield 'after, earlier' => ['after', '09:29', false];
        yield 'after, same' => ['after', '09:30', false];
        yield 'after, later' => ['after', '09:31', true];
        yield 'afterOrEqual, earlier' => ['afterOrEqual', '09:29', false];
        yield 'afterOrEqual, same' => ['afterOrEqual', '09:30', true];
        yield 'afterOrEqual, later' => ['afterOrEqual', '09:31', true];
    }

    #[DataProvider('comparisons')]
    public function testComparisonsOrderTheValueAgainstTheLimit(string $method, string $input, bool $passes): void
    {
        $limit = TimeOfDay::of(9, 30);
        $rule  = match ($method) {
            'before' => Rule::time()->before($limit),
            'beforeOrEqual' => Rule::time()->beforeOrEqual($limit),
            'after' => Rule::time()->after($limit),
            default => Rule::time()->afterOrEqual($limit),
        };

        $this->assertSame($passes ? [] : [$method], self::failedRules($rule, $input));
    }

    public function testComparisonOrdersByTheClockRatherThanByTheWrittenText(): void
    {
        // As text, '9:05 PM' sorts before '10:00 AM'.
        $rule = Rule::time('g:i A')->after(TimeOfDay::of(10, 0));

        $this->assertSame([], self::failedRules($rule, '9:05 PM'));
    }

    public function testComparisonErrorNamesTheLimitInTheDeclaredFormat(): void
    {
        self::assertErrorSame(
            new ValidationError('before', ['limit' => '9:30 AM'], 'The v field must be before 9:30 AM.'),
            self::onlyError(Rule::time('g:i A')->before(TimeOfDay::of(9, 30)), '10:00 AM'),
        );
    }

    public function testLimitIsBroughtDownToWhatTheFormatWrites(): void
    {
        // Against H:i the limit 09:30:30 is 09:30, so 09:30 is not before it.
        $error = self::onlyError(Rule::time()->before(TimeOfDay::of(9, 30, 30, 500000)), '09:30');

        $this->assertSame(['before', ['limit' => '09:30']], [$error->rule, $error->params]);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function hoursAFormatCannotReadBack(): iterable
    {
        yield 'afternoon without meridiem' => ['g:i', 15, 30];
        yield 'midnight without meridiem' => ['g:i', 0, 15];
        yield 'hour the format leaves out' => ['i:s', 1, 0];
    }

    #[DataProvider('hoursAFormatCannotReadBack')]
    public function testLimitIsRefusedWhenTheFormatDoesNotReadBackItsHour(string $format, int $hour, int $minute): void
    {
        $limit  = TimeOfDay::of($hour, $minute);
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::time($format)->before($limit));

        $this->assertSame(
            'time() reads times with \'' . $format . '\', which does not read back ' . $limit . '.',
            $thrown->getMessage(),
        );
    }

    public function testLimitIsKeptWhenTheFormatReadsBackItsHour(): void
    {
        $this->assertSame([], self::failedRules(Rule::time('g:i')->after(TimeOfDay::of(11, 0)), '11:30'));
    }

    // -------------------------------------------------------
    // defaults
    // -------------------------------------------------------

    public function testDefaultIsBroughtDownToWhatTheFormatWrites(): void
    {
        $rule = Rule::time()->default(TimeOfDay::of(9, 30, 30, 500000));

        $this->assertSame('09:30:00', self::timeOf(self::valueOf($rule, null)));
    }

    public function testDefaultIsRefusedWhenTheFormatDoesNotReadBackItsHour(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Rule::time('g:i')->default(TimeOfDay::of(15, 30)),
        );
    }

    public function testADefaultDeclaredOnAContainerIsBroughtDownToo(): void
    {
        $value = self::valueOf(Rule::shape(['t' => Rule::time()])->default(['t' => TimeOfDay::of(9, 30, 30)]), null);

        $this->assertIsArray($value);
        $this->assertSame('09:30:00', self::timeOf($value['t']));
    }

    // -------------------------------------------------------
    // same / different
    // -------------------------------------------------------

    public function testTwoFieldsWithTheSameTimeCompareEqual(): void
    {
        $rules  = ['from' => Rule::time('H:i'), 'to' => Rule::time('g:i A')->same('from')];
        $result = new Validator($rules)->validate(['from' => '15:30', 'to' => '3:30 PM']);

        $this->assertFalse($result->failed());
    }

    public function testTwoFieldsWithDifferentTimesCompareUnequal(): void
    {
        $rules  = ['from' => Rule::time('H:i:s.u'), 'to' => Rule::time('H:i:s.u')->same('from')];
        $result = new Validator($rules)->validate(['from' => '09:30:00.1', 'to' => '09:30:00.2']);

        $this->assertSame(['same'], array_map(static fn (ValidationError $e): string => $e->rule, $result->errors()['to'] ?? []));
    }
}
