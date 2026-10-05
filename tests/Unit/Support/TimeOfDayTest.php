<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Support\TimeOfDay;
use Sloop\Tests\Support\ThrowsAssertions;

final class TimeOfDayTest extends TestCase
{
    use ThrowsAssertions;

    // -------------------------------------------------------
    // of / fromDateTime
    // -------------------------------------------------------

    public function testOfKeepsEachPart(): void
    {
        $time = TimeOfDay::of(23, 59, 58, 999999);

        $this->assertSame([23, 59, 58, 999999], [$time->hour(), $time->minute(), $time->second(), $time->microsecond()]);
    }

    public function testOfDefaultsSecondAndMicrosecondToZero(): void
    {
        $time = TimeOfDay::of(9, 30);

        $this->assertSame([9, 30, 0, 0], [$time->hour(), $time->minute(), $time->second(), $time->microsecond()]);
    }

    public function testOfAcceptsMidnight(): void
    {
        $this->assertSame('00:00:00', (string) TimeOfDay::of(0, 0));
    }

    /**
     * @return iterable<string, array{int, int, int, int, string}>
     */
    public static function outOfRangeParts(): iterable
    {
        yield 'negative hour' => [-1, 0, 0, 0, 'The hour of a TimeOfDay must be from 0 to 23, got -1.'];
        yield 'hour 24' => [24, 0, 0, 0, 'The hour of a TimeOfDay must be from 0 to 23, got 24.'];
        yield 'negative minute' => [0, -1, 0, 0, 'The minute of a TimeOfDay must be from 0 to 59, got -1.'];
        yield 'minute 60' => [0, 60, 0, 0, 'The minute of a TimeOfDay must be from 0 to 59, got 60.'];
        yield 'negative second' => [0, 0, -1, 0, 'The second of a TimeOfDay must be from 0 to 59, got -1.'];
        yield 'leap second' => [23, 59, 60, 0, 'The second of a TimeOfDay must be from 0 to 59, got 60.'];
        yield 'negative microsecond' => [0, 0, 0, -1, 'The microsecond of a TimeOfDay must be from 0 to 999999, got -1.'];
        yield 'a whole second of microseconds' => [0, 0, 0, 1000000, 'The microsecond of a TimeOfDay must be from 0 to 999999, got 1000000.'];
    }

    #[DataProvider('outOfRangeParts')]
    public function testOfRejectsAPartOutOfRange(int $hour, int $minute, int $second, int $microsecond, string $message): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): TimeOfDay => TimeOfDay::of($hour, $minute, $second, $microsecond),
        );

        $this->assertSame($message, $thrown->getMessage());
    }

    public function testFromDateTimeReadsTheClockInTheZoneTheValueCarries(): void
    {
        // 23:30 in Tokyo is 14:30 in UTC; the time of day is the one the value
        // shows, not the one it would show elsewhere.
        $time = TimeOfDay::fromDateTime(new DateTimeImmutable('2026-01-02T23:30:15.250000+09:00'));

        $this->assertSame([23, 30, 15, 250000], [$time->hour(), $time->minute(), $time->second(), $time->microsecond()]);
    }

    // -------------------------------------------------------
    // comparison
    // -------------------------------------------------------

    /**
     * @return iterable<string, array{TimeOfDay, TimeOfDay, int}>
     */
    public static function orderedPairs(): iterable
    {
        yield 'earlier hour' => [TimeOfDay::of(8, 59, 59, 999999), TimeOfDay::of(9, 0), -1];
        yield 'earlier minute' => [TimeOfDay::of(9, 29, 59), TimeOfDay::of(9, 30), -1];
        yield 'earlier second' => [TimeOfDay::of(9, 30, 1), TimeOfDay::of(9, 30, 2), -1];
        yield 'earlier microsecond' => [TimeOfDay::of(9, 30, 0, 1), TimeOfDay::of(9, 30, 0, 2), -1];
        yield 'equal' => [TimeOfDay::of(9, 30, 0, 5), TimeOfDay::of(9, 30, 0, 5), 0];
        yield 'later microsecond' => [TimeOfDay::of(9, 30, 0, 2), TimeOfDay::of(9, 30, 0, 1), 1];
        yield 'later hour' => [TimeOfDay::of(10, 0), TimeOfDay::of(9, 59, 59, 999999), 1];
    }

    #[DataProvider('orderedPairs')]
    public function testComparisonOrdersByHourMinuteSecondAndMicrosecond(TimeOfDay $left, TimeOfDay $right, int $expected): void
    {
        $this->assertSame(
            [$expected, $expected < 0, $expected > 0, $expected === 0],
            [$left->compareTo($right), $left->isBefore($right), $left->isAfter($right), $left->equals($right)],
        );
    }

    // -------------------------------------------------------
    // string forms
    // -------------------------------------------------------

    /**
     * @return iterable<string, array{TimeOfDay, string}>
     */
    public static function stringForms(): iterable
    {
        yield 'whole second' => [TimeOfDay::of(9, 5, 7), '09:05:07'];
        yield 'with a fraction' => [TimeOfDay::of(9, 5, 7, 500000), '09:05:07.500000'];
        yield 'with one microsecond' => [TimeOfDay::of(23, 59, 59, 1), '23:59:59.000001'];
    }

    #[DataProvider('stringForms')]
    public function testStringFormOmitsTheFractionOnlyWhenItIsZero(TimeOfDay $time, string $expected): void
    {
        $this->assertSame([$expected, $expected, '"' . $expected . '"'], [(string) $time, $time->jsonSerialize(), json_encode($time)]);
    }

    // -------------------------------------------------------
    // format
    // -------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function timeFormats(): iterable
    {
        yield '24-hour' => ['H:i', '15:04'];
        yield '24-hour without padding' => ['G:i', '15:04'];
        yield '12-hour with meridiem' => ['g:i A', '3:04 PM'];
        yield 'padded 12-hour' => ['h:i:s a', '03:04:05 pm'];
        yield 'milliseconds' => ['H:i:s.v', '15:04:05.012'];
        yield 'microseconds' => ['H:i:s.u', '15:04:05.012345'];
        yield 'escaped letters' => ['H\h i\m', '15h 04m'];
        yield 'nothing written' => ['', ''];
    }

    #[DataProvider('timeFormats')]
    public function testFormatWritesTheTimeWithTheTimeCharacters(string $format, string $expected): void
    {
        $this->assertSame($expected, TimeOfDay::of(15, 4, 5, 12345)->format($format));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formatsBeyondTheTime(): iterable
    {
        yield 'year' => ['Y H:i'];
        yield 'day of month' => ['j H:i'];
        yield 'days in month' => ['t H:i'];
        yield 'leap year' => ['L H:i'];
        yield 'weekday' => ['D H:i'];
        yield 'ISO week' => ['W H:i'];
        yield 'unix timestamp' => ['U'];
        yield 'zone identifier' => ['H:i e'];
        yield 'zone abbreviation' => ['H:i T'];
        yield 'offset' => ['H:i P'];
        yield 'daylight saving flag' => ['H:i I'];
        yield 'internet time' => ['B'];
        yield 'ISO 8601 date' => ['c'];
    }

    #[DataProvider('formatsBeyondTheTime')]
    public function testFormatRejectsAFormatWritingADateOrAZone(string $format): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): string => TimeOfDay::of(15, 4)->format($format),
        );

        $this->assertStringContainsString('\'' . $format . '\'', $thrown->getMessage());
    }
}
