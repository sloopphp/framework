<?php

declare(strict_types=1);

namespace Sloop\Validation;

use InvalidArgumentException;

/**
 * Rules for a field whose validated value is a float.
 *
 * Accepts a JSON int or float and a string of the form `-?\d+(\.\d+)?`; an
 * int becomes a float. `".5"`, `"1."`, an exponent, NAN, and INF are type
 * failures.
 *
 * @extends FieldRule<float>
 */
final class FloatRule extends FieldRule
{
    /**
     * Use this value when the field is empty.
     *
     * The default is returned as is; the declared rules are not applied to it.
     *
     * @param  float           $value Value to use for an empty field
     * @return self
     * @throws \LogicException When the field is required or a default has already been declared
     */
    public function default(float $value): self
    {
        return $this->withDefault($value);
    }

    /**
     * Fail when the value is less than given.
     *
     * @param  int|float                $min     Smallest accepted value
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $min is not finite or the message template is malformed
     */
    public function min(int|float $min, ?string $message = null): self
    {
        $bound = self::bound($min);

        return $this->withCheck('min', ['min' => $min], static fn (float $value): bool => $value >= $bound, $message);
    }

    /**
     * Fail when the value is greater than given.
     *
     * @param  int|float                $max     Largest accepted value
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $max is not finite or the message template is malformed
     */
    public function max(int|float $max, ?string $message = null): self
    {
        $bound = self::bound($max);

        return $this->withCheck('max', ['max' => $max], static fn (float $value): bool => $value <= $bound, $message);
    }

    /**
     * Fail unless the value is between the two bounds, both included.
     *
     * @param  int|float                $min     Smallest accepted value
     * @param  int|float                $max     Largest accepted value
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When a bound is not finite, $min is greater than $max, or the message template is malformed
     */
    public function between(int|float $min, int|float $max, ?string $message = null): self
    {
        $lower = self::bound($min);
        $upper = self::bound($max);
        if ($lower > $upper) {
            throw new InvalidArgumentException('between() needs min <= max, got ' . $min . ' and ' . $max . '.');
        }

        return $this->withCheck(
            'between',
            ['min' => $min, 'max' => $max],
            static fn (float $value): bool => $value >= $lower && $value <= $upper,
            $message,
        );
    }

    /**
     * Fail unless the value is one of the given numbers.
     *
     * @param  list<int|float>          $values  Accepted values; ints are compared as floats
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $values is empty, contains a non-finite number, or the message template is malformed
     */
    public function in(array $values, ?string $message = null): self
    {
        $candidates = self::candidates($values, 'in');

        return $this->withCheck('in', ['values' => $values], static fn (float $value): bool => \in_array($value, $candidates, true), $message);
    }

    /**
     * Fail when the value is one of the given numbers.
     *
     * @param  list<int|float>          $values  Rejected values; ints are compared as floats
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $values is empty, contains a non-finite number, or the message template is malformed
     */
    public function notIn(array $values, ?string $message = null): self
    {
        $candidates = self::candidates($values, 'notIn');

        return $this->withCheck('notIn', ['values' => $values], static fn (float $value): bool => !\in_array($value, $candidates, true), $message);
    }

    /**
     * Read a finite float.
     *
     * @param  mixed              $value Raw value
     * @return float|TypeMismatch
     */
    protected function coerce(mixed $value): float|TypeMismatch
    {
        return NumberParser::float($value) ?? new TypeMismatch();
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'float';
    }

    /**
     * Convert a bound to a float, rejecting NAN and INF.
     *
     * @param  int|float                $bound Bound as declared
     * @return float
     * @throws InvalidArgumentException When the bound is not finite
     */
    private static function bound(int|float $bound): float
    {
        $float = (float) $bound;
        if (!is_finite($float)) {
            throw new InvalidArgumentException('A bound must be a finite number.');
        }

        return $float;
    }

    /**
     * Convert a candidate list to floats.
     *
     * @param  list<int|float>          $values Candidates as declared
     * @param  string                   $rule   Rule name for the message
     * @return list<float>
     * @throws InvalidArgumentException When the list is empty or contains a non-finite number
     */
    private static function candidates(array $values, string $rule): array
    {
        if ($values === []) {
            throw new InvalidArgumentException($rule . '() needs at least one value.');
        }

        return array_map(self::bound(...), $values);
    }
}
