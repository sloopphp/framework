<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\Rule;
use Sloop\Validation\Sanitize;
use Sloop\Validation\ValidationError;
use Sloop\Validation\Validator;

final class DateRuleTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    /**
     * Run the callable with the PHP default time zone set to the given one.
     *
     * @template T
     * @param  callable(): T $act Work to run
     * @return T
     */
    private static function inTimeZone(string $timezone, callable $act): mixed
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set($timezone);

        try {
            return $act();
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testValueIsTheStartOfTheDateInThePhpDefaultTimeZone(): void
    {
        // The suite runs in UTC, so a zone with an offset is needed to show
        // that the date is read where the application runs rather than in UTC.
        $value = self::inTimeZone('Asia/Tokyo', static fn (): mixed => self::valueOf(Rule::date(), '2026-01-02'));

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-01-02T00:00:00+09:00', $value->format('Y-m-d\TH:i:sP'));
    }

    public function testValueIsTheStartOfTheDateWhereTheClockSkipsMidnight(): void
    {
        // Chile moved to summer time at 00:00 on 14 August 2016, so that day
        // has no 00:00 and starts at 01:00. A failure here is a change in the
        // system tzdata as readily as one in this field.
        $value = self::inTimeZone('America/Santiago', static fn (): mixed => self::valueOf(Rule::date(), '2016-08-14'));

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2016-08-14T01:00:00-03:00', $value->format('Y-m-d\TH:i:sP'));
    }

    public function testComparisonUsesTheSameZoneAsTheValue(): void
    {
        // Midnight in Tokyo is 15:00 the day before in UTC, so a limit read in
        // the wrong zone would put this value on the wrong side of the day.
        $failures = self::inTimeZone('Asia/Tokyo', static fn (): array => self::failedRules(
            Rule::date()->afterOrEqual(new DateTimeImmutable('2026-01-02T00:00:00+09:00')),
            '2026-01-02',
        ));

        $this->assertSame([], $failures);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedDates(): iterable
    {
        yield 'unpadded' => ['2026-1-2'];
        yield 'day out of range' => ['2026-02-30'];
        yield 'month out of range' => ['2026-13-01'];
        yield 'trailing text' => ['2026-01-02 x'];
        yield 'with time' => ['2026-01-02T03:04:05'];
        yield 'slashes' => ['2026/01/02'];
        yield 'not a date' => ['tomorrow'];
    }

    #[DataProvider('rejectedDates')]
    public function testValuesOutsideTheDeclaredFormatAreTypeFailures(string $input): void
    {
        $this->assertSame(['date'], self::failedRules(Rule::date(), $input));
    }

    public function testNonStringIsATypeFailure(): void
    {
        $this->assertSame(['date'], self::failedRules(Rule::date(), 20260102));
    }

    public function testFormatArgumentChangesWhatIsAccepted(): void
    {
        $value = self::valueOf(Rule::date('d/m/Y'), '02/01/2026');

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-01-02', $value->format('Y-m-d'));
        $this->assertSame(['date'], self::failedRules(Rule::date('d/m/Y'), '2026-01-02'));
    }

    public function testSanitizersRunBeforeTheFormatIsChecked(): void
    {
        $value = self::valueOf(Rule::date('Y-m-d', Sanitize::Trim), "  2026-01-02\n");

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-01-02', $value->format('Y-m-d'));
    }

    public function testEmptyFormatIsRejectedWhenDeclared(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::date(''));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formatsThatAreNotDates(): iterable
    {
        yield 'time of day' => [
            'Y-m-d H:i:s',
            'date() is for calendar dates, and \'Y-m-d H:i:s\' carries a time of day. Use dateTime() for an instant.',
        ];
        yield 'time and offset' => [
            'Y-m-d\TH:i:sP',
            'date() is for calendar dates, and \'Y-m-d\TH:i:sP\' carries a time of day. Use dateTime() for an instant.',
        ];
        yield 'digits that run together' => [
            'nd',
            'date() needs a format that reads back what it writes, and \'nd\' does not.',
        ];
        yield 'one whose reading writes itself differently' => [
            'S',
            'date() needs a format that reads back what it writes, and \'S\' does not.',
        ];
        yield 'one that only warns about what it could not read' => [
            'Y-m-d+',
            'date() needs a format that reads back what it writes, and \'Y-m-d+\' does not.',
        ];
        yield 'written but not readable' => [
            'N',
            'date() needs a format that reads back what it writes, and \'N\' does not.',
        ];
        yield 'one ending in a backslash' => [
            'Y-m-d\\',
            'date() needs a format that reads back what it writes, and \'Y-m-d\\\' does not.',
        ];
        yield 'offset' => [
            'Y-m-d P',
            'date() is for calendar dates, and \'Y-m-d P\' carries a time zone. Use dateTime() for an instant.',
        ];
        yield 'zone abbreviation' => [
            'Y-m-d T',
            'date() is for calendar dates, and \'Y-m-d T\' carries a time zone. Use dateTime() for an instant.',
        ];
        yield 'seconds since the epoch' => [
            'U',
            'date() is for calendar dates, and \'U\' carries a time of day. Use dateTime() for an instant.',
        ];
    }

    #[DataProvider('formatsThatAreNotDates')]
    public function testFormatIsRefusedWhenTheFieldCouldNotHonourIt(string $format, string $message): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::date($format));

        $this->assertSame($message, $thrown->getMessage());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function acceptedFormats(): iterable
    {
        yield 'ISO' => ['Y-m-d', '2026-01-02', '2026-01-02'];
        yield 'day first' => ['d/m/Y', '02/01/2026', '2026-01-02'];
        yield 'no separators' => ['Ymd', '20260102', '2026-01-02'];
        yield 'weekday and month names' => ['D, d M Y', 'Fri, 02 Jan 2026', '2026-01-02'];
    }

    #[DataProvider('acceptedFormats')]
    public function testFormatsThatNameOnlyADateAreAccepted(string $format, string $input, string $expected): void
    {
        $value = self::valueOf(Rule::date($format), $input);

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame($expected, $value->format('Y-m-d'));
    }

    public function testDefaultIsTruncatedToTheDeclaredFormat(): void
    {
        $rule  = Rule::date()->default(new DateTimeImmutable('2026-01-02 15:30:45'));
        $value = self::valueOf($rule, null);

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-01-02T00:00:00', $value->format('Y-m-d\TH:i:s'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'before, earlier' => ['before', '2026-01-01', true];
        yield 'before, same day' => ['before', '2026-01-02', false];
        yield 'before, later' => ['before', '2026-01-03', false];
        yield 'beforeOrEqual, earlier' => ['beforeOrEqual', '2026-01-01', true];
        yield 'beforeOrEqual, same day' => ['beforeOrEqual', '2026-01-02', true];
        yield 'beforeOrEqual, later' => ['beforeOrEqual', '2026-01-03', false];
        yield 'after, earlier' => ['after', '2026-01-01', false];
        yield 'after, same day' => ['after', '2026-01-02', false];
        yield 'after, later' => ['after', '2026-01-03', true];
        yield 'afterOrEqual, earlier' => ['afterOrEqual', '2026-01-01', false];
        yield 'afterOrEqual, same day' => ['afterOrEqual', '2026-01-02', true];
        yield 'afterOrEqual, later' => ['afterOrEqual', '2026-01-03', true];
    }

    #[DataProvider('comparisons')]
    public function testComparisonsAreMadeOnTheCalendarDate(string $method, string $input, bool $passes): void
    {
        $limit = new DateTimeImmutable('2026-01-02');
        $rule  = match ($method) {
            'before' => Rule::date()->before($limit),
            'beforeOrEqual' => Rule::date()->beforeOrEqual($limit),
            'after' => Rule::date()->after($limit),
            default => Rule::date()->afterOrEqual($limit),
        };

        $this->assertSame($passes ? [] : [$method], self::failedRules($rule, $input));
    }

    public function testComparisonIgnoresTheTimeOfDayOfTheLimit(): void
    {
        // Same calendar date, but an instant comparison would put the limit
        // most of a day away from the value's midnight.
        $limit = new DateTimeImmutable('2026-01-02 23:45:00', new DateTimeZone('Asia/Tokyo'));

        $failures = self::inTimeZone('Asia/Tokyo', static fn (): array => [
            'beforeOrEqual' => self::failedRules(Rule::date()->beforeOrEqual($limit), '2026-01-02'),
            'before' => self::failedRules(Rule::date()->before($limit), '2026-01-02'),
        ]);

        $this->assertSame(['beforeOrEqual' => [], 'before' => ['before']], $failures);
    }

    public function testDefaultIsReadAsTheLocalDateOfTheValueGiven(): void
    {
        // 23:00 UTC on the 14th is already the 15th in Tokyo, and default()
        // goes through the same reduction a comparison limit does.
        $value = self::inTimeZone('Asia/Tokyo', static fn (): mixed => self::valueOf(
            Rule::date()->default(new DateTimeImmutable('2026-06-14T23:00:00+00:00')),
            null,
        ));

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-06-15T00:00:00+09:00', $value->format('Y-m-d\TH:i:sP'));
    }

    public function testComparisonUsesTheDateTheLimitFallsOnLocally(): void
    {
        // 23:00 UTC on the 14th is 08:00 on the 15th in Tokyo. Reading the
        // limit in the zone it was built with would put the boundary a day
        // before the one the application sees.
        $limit = new DateTimeImmutable('2026-06-14T23:00:00+00:00');

        $failures = self::inTimeZone('Asia/Tokyo', static fn (): array => [
            'the 15th' => self::failedRules(Rule::date()->beforeOrEqual($limit), '2026-06-15'),
            'the 16th' => self::failedRules(Rule::date()->beforeOrEqual($limit), '2026-06-16'),
        ]);

        $this->assertSame(['the 15th' => [], 'the 16th' => ['beforeOrEqual']], $failures);
    }

    public function testComparisonParameterNamesTheLocalDateOfTheLimit(): void
    {
        $limit = new DateTimeImmutable('2026-06-14T23:00:00+00:00');
        $error = self::inTimeZone(
            'Asia/Tokyo',
            static fn (): ValidationError => self::onlyError(Rule::date()->before($limit), '2026-06-20'),
        );

        $this->assertSame(['date' => '2026-06-15'], $error->params);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function limitsThatLeaveTheRangeOfTheFormat(): iterable
    {
        yield 'past the last year a four-digit format can read' => [
            'Asia/Tokyo', '9999-12-31 23:59:59', '10000-01-01',
        ];
        yield 'before the first one' => [
            'America/New_York', '0000-01-01 00:00:00', '-0001-12-31',
        ];
    }

    #[DataProvider('limitsThatLeaveTheRangeOfTheFormat')]
    public function testLimitIsRefusedWhenItsLocalDateLeavesTheFormat(string $timezone, string $utc, string $local): void
    {
        // `Y` writes five digits but reads four, so a limit at either end of
        // the range moves out of it once it is read where the application runs.
        $declare = static fn (): mixed => Rule::date()->before(new DateTimeImmutable($utc, new DateTimeZone('UTC')));

        $thrown = self::inTimeZone(
            $timezone,
            fn (): \Throwable => $this->assertThrows(InvalidArgumentException::class, $declare),
        );

        $this->assertSame(
            'date() reads dates with \'Y-m-d\', which does not read back the local date ' . $local . '.',
            $thrown->getMessage(),
        );
    }

    public function testDefaultIsRefusedWhenItsLocalDateLeavesTheFormat(): void
    {
        // default() goes through the same reduction, so it meets the same wall.
        $declare = static fn (): mixed => Rule::date()->default(
            new DateTimeImmutable('9999-12-31 23:59:59', new DateTimeZone('UTC')),
        );

        $thrown = self::inTimeZone(
            'Asia/Tokyo',
            fn (): \Throwable => $this->assertThrows(InvalidArgumentException::class, $declare),
        );

        $this->assertSame(
            'date() reads dates with \'Y-m-d\', which does not read back the local date 10000-01-01.',
            $thrown->getMessage(),
        );
    }

    public function testLimitIsRefusedWhenATwoDigitYearWouldMoveItToThisCentury(): void
    {
        // `y` writes two digits and reads them into this century, so the limit
        // the caller meant and the one the field would compare against are a
        // hundred years apart.
        $declare = static fn (): mixed => Rule::date('d/m/y')->before(
            new DateTimeImmutable('1926-03-15', new DateTimeZone('UTC')),
        );

        $thrown = $this->assertThrows(InvalidArgumentException::class, $declare);

        $this->assertSame(
            'date() reads dates with \'d/m/y\', which does not read back the local date 1926-03-15.',
            $thrown->getMessage(),
        );
    }

    public function testLimitIsKeptWhenTheFormatDropsOnlyPartOfTheDate(): void
    {
        // Losing the day of the month is the reduction this field is for, and
        // `Y-m` keeps the year, so the limit still names the month it did.
        $limit = new DateTimeImmutable('2026-03-15', new DateTimeZone('UTC'));

        $this->assertSame([], self::failedRules(Rule::date('Y-m')->beforeOrEqual($limit), '2026-03'));
        $this->assertSame(['beforeOrEqual'], self::failedRules(Rule::date('Y-m')->beforeOrEqual($limit), '2026-04'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function limitsTheirFormatSurvives(): iterable
    {
        yield 'month and day' => ['m/d', '2026-03-15'];
        yield 'day and month name' => ['d M', '2026-03-15'];
        yield 'day of the month' => ['j', '2026-03-15'];
        yield 'month name' => ['F', '2026-03-15'];
        yield 'day of a common year' => ['z', '2026-03-15'];
    }

    #[DataProvider('limitsTheirFormatSurvives')]
    public function testALimitThatSurvivesItsFormatIsKept(string $format, string $limit): void
    {
        // Every date lands on the same year, which is a reduction like
        // dropping the day of the month rather than a limit moving.
        $given = new DateTimeImmutable($limit, new DateTimeZone('UTC'));
        $rule  = Rule::date($format)->beforeOrEqual($given);

        $this->assertSame([], self::failedRules($rule, $given->format($format)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function limitsTheirFormatCannotHold(): iterable
    {
        // The day of a leap year passes 365, which reads back into the year
        // after the epoch instead of the epoch itself.
        yield 'day of a leap year' => ['z', '2024-12-31'];
        // 29 February has no counterpart in the epoch year.
        yield 'a leap day without its year' => ['m/d', '2024-02-29'];
        // `y` reads 0059 into 2000, where `z` puts 59 on 29 February, and `X`
        // moves that to 1900, which has no such day: the reading is repaired
        // to 1 March and writes itself back unchanged, so only the warning
        // tells it apart from a reading that held.
        yield 'a repaired reading that writes itself back' => ['yzX', '1900-03-01'];
    }

    #[DataProvider('limitsTheirFormatCannotHold')]
    public function testALimitIsRefusedWhenItsFormatCannotHoldIt(string $format, string $limit): void
    {
        $given   = new DateTimeImmutable($limit, new DateTimeZone('UTC'));
        $declare = static fn (): mixed => Rule::date($format)->after($given);

        $this->assertSame(
            'date() reads dates with \'' . $format . '\', which does not read back the local date ' . $limit . '.',
            $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage(),
        );
    }

    public function testAFormatWithoutAYearComparesWithinTheOneItLandsOn(): void
    {
        $limit = new DateTimeImmutable('2026-03-15', new DateTimeZone('UTC'));
        $rule  = Rule::date('m/d')->before($limit);

        $this->assertSame([], self::failedRules($rule, '03/10'));
        $this->assertSame(['before'], self::failedRules($rule, '03/20'));
    }

    public function testAFormatWritingTheWeekdayIsNotTakenForOneWritingAYear(): void
    {
        // A pair of dates a year apart differs in weekday, so settling the
        // year check on one would refuse this format as though its year had
        // moved. 15 March falls on a Sunday in both 2026 and the epoch year.
        $limit = new DateTimeImmutable('2026-03-15', new DateTimeZone('UTC'));

        $this->assertSame([], self::failedRules(Rule::date('D d M')->beforeOrEqual($limit), 'Sun 15 Mar'));
    }

    public function testATwoDigitYearIsKeptInsideTheWindowItReadsInto(): void
    {
        // `y` reads two digits into 1970..2069, so a limit inside that window
        // reads back as the year it was given.
        $inside = new DateTimeImmutable('1975-03-15', new DateTimeZone('UTC'));
        $rule   = Rule::date('d/m/y')->beforeOrEqual($inside);

        $this->assertSame([], self::failedRules($rule, '15/03/75'));
        $this->assertSame(['beforeOrEqual'], self::failedRules($rule, '16/03/75'));
    }

    public function testALimitAtTheEndOfTheRangeIsKeptWhenItStaysInside(): void
    {
        $limit = new DateTimeImmutable('9999-12-31 00:00:00', new DateTimeZone('UTC'));

        $failures = self::inTimeZone('Asia/Tokyo', static fn (): array => self::failedRules(
            Rule::date()->beforeOrEqual($limit),
            '9999-12-31',
        ));

        $this->assertSame([], $failures);
    }

    public function testNullByteIsATypeFailureRatherThanAnError(): void
    {
        // It survives parse_str() and json_decode() and passes the UTF-8
        // check, but createFromFormat() raises a ValueError on one.
        $this->assertSame(['date'], self::failedRules(Rule::date(), "2026-01-02\0"));
    }

    public function testComparisonOrdersByDateRatherThanByTheFormattedString(): void
    {
        // '03/02/2026' sorts before '10/01/2026' as text, but 3 February is
        // after 10 January.
        $rule = Rule::date('d/m/Y')->after(new DateTimeImmutable('2026-01-10'));

        $this->assertSame([], self::failedRules($rule, '03/02/2026'));
    }

    public function testComparisonParameterIsReportedInTheDeclaredFormat(): void
    {
        $error = self::onlyError(Rule::date('d/m/Y')->before(new DateTimeImmutable('2026-01-10')), '03/02/2026');

        $this->assertSame('before', $error->rule);
        $this->assertSame(['date' => '10/01/2026'], $error->params);
    }

    public function testTwoFieldsWithTheSameDateCompareEqual(): void
    {
        $rules  = ['from' => Rule::date(), 'to' => Rule::date()->same('from')];
        $result = new Validator($rules)->validate(['from' => '2026-01-02', 'to' => '2026-01-02']);

        $this->assertFalse($result->failed());
    }
}
