<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Rules for a field whose validated value is a calendar date.
 *
 * The input must match the declared format exactly; a value PHP would repair
 * (`2026-1-2`, `2026-02-30`) or read past (`2026-01-02 x`) is a type failure.
 * The validated value is the start of that date in the PHP default time zone,
 * because `Y-m-d` carries no offset of its own and the caller's own reading of
 * a bare date is the day as it falls where the application runs. That is
 * midnight, except in a zone whose clock skips it: America/Santiago goes from
 * 23:59:59 straight to 01:00 on the day its summer time starts, so a date of
 * that day is 01:00 there.
 *
 * Comparisons reduce their limit to the date it falls on where the
 * application runs. The format itself may not carry a time: dateTime() is the
 * type for an instant, and it asks for an offset that a bare time would not
 * have.
 */
final class DateRule extends TemporalRule
{
    /**
     * Whether the declared format puts the year in what it writes.
     *
     * @var bool
     */
    private readonly bool $writesYear;

    /**
     * Two dates 28 years apart, which share a weekday and are both common years.
     *
     * @var string
     */
    private const string YEAR_PROBE = '1926-03-15';

    /**
     * The other end of that pair.
     *
     * @var string
     */
    private const string YEAR_PROBE_LATER = '1954-03-15';

    /**
     * Create the rule set.
     *
     * @param  string                                 $format     Format the input must match, as DateTimeImmutable::createFromFormat() reads it
     * @param  list<Sanitize|Closure(string): string> $sanitizers Sanitizers applied before validation, in order
     * @throws InvalidArgumentException               When $format is empty, does not read back what it writes, or carries a time of day or a time zone
     */
    public function __construct(
        private readonly string $format,
        array $sanitizers,
    ) {
        if ($format === '') {
            throw new InvalidArgumentException('date() needs a format.');
        }
        self::assertDateOnly($format);
        $this->writesYear = self::writesYear($format);
        parent::__construct($sanitizers);
    }

    /**
     * Use this date when the field is empty.
     *
     * The value is reduced the same way a comparison limit is, so the default
     * is a date the field could itself have produced.
     *
     * @param  DateTimeInterface        $value Date to use for an empty field
     * @return self
     * @throws \LogicException          When the field is required or a default has already been declared
     * @throws InvalidArgumentException When the declared format cannot read back the value's local date
     */
    public function default(DateTimeInterface $value): self
    {
        return $this->withDefault($this->alignLimit($value));
    }

    /**
     * The declared format, with unmatched fields reset to the start of the day.
     *
     * @return non-empty-list<string>
     */
    protected function formats(): array
    {
        return ['!' . $this->format];
    }

    /**
     * Require the input to be written exactly as the declared format renders it.
     *
     * @param  string            $value  Raw input that parsed
     * @param  DateTimeImmutable $parsed What it parsed to
     * @return bool
     */
    protected function accepts(string $value, DateTimeImmutable $parsed): bool
    {
        return $parsed->format($this->format) === $value;
    }

    /**
     * Read the limit as a date in the PHP default time zone, dropping its time of day.
     *
     * @param  DateTimeInterface        $limit Limit as the caller declared it
     * @return DateTimeImmutable
     * @throws InvalidArgumentException When the declared format cannot read back the local date
     */
    protected function alignLimit(DateTimeInterface $limit): DateTimeImmutable
    {
        $local   = $this->localize($limit);
        $aligned = $this->coerce($local->format($this->format));
        // The constructor settled the format against one date, which does not
        // settle every date, so the limit goes through the same reading the
        // field makes of its own input. The year is checked on top of that for
        // a format that writes one, since `y` keeps both of its digits and
        // still reads 1926 into 2026.
        if ($aligned instanceof TypeMismatch
            || ($this->writesYear && $aligned->format('Y') !== $local->format('Y'))
        ) {
            throw new InvalidArgumentException(
                'date() reads dates with \'' . $this->format . '\', which does not read back the local date ' . $local->format('Y-m-d') . '.',
            );
        }

        return $aligned;
    }

    /**
     * Render the limit in the declared format, as the date it falls on locally.
     *
     * A limit is a point in time and names a different date either side of
     * midnight, so the one this field compares against is the date it falls on
     * where the application runs, which is the frame the validated value is
     * in. Reading it in the zone the caller happened to build it with would
     * put the boundary a day out.
     *
     * @param  DateTimeInterface $limit Limit as the caller declared it
     * @return string
     */
    protected function describeLimit(DateTimeInterface $limit): string
    {
        return $this->localize($limit)->format($this->format);
    }

    /**
     * Whether the format puts the year in what it writes.
     *
     * The two dates are 28 years apart so that they fall on the same weekday
     * and neither is a leap year: a format writing the weekday or the day of
     * the year tells a pair a year apart apart without writing a year at all.
     *
     * @param  string $format Format as the caller declared it
     * @return bool
     */
    private static function writesYear(string $format): bool
    {
        $zone  = new DateTimeZone('+00:00');
        $probe = new DateTimeImmutable(self::YEAR_PROBE, $zone);

        return $probe->format($format) !== new DateTimeImmutable(self::YEAR_PROBE_LATER, $zone)->format($format);
    }

    /**
     * Read the limit where the application runs.
     *
     * @param  DateTimeInterface $limit Limit as the caller declared it
     * @return DateTimeImmutable
     */
    private function localize(DateTimeInterface $limit): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($limit)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'date';
    }

    /**
     * Reject a format that this field could not honour.
     *
     * Runs a date through the round trip the field itself makes of every
     * value, which rules out three things: a format createFromFormat() cannot
     * read back (`N` writes a weekday number it has no way to parse), one
     * carrying a time of day, and one carrying a time zone. The last two would
     * make this field yield something other than a date as it falls where the
     * application runs, which is the whole of what it promises.
     *
     * @param  string                   $format Format as the caller declared it
     * @return void
     * @throws InvalidArgumentException When the format does not read back, or carries a time of day or a time zone
     */
    private static function assertDateOnly(string $format): void
    {
        $probe    = new DateTimeImmutable('2026-01-02 15:30:45.123456', new DateTimeZone('+00:00'));
        $rendered = $probe->format($format);
        // A trailing backslash escapes nothing and format() writes a NUL byte
        // for it, which createFromFormat() raises a ValueError on rather than
        // refusing to parse.
        $back = str_contains($rendered, "\0")
            ? false
            : DateTimeImmutable::createFromFormat('!' . $format, $rendered);
        if ($back === false
            || DateTimeImmutable::getLastErrors() !== false
            || $back->format($format) !== $rendered
        ) {
            throw new InvalidArgumentException(
                'date() needs a format that reads back what it writes, and \'' . $format . '\' does not.',
            );
        }
        if ($back->format('H:i:s.u') !== '00:00:00.000000') {
            throw new InvalidArgumentException(
                'date() is for calendar dates, and \'' . $format . '\' carries a time of day. Use dateTime() for an instant.',
            );
        }
        // Reading the same wall clock somewhere else tells the two apart: a
        // format that writes nothing of the zone writes the same text twice.
        $elsewhere = new DateTimeImmutable('2026-01-02 15:30:45.123456', new DateTimeZone('-05:00'));
        if ($elsewhere->format($format) !== $rendered) {
            throw new InvalidArgumentException(
                'date() is for calendar dates, and \'' . $format . '\' carries a time zone. Use dateTime() for an instant.',
            );
        }
    }
}
