<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Sloop\Support\TimeOfDay;

/**
 * Rules for a field whose validated value is a time of day.
 *
 * The input must match the declared format the way Rule::date() requires:
 * a value PHP would repair (`24:00`, `09:60`) or read past (`09:30 x`) is a
 * type failure, and `9:30` fails against `H:i`, which writes the hour with two
 * digits. Fractional seconds are the exception: `u` takes one to six digits
 * and `v` one to three, as PHP reads them, so `09:30:00.5` passes against
 * `H:i:s.u`.
 *
 * Comparison limits and the default are brought down to what the format
 * writes, so against `H:i` a limit of 09:30:30 is 09:30.
 *
 * @extends FieldRule<TimeOfDay>
 */
final class TimeRule extends FieldRule
{
    /**
     * Create the rule set.
     *
     * @param  string                                 $format     Format the input must match, as DateTimeImmutable::createFromFormat() reads it
     * @param  list<Sanitize|Closure(string): string> $sanitizers Sanitizers applied before validation, in order
     * @throws InvalidArgumentException               When $format is empty, carries a date or a time zone, or does not read back what it writes
     */
    public function __construct(
        private readonly string $format,
        array $sanitizers,
    ) {
        if ($format === '') {
            throw new InvalidArgumentException('time() needs a format.');
        }
        self::assertTimeOnly($format);
        parent::__construct($sanitizers);
    }

    /**
     * Fail unless the value is earlier than the given time.
     *
     * @param  TimeOfDay                $limit   Time the value must precede
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the format cannot write the limit
     */
    public function before(TimeOfDay $limit, ?string $message = null): static
    {
        return $this->withComparisonTo('before', $limit, $message, false);
    }

    /**
     * Fail unless the value is earlier than or equal to the given time.
     *
     * @param  TimeOfDay                $limit   Latest accepted time
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the format cannot write the limit
     */
    public function beforeOrEqual(TimeOfDay $limit, ?string $message = null): static
    {
        return $this->withComparisonTo('beforeOrEqual', $limit, $message, true);
    }

    /**
     * Fail unless the value is later than the given time.
     *
     * @param  TimeOfDay                $limit   Time the value must follow
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the format cannot write the limit
     */
    public function after(TimeOfDay $limit, ?string $message = null): static
    {
        return $this->withComparisonTo('after', $limit, $message, false);
    }

    /**
     * Fail unless the value is later than or equal to the given time.
     *
     * @param  TimeOfDay                $limit   Earliest accepted time
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the format cannot write the limit
     */
    public function afterOrEqual(TimeOfDay $limit, ?string $message = null): static
    {
        return $this->withComparisonTo('afterOrEqual', $limit, $message, true);
    }

    /**
     * Use this time when the field is empty.
     *
     * The value is brought down to what the format writes, the same way a
     * comparison limit is, so the default is a time the field could itself
     * have produced.
     *
     * @param  TimeOfDay                $value Time to use for an empty field
     * @return self
     * @throws \LogicException          When the field is required or a default has already been declared
     * @throws InvalidArgumentException When the format cannot write the value
     */
    public function default(TimeOfDay $value): self
    {
        return $this->withDefault($this->alignLimit($value));
    }

    /**
     * Bring a declared default into the frame the caller reads it in.
     *
     * @param  mixed                    $value Value the container's default holds for this field
     * @return mixed
     * @throws InvalidArgumentException When the format cannot write the value
     */
    protected function prepareDefault(mixed $value): mixed
    {
        return $value instanceof TimeOfDay ? $this->alignLimit($value) : $value;
    }

    /**
     * Read a time of day in the declared format.
     *
     * @param  mixed                  $value Raw value
     * @return TimeOfDay|TypeMismatch
     */
    protected function coerce(mixed $value): TimeOfDay|TypeMismatch
    {
        // A NUL byte survives both parse_str() and json_decode() and passes
        // the UTF-8 check, and createFromFormat() raises a ValueError on one
        // rather than failing to parse.
        if (!\is_string($value) || str_contains($value, "\0")) {
            return new TypeMismatch();
        }

        // UTC rather than the PHP default zone: a zone that skips a stretch
        // of the clock on 1 January 1970 would move the time read into it.
        $parsed = DateTimeImmutable::createFromFormat('!' . $this->format, $value, new DateTimeZone('UTC'));
        // getLastErrors() answers false when the value went in cleanly.
        // Anything else means PHP repaired it (24:00 comes back as 00:00 the
        // next day), which is a rejection here rather than a reading.
        if ($parsed === false || DateTimeImmutable::getLastErrors() !== false) {
            return new TypeMismatch();
        }

        return $this->accepts($value, $parsed) ? TimeOfDay::fromDateTime($parsed) : new TypeMismatch();
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'time';
    }

    /**
     * Compare two times by their string form, which is unique to each time.
     *
     * @param  TimeOfDay $value Validated value
     * @return string
     */
    protected function comparable(mixed $value): string
    {
        return (string) $value;
    }

