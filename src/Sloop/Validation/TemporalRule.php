<?php

declare(strict_types=1);

namespace Sloop\Validation;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Shared behaviour of the field rules whose validated value is a point in time.
 *
 * A subclass names the formats it accepts and how a comparison limit is
 * brought into the same frame as the value; the four comparison rules and the
 * parsing are the same for both. Parsing rejects a value that PHP would
 * silently repair: `2026-02-30` is reported as a type failure rather than
 * rolled forward to 2 March.
 *
 * @extends FieldRule<DateTimeImmutable>
 */
abstract class TemporalRule extends FieldRule
{
    /**
     * Fail unless the value is earlier than the given point in time.
     *
     * The limit is brought into the same frame as the value before the two are
     * compared. What that frame is follows from the type: Rule::date() reduces
     * the limit to the date it names, Rule::dateTime() takes it as the instant
     * it already is.
     *
     * @param  DateTimeInterface        $limit   Point in time the value must precede
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the limit cannot be brought into the field's frame
     */
    public function before(DateTimeInterface $limit, ?string $message = null): static
    {
        return $this->withComparisonTo('before', $limit, $message, false);
    }

    /**
     * Fail unless the value is earlier than or equal to the given point in time.
     *
     * @param  DateTimeInterface        $limit   Latest accepted point in time
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the limit cannot be brought into the field's frame
     */
    public function beforeOrEqual(DateTimeInterface $limit, ?string $message = null): static
    {
        return $this->withComparisonTo('beforeOrEqual', $limit, $message, true);
    }

    /**
     * Fail unless the value is later than the given point in time.
     *
     * @param  DateTimeInterface        $limit   Point in time the value must follow
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the limit cannot be brought into the field's frame
     */
    public function after(DateTimeInterface $limit, ?string $message = null): static
    {
        return $this->withComparisonTo('after', $limit, $message, false);
    }

    /**
     * Fail unless the value is later than or equal to the given point in time.
     *
     * @param  DateTimeInterface        $limit   Earliest accepted point in time
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the limit cannot be brought into the field's frame
     */
    public function afterOrEqual(DateTimeInterface $limit, ?string $message = null): static
    {
        return $this->withComparisonTo('afterOrEqual', $limit, $message, true);
    }

    /**
     * Reduce a declared default the way default() does.
     *
     * @param  mixed                    $value Value the container's default holds for this field
     * @return mixed
     * @throws InvalidArgumentException When the value cannot be brought into the frame of this field
     */
    protected function prepareDefault(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $this->alignLimit($value) : $value;
    }

    /**
     * Formats accepted for this field, tried in the given order.
     *
     * Each one is passed to DateTimeImmutable::createFromFormat() as written,
     * so it carries its own `!` when unmatched fields must be reset.
     *
     * @return non-empty-list<string>
     */
    abstract protected function formats(): array;

    /**
     * Bring a comparison limit into the same frame as a validated value.
     *
     * A subclass whose frame cannot hold every limit refuses the ones it
     * cannot.
     *
     * @param  DateTimeInterface        $limit Limit as the caller declared it
     * @return DateTimeImmutable
     * @throws InvalidArgumentException When the limit cannot be brought into the frame
     */
    abstract protected function alignLimit(DateTimeInterface $limit): DateTimeImmutable;

    /**
     * Whether the input is written the way this field requires.
     *
     * createFromFormat() reads a shorter field than the format asks for
     * without complaining, so `2026-1-2` parses against `!Y-m-d` and
     * `2026-01-02T3:04:05+09:00` against `!Y-m-d\TH:i:sP`. Each subclass says
     * how it rules those out.
     *
     * @param  string            $value  Raw input that parsed
     * @param  DateTimeImmutable $parsed What it parsed to
     * @return bool
     */
    abstract protected function accepts(string $value, DateTimeImmutable $parsed): bool;

    /**
     * Render a comparison limit for the error parameters.
     *
     * @param  DateTimeInterface $limit Limit as the caller declared it
     * @return string
     */
    abstract protected function describeLimit(DateTimeInterface $limit): string;

    /**
     * Read a point in time from one of the accepted formats.
     *
     * @param  mixed                          $value Raw value
     * @return DateTimeImmutable|TypeMismatch
     */
    protected function coerce(mixed $value): DateTimeImmutable|TypeMismatch
    {
        // A NUL byte survives both parse_str() and json_decode() and passes
        // the UTF-8 check, and createFromFormat() raises a ValueError on one
        // rather than failing to parse. No other type raises on one, so
        // report it here without raising either.
        if (!\is_string($value) || str_contains($value, "\0")) {
            return new TypeMismatch();
        }

        foreach ($this->formats() as $format) {
            // No time zone argument: a format carrying an offset takes it from
            // the input, and one without falls back to the PHP default either
            // way, which is what a bare date means here.
            $parsed = DateTimeImmutable::createFromFormat($format, $value);
            // getLastErrors() answers false when the value went in cleanly.
            // Anything else means PHP repaired it (2026-02-30 comes back as
            // 2 March), which is a rejection here rather than a reading.
            if ($parsed === false || DateTimeImmutable::getLastErrors() !== false) {
                continue;
            }
            if ($this->accepts($value, $parsed)) {
                return $parsed;
            }
        }

        return new TypeMismatch();
    }

    /**
     * Compare two points in time by the instant they name.
     *
     * @param  DateTimeImmutable $value Validated value
     * @return string
     */
    protected function comparable(mixed $value): string
    {
        return $value->format('U.u');
    }

    /**
     * Append one of the four comparison rules.
     *
     * @param  string                   $rule    Rule name, also the language file key
     * @param  DateTimeInterface        $limit   Limit as the caller declared it
     * @param  string|null              $message Message for this rule only
     * @param  bool                     $orEqual Whether a value equal to the limit passes
     * @return static
     * @throws InvalidArgumentException When the message template is malformed, or the limit cannot be brought into the field's frame
     */
    private function withComparisonTo(string $rule, DateTimeInterface $limit, ?string $message, bool $orEqual): static
    {
        $aligned = $this->alignLimit($limit);
        $earlier = str_starts_with($rule, 'before');

        return $this->withCheck(
            $rule,
            ['date' => $this->describeLimit($limit)],
            static function (DateTimeImmutable $value) use ($aligned, $earlier, $orEqual): bool {
                $ordering = $value <=> $aligned;
                $strictly = $earlier ? $ordering < 0 : $ordering > 0;

                return $strictly || ($orEqual && $ordering === 0);
            },
            $message,
        );
    }
}
