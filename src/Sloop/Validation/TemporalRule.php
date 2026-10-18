<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
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
 * A comparison limit is read in the frame of each validation, which for a
 * date is the time zone the Validator's clock reads. It is also read once in
 * the PHP default time zone when declared, and refused there if the field
 * cannot read it; one the field reads there but not in the zone of a later
 * validation is refused by that validation.
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
     * the limit to the date it falls on where the Validator's clock reads,
     * Rule::dateTime() takes it as the instant it already is.
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
     * Fail unless the value is later than the current time.
     *
     * The current time is read from the Validator's clock, once per
     * validation, and brought into the field's frame the way a limit given to
     * after() is: Rule::date() compares with today where the clock reads, so
     * a date passes from tomorrow on, and Rule::dateTime() with the instant.
     * Validating throws InvalidArgumentException when Rule::date()'s format
     * cannot write today's date and read it back (`y` past 2069); this applies
     * to the other three as well.
     *
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function afterNow(?string $message = null): static
    {
        return $this->withComparisonToNow('afterNow', $message, false);
    }

    /**
     * Fail unless the value is later than or equal to the current time.
     *
     * For Rule::date(), today passes.
     *
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function afterOrEqualNow(?string $message = null): static
    {
        return $this->withComparisonToNow('afterOrEqualNow', $message, true);
    }

    /**
     * Fail unless the value is earlier than the current time.
     *
     * For Rule::date(), a date passes up to yesterday.
     *
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function beforeNow(?string $message = null): static
    {
        return $this->withComparisonToNow('beforeNow', $message, false);
    }

    /**
     * Fail unless the value is earlier than or equal to the current time.
     *
     * For Rule::date(), today passes.
     *
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function beforeOrEqualNow(?string $message = null): static
    {
        return $this->withComparisonToNow('beforeOrEqualNow', $message, true);
    }

    /**
     * Fail unless the value is later than the validated value of another field.
     *
     * The other field is a sibling with the same factory: a date() field
     * names another date() field and a dateTime() field another dateTime()
     * field; anything else is refused when the Validator is built, as is the
     * field naming itself. A dateTime() is ordered by the instant it names,
     * whatever offset each input was written with. Each side is read with its
     * own format, and what a format does not write is the start of it: under
     * `Y-m` the value `2026-03` is 1 March, which is before `2026-03-15`.
     *
     * The rule is not evaluated when this field is empty, or when the other
     * field failed a rule of its own; an empty other field is compared by its
     * default, and passes when it has none.
     *
     * @param  string                   $field   Name of the other field
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function afterField(string $field, ?string $message = null): static
    {
        return $this->withOrderTo('afterField', $field, $message, static fn (int $order): bool => $order > 0);
    }

    /**
     * Fail unless the value is later than or equal to the validated value of another field.
     *
     * @param  string                   $field   Name of the other field
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function afterOrEqualField(string $field, ?string $message = null): static
    {
        return $this->withOrderTo('afterOrEqualField', $field, $message, static fn (int $order): bool => $order >= 0);
    }

    /**
     * Fail unless the value is earlier than the validated value of another field.
     *
     * @param  string                   $field   Name of the other field
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function beforeField(string $field, ?string $message = null): static
    {
        return $this->withOrderTo('beforeField', $field, $message, static fn (int $order): bool => $order < 0);
    }

    /**
     * Fail unless the value is earlier than or equal to the validated value of another field.
     *
     * @param  string                   $field   Name of the other field
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function beforeOrEqualField(string $field, ?string $message = null): static
    {
        return $this->withOrderTo('beforeOrEqualField', $field, $message, static fn (int $order): bool => $order <= 0);
    }

    /**
     * Keep a declared default as the point in time it names, refusing one this field could not read.
     *
     * It is brought into the frame of each validation when handed out.
     *
     * @param  mixed                    $value Value the container's default holds for this field
     * @return mixed
     * @throws InvalidArgumentException When the value is not a DateTimeInterface, or cannot be brought into the frame of this field in the PHP default time zone
     */
    protected function prepareDefault(mixed $value): mixed
    {
        if (!$value instanceof DateTimeInterface) {
            throw self::refusedDefault('a DateTimeInterface', $value);
        }

        return $this->heldLimit($value);
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
     * @param  DateTimeZone             $zone  Time zone of the validation
     * @return DateTimeImmutable
     * @throws InvalidArgumentException When the limit cannot be brought into the frame
     */
    abstract protected function alignLimit(DateTimeInterface $limit, DateTimeZone $zone): DateTimeImmutable;

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
     * @param  DateTimeZone      $zone  Time zone of the validation
     * @return string
     */
    abstract protected function describeLimit(DateTimeInterface $limit, DateTimeZone $zone): string;

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
            // A format carrying an offset takes it from the input. One without
            // is read in UTC, which skips no day, so the date read does not
            // depend on the PHP default; inFrame() moves it to where the clock
            // reads.
            $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
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
        $held    = $this->heldLimit($limit);
        $inFrame = new LimitInFrame($this->alignLimit(...));
        $earlier = str_starts_with($rule, 'before');

        return $this->withCheck(
            $rule,
            [],
            static fn (DateTimeImmutable $value, DateTimeImmutable $now): bool => self::orders(
                $value <=> $inFrame->in($held, $now->getTimezone()),
                $earlier,
                $orEqual,
            ),
            $message,
            fn (DateTimeImmutable $now): array => ['limit' => $this->describeLimit($held, $now->getTimezone())],
        );
    }

    /**
     * Append one of the four rules comparing with the current time.
     *
     * @param  string                   $rule    Rule name, also the language file key
     * @param  string|null              $message Message for this rule only
     * @param  bool                     $orEqual Whether a value equal to the current time, in the field's frame, passes
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    private function withComparisonToNow(string $rule, ?string $message, bool $orEqual): static
    {
        $inFrame = new LimitInFrame($this->alignLimit(...));
        $earlier = str_starts_with($rule, 'before');

        return $this->withCheck(
            $rule,
            [],
            static fn (DateTimeImmutable $value, DateTimeImmutable $now): bool => self::orders(
                $value <=> $inFrame->in($now, $now->getTimezone()),
                $earlier,
                $orEqual,
            ),
            $message,
            fn (DateTimeImmutable $now): array => ['limit' => $this->describeLimit($now, $now->getTimezone())],
        );
    }

    /**
     * Keep a limit or default as the point in time it names, once this field has shown it can read it.
     *
     * Read in the PHP default time zone, the one a declaration is most
     * likely to share with the validations that follow, so that a limit
     * the field's format could never write is refused when declared.
     *
     * @param  DateTimeInterface        $limit Limit or default as the caller declared it
     * @return DateTimeImmutable
     * @throws InvalidArgumentException When the limit cannot be brought into the frame of this field in the PHP default time zone
     */
    protected function heldLimit(DateTimeInterface $limit): DateTimeImmutable
    {
        $this->alignLimit($limit, new DateTimeZone(date_default_timezone_get()));

        return DateTimeImmutable::createFromInterface($limit);
    }

    /**
     * Whether a spaceship result against the limit passes.
     *
     * @param  int  $ordering Value <=> limit
     * @param  bool $earlier  Whether the value must come before the limit
     * @param  bool $orEqual  Whether a value equal to the limit passes
     * @return bool
     */
    private static function orders(int $ordering, bool $earlier, bool $orEqual): bool
    {
        $strictly = $earlier ? $ordering < 0 : $ordering > 0;

        return $strictly || ($orEqual && $ordering === 0);
    }

    /**
     * Append one of the four rules ordering the value against another field.
     *
     * @param  string                   $rule    Rule name, also the language file key
     * @param  string                   $field   Name of the other field
     * @param  string|null              $message Message for this rule only
     * @param  Closure(int): bool       $holds   Whether the spaceship result of the value against the other passes
     * @return static
     * @throws InvalidArgumentException When the message template is malformed
     */
    private function withOrderTo(string $rule, string $field, ?string $message, Closure $holds): static
    {
        // The other side is null only when that field was empty without a default.
        return $this->withFieldComparison(
            $rule,
            $field,
            $message,
            static fn (DateTimeImmutable $own, mixed $other): bool => !$other instanceof DateTimeImmutable || $holds($own <=> $other),
        );
    }
}