    /**
     * Whether the input is written the way the declared format writes it.
     *
     * createFromFormat() reads a shorter field than the format asks for, so
     * `9:30` parses against `H:i`; writing the value back and comparing rules
     * that out. A fraction is written back with all six (`u`) or three (`v`)
     * digits, so it is written back with as many digits as the input may have
     * used, from one up, and any of those matching is enough. A format with no
     * fraction writes the same text for every count.
     *
     * @param  string            $value  Raw input that parsed
     * @param  DateTimeImmutable $parsed What it parsed to
     * @return bool
     */
    private function accepts(string $value, DateTimeImmutable $parsed): bool
    {
        for ($digits = 1; $digits <= 6; $digits++) {
            if ($parsed->format(self::withFractionDigits($this->format, $parsed, $digits)) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bring a limit or a default down to what the declared format writes.
     *
     * What a format leaves out below the hour is dropped. The hour itself is
     * not: `g:i` writes 15:30 as `3:30` and reads that back as 03:30, a time
     * no input to that field can be on the far side of, so a limit there
     * would always give the same answer.
     *
     * @param  TimeOfDay                $limit Time as the caller declared it
     * @return TimeOfDay
     * @throws InvalidArgumentException When the format does not read back the hour of this time
     */
    private function alignLimit(TimeOfDay $limit): TimeOfDay
    {
        $aligned = $this->coerce($limit->format($this->format));
        if ($aligned instanceof TypeMismatch || $aligned->hour() !== $limit->hour()) {
            throw new InvalidArgumentException(
                'time() reads times with \'' . $this->format . '\', which does not read back ' . $limit . '.',
            );
        }

        return $aligned;
    }

    /**
     * Append one of the four comparison rules.
     *
     * @param  string                   $rule    Rule name, also the language file key
     * @param  TimeOfDay                $limit   Limit as the caller declared it
     * @param  string|null              $message Message for this rule only
     * @param  bool                     $orEqual Whether a value equal to the limit passes
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the format cannot write the limit
     */
    private function withComparisonTo(string $rule, TimeOfDay $limit, ?string $message, bool $orEqual): static
    {
        $aligned = $this->alignLimit($limit);
        $earlier = str_starts_with($rule, 'before');

        return $this->withCheck(
            $rule,
            ['limit' => $aligned->format($this->format)],
            static function (TimeOfDay $value) use ($aligned, $earlier, $orEqual): bool {
                $ordering = $value->compareTo($aligned);
                $strictly = $earlier ? $ordering < 0 : $ordering > 0;

                return $strictly || ($orEqual && $ordering === 0);
            },
            $message,
        );
    }

    /**
     * The format with each unescaped `u` and `v` replaced by its digits, cut to the given count.
     *
     * Digits are not format characters, so the result writes them as they
     * are. `v` holds three digits, so a count above that keeps all three.
     *
     * @param  string            $format Format as the caller declared it
     * @param  DateTimeImmutable $parsed Value whose fraction is written
     * @param  int               $digits Number of digits to keep, 1 to 6
     * @return string
     */
    private static function withFractionDigits(string $format, DateTimeImmutable $parsed, int $digits): string
    {
        return preg_replace_callback(
            '/\\\\.|[uv]/s',
            static fn (array $match): string => match ($match[0]) {
                'u'     => substr($parsed->format('u'), 0, $digits),
                'v'     => substr($parsed->format('v'), 0, $digits),
                default => $match[0],
            },
            $format,
        ) ?? $format;
    }

    /**
     * Reject a format that this field could not honour.
     *
     * A format writing a date or a time zone would make the field depend on
     * something a time of day does not have. Of the rest, a format
     * createFromFormat() cannot read back would reject what it writes, so
     * each hour of the day is written and read back: `G` and `g` write one
     * digit or two depending on the hour, and `Gi` reads back 15:30 but not
     * 09:30, which it writes as `930`.
     *
     * @param  string                   $format Format as the caller declared it
     * @return void
     * @throws InvalidArgumentException When the format carries a date or a time zone, or does not read back every hour
     */
    private static function assertTimeOnly(string $format): void
    {
        // Whether the format writes a date or a time zone does not depend
        // on the time written, so one time settles it.
        try {
            TimeOfDay::fromDateTime(new DateTimeImmutable('@0'))->format($format);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(
                'time() is for a time of day, and \'' . $format . '\' carries a date or a time zone. Use date() or dateTime().',
            );
        }

        $utc = new DateTimeZone('UTC');
        foreach (range(0, 23) as $hour) {
            $rendered = new DateTimeImmutable(\sprintf('1970-01-01 %02d:30:45.123456', $hour), $utc)->format($format);

            // A trailing backslash escapes nothing and format() writes a NUL
            // byte for it, which createFromFormat() raises a ValueError on
            // rather than refusing to parse.
            $back = str_contains($rendered, "\0")
                ? false
                : DateTimeImmutable::createFromFormat($format, $rendered, $utc);
            if ($back === false
                || DateTimeImmutable::getLastErrors() !== false
                || $back->format($format) !== $rendered
            ) {
                throw new InvalidArgumentException(
                    'time() needs a format that reads back what it writes, and \'' . $format . '\' does not.',
                );
            }
        }
    }
}
