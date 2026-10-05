<?php

declare(strict_types=1);

namespace Sloop\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A time of day: hour, minute, second and microsecond, with no date and no time zone.
 *
 * Two values compare by the clock alone, so 09:30 is before 17:00 whatever
 * day either was read on. A range that crosses midnight (22:00 to 02:00 the
 * next day) cannot be told apart from one that does not; that takes a date.
 *
 * The string form is `HH:MM:SS`, followed by six digits of fraction when the
 * microsecond is not zero, which a MySQL TIME column accepts as written.
 */
final readonly class TimeOfDay implements JsonSerializable, Stringable
{
    /**
     * Create the value from parts already known to be in range.
     *
     * @param int $hour        Hour, 0 to 23
     * @param int $minute      Minute, 0 to 59
     * @param int $second      Second, 0 to 59
     * @param int $microsecond Microsecond, 0 to 999999
     */
    private function __construct(
        private int $hour,
        private int $minute,
        private int $second,
        private int $microsecond,
    ) {
    }

    /**
     * Create a time of day from its parts.
     *
     * @param  int                      $hour        Hour, 0 to 23
     * @param  int                      $minute      Minute, 0 to 59
     * @param  int                      $second      Second, 0 to 59
     * @param  int                      $microsecond Microsecond, 0 to 999999
     * @return self
     * @throws InvalidArgumentException When a part is out of its range
     */
    public static function of(int $hour, int $minute, int $second = 0, int $microsecond = 0): self
    {
        self::assertInRange('hour', $hour, 23);
        self::assertInRange('minute', $minute, 59);
        self::assertInRange('second', $second, 59);
        self::assertInRange('microsecond', $microsecond, 999999);

        return new self($hour, $minute, $second, $microsecond);
    }

    /**
     * Take the time of day a date and time shows.
     *
     * The clock is read in the time zone the value carries, so convert the
     * value first to read it somewhere else.
     *
     * @param  DateTimeInterface $dateTime Date and time to read the clock of
     * @return self
     */
    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return new self(
            (int) $dateTime->format('G'),
            (int) $dateTime->format('i'),
            (int) $dateTime->format('s'),
            (int) $dateTime->format('u'),
        );
    }

    /**
     * Hour, 0 to 23.
     *
     * @return int
     */
    public function hour(): int
    {
        return $this->hour;
    }

    /**
     * Minute, 0 to 59.
     *
     * @return int
     */
    public function minute(): int
    {
        return $this->minute;
    }

    /**
     * Second, 0 to 59.
     *
     * @return int
     */
    public function second(): int
    {
        return $this->second;
    }

    /**
     * Microsecond, 0 to 999999.
     *
     * @return int
     */
    public function microsecond(): int
    {
        return $this->microsecond;
    }

    /**
     * Order this time against another: negative when earlier, zero when equal, positive when later.
     *
     * @param  self $other Time to compare with
     * @return int
     */
    public function compareTo(self $other): int
    {
        return [$this->hour, $this->minute, $this->second, $this->microsecond]
            <=> [$other->hour, $other->minute, $other->second, $other->microsecond];
    }

    /**
     * Whether this time is earlier than the other.
     *
     * @param  self $other Time to compare with
     * @return bool
     */
    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Whether this time is later than the other.
     *
     * @param  self $other Time to compare with
     * @return bool
     */
    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * Whether this time is the same as the other, to the microsecond.
     *
     * @param  self $other Time to compare with
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * Write the time with the characters DateTimeInterface::format() reads.
     *
     * Only the characters that write a time of day are allowed, since there is
     * no date or time zone to write.
     *
     * @param  string                   $format Format as DateTimeInterface::format() reads it
     * @return string
     * @throws InvalidArgumentException When the format writes a date or a time zone
     */
    public function format(string $format): string
    {
        if (!self::writesOnlyTime($format)) {
            throw new InvalidArgumentException(
                'TimeOfDay::format() writes a time of day, and \'' . $format . '\' writes a date or a time zone.',
            );
        }

        return $this->toDateTime()->format($format);
    }

    /**
     * Whether a format writes nothing but the time of day.
     *
     * The same clock is written on dates that differ in every part a format
     * can write (year, month, day, weekday, week, days in the month, leap year)
     * and in two zones that differ in offset, name and daylight saving, so a
     * format that writes any of those writes different text somewhere.
     *
     * @param  string $format Format as DateTimeInterface::format() reads it
     * @return bool
     */
    private static function writesOnlyTime(string $format): bool
    {
        $utc     = new DateTimeZone('UTC');
        $written = new DateTimeImmutable('2026-01-02 15:30:45.123456', $utc)->format($format);

        return new DateTimeImmutable('2028-02-04 15:30:45.123456', $utc)->format($format) === $written
            && new DateTimeImmutable('2026-07-02 15:30:45.123456', $utc)->format($format) === $written
            && new DateTimeImmutable('2026-07-02 15:30:45.123456', new DateTimeZone('America/New_York'))->format($format) === $written;
    }

    /**
     * The same clock on 1 January 1970 in UTC, for DateTimeInterface::format() to write.
     *
     * @return DateTimeImmutable
     */
    private function toDateTime(): DateTimeImmutable
    {
        return new DateTimeImmutable('1970-01-01', new DateTimeZone('UTC'))
            ->setTime($this->hour, $this->minute, $this->second, $this->microsecond);
    }

    /**
     * `HH:MM:SS`, with six digits of fraction when the microsecond is not zero.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toDateTime()->format($this->microsecond === 0 ? 'H:i:s' : 'H:i:s.u');
    }

    /**
     * The string form, so that json_encode() writes the time as a string.
     *
     * @return string
     */
    public function jsonSerialize(): string
    {
        return (string) $this;
    }

    /**
     * Refuse a part outside zero to its largest value.
     *
     * @param  string                   $part  Name of the part, for the message
     * @param  int                      $value Value given for it
     * @param  int                      $max   Largest value the part takes
     * @return void
     * @throws InvalidArgumentException When the value is out of range
     */
    private static function assertInRange(string $part, int $value, int $max): void
    {
        if ($value < 0 || $value > $max) {
            throw new InvalidArgumentException(
                'The ' . $part . ' of a TimeOfDay must be from 0 to ' . $max . ', got ' . $value . '.',
            );
        }
    }
}
