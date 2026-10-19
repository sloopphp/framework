<?php

declare(strict_types=1);

namespace Sloop\Validation;

use InvalidArgumentException;

/**
 * Rules for a field whose validated value is an int.
 *
 * Accepts a JSON int or a string of the form `-?\d+` (`"042"` is 42).
 * Whitespace, `+`, a decimal point, an exponent, a float (`42.0` included),
 * and a value outside the int range are type failures.
 *
 * @extends FieldRule<int, int>
 */
final class IntRule extends FieldRule
{
    /**
     * Use this value when the field is empty.
     *
     * The default is returned as is; the declared rules are not applied to it.
     *
     * @param  int             $value Value to use for an empty field
     * @return self
     * @throws \LogicException When the field is required or a default has already been declared
     */
    public function default(int $value): self
    {
        return $this->withDefault($value);
    }

    /**
     * Prepare a value a container declares as the default of this field.
     *
     * @param  mixed                    $value Value the container's default holds for this field, not empty
     * @return mixed
     * @throws InvalidArgumentException When the value is not an int
     */
    protected function prepareDefault(mixed $value): mixed
    {
        if (!\is_int($value)) {
            throw self::refusedDefault('an int', $value);
        }

        return $value;
    }

    /**
     * Fail when the value is less than given.
     *
     * @param  int                      $min     Smallest accepted value
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function min(int $min, ?string $message = null): self
    {
        return $this->withCheck('min', ['min' => $min], static fn (int $value): bool => $value >= $min, $message);
    }

    /**
     * Fail when the value is greater than given.
     *
     * @param  int                      $max     Largest accepted value
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function max(int $max, ?string $message = null): self
    {
        return $this->withCheck('max', ['max' => $max], static fn (int $value): bool => $value <= $max, $message);
    }

    /**
     * Fail unless the value is between the two bounds, both included.
     *
     * @param  int                      $min     Smallest accepted value
     * @param  int                      $max     Largest accepted value
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $min is greater than $max or the message template is malformed
     */
    public function between(int $min, int $max, ?string $message = null): self
    {
        if ($min > $max) {
            throw new InvalidArgumentException('between() needs min <= max, got ' . $min . ' and ' . $max . '.');
        }

        return $this->withCheck(
            'between',
            ['min' => $min, 'max' => $max],
            static fn (int $value): bool => $value >= $min && $value <= $max,
            $message,
        );
    }

    /**
     * Fail unless the value is one of the given ints.
     *
     * @param  list<int>                $values  Accepted values
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $values is empty or the message template is malformed
     */
    public function in(array $values, ?string $message = null): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('in() needs at least one value.');
        }

        return $this->withCheck('in', ['values' => $values], static fn (int $value): bool => \in_array($value, $values, true), $message);
    }

    /**
     * Fail when the value is one of the given ints.
     *
     * @param  list<int>                $values  Rejected values
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $values is empty or the message template is malformed
     */
    public function notIn(array $values, ?string $message = null): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('notIn() needs at least one value.');
        }

        return $this->withCheck('notIn', ['values' => $values], static fn (int $value): bool => !\in_array($value, $values, true), $message);
    }

    /**
     * Read an int.
     *
     * @param  mixed            $value Raw value
     * @return int|TypeMismatch
     */
    protected function coerce(mixed $value): int|TypeMismatch
    {
        return NumberParser::int($value) ?? new TypeMismatch();
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'int';
    }

    /**
     * Whether a validated value of this field can be the given value: any int.
     *
     * @internal Read by Validator to check the values of requiredIf() / when().
     *
     * @param  mixed $value Value given to requiredIf() / when()
     * @return bool
     */
    public function canHold(mixed $value): bool
    {
        return \is_int($value);
    }

    /**
     * The validated value as it is.
     *
     * @param  int $value Validated value
     * @return int
     */
    #[\Override]
    protected function output(mixed $value): mixed
    {
        return $value;
    }
}
