<?php

declare(strict_types=1);

namespace Sloop\Validation;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Rules for a field whose validated value is an instant in time.
 *
 * The input is a date and a time with a mandatory offset:
 * `2026-01-02T03:04:05+09:00`,
 * `...Z`, `...+0900` and `...+09` all name the same kind of thing, while
 * `2026-01-02T03:04:05` is a type failure. Without an offset the instant would
 * depend on where the application happens to run, and a client that can send a
 * date and a time can send the offset with them.
 *
 * Fractional seconds are optional and kept, up to microseconds, because
 * JavaScript's `Date.toISOString()` always writes three of them.
 */
final class DateTimeRule extends TemporalRule
{
    /**
     * Shape the input must have, checked before it is parsed.
     *
     * createFromFormat() would otherwise read `2026-01-02T3:04:05+09:00` and
     * a round trip cannot rule it out here, since `Z`, `+0900` and `+09` are
     * all written back as `+09:00`. The hours and minutes of the offset are
     * held to the range a clock has, which `+24:00` and `+09:99` are outside.
     * Comparisons take the limit as the instant it already names, so the time
     * of day given there is the boundary.
     *
     * @var string
     */
    private const string SHAPE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-](?:[01]\d|2[0-3])(?::?[0-5]\d)?)$/D';

    /**
     * Format used to report a comparison limit, in UTC.
     *
     * @var string
     */
    private const string LIMIT_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * Use this instant when the field is empty.
     *
     * @param  DateTimeInterface $value Instant to use for an empty field
     * @return self
     * @throws \LogicException   When the field is required or a default has already been declared
     */
    public function default(DateTimeInterface $value): self
    {
        return $this->withDefault(DateTimeImmutable::createFromInterface($value));
    }

    /**
     * With and without fractional seconds; the offset is required by both.
     *
     * @return non-empty-list<string>
     */
    protected function formats(): array
    {
        return ['!Y-m-d\TH:i:sP', '!Y-m-d\TH:i:s.uP'];
    }

    /**
     * Take the limit as the instant it already names.
     *
     * @param  DateTimeInterface $limit Limit as the caller declared it
     * @return DateTimeImmutable
     */
    protected function alignLimit(DateTimeInterface $limit): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($limit);
    }

    /**
     * Render the limit in UTC, so the same instant reads the same whatever zone it was declared in.
     *
     * @param  DateTimeInterface $limit Limit as the caller declared it
     * @return string
     */
    protected function describeLimit(DateTimeInterface $limit): string
    {
        return DateTimeImmutable::createFromInterface($limit)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::LIMIT_FORMAT);
    }

    /**
     * Require the input to have the full shape, offset included.
     *
     * @param  string            $value  Raw input that parsed
     * @param  DateTimeImmutable $parsed What it parsed to
     * @return bool
     */
    protected function accepts(string $value, DateTimeImmutable $parsed): bool
    {
        return preg_match(self::SHAPE, $value) === 1;
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'dateTime';
    }
}
