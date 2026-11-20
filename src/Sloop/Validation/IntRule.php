<?php

declare(strict_types=1);

namespace Sloop\Validation;

use InvalidArgumentException;
use TypeError;

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
    #[\NoDiscard('IntRule::default() returns a new rule. The original is not modified.')]
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
     * @param  string|null              $flag    Flag the result raises when this rule fails (see ValidationResult::hasFlag())
     * @return self
     * @throws InvalidArgumentException When the message template or the flag name is malformed
     */
    #[\NoDiscard('IntRule::min() returns a new rule. The original is not modified.')]
    public function min(int $min, ?string $message = null, ?string $flag = null): self
    {
        return $this->withCheck('min', ['min' => $min], static fn (int $value): bool => $value >= $min, $message, flag: $flag);
    }

    /**
     * Fail when the value is greater than given.
     *
     * @param  int                      $max     Largest accepted value
     * @param  string|null              $message Message for this rule only
     * @param  string|null              $flag    Flag the result raises when this rule fails (see ValidationResult::hasFlag())
     * @return self
     * @throws InvalidArgumentException When the message template or the flag name is malformed
     */
    #[\NoDiscard('IntRule::max() returns a new rule. The original is not modified.')]
    public function max(int $max, ?string $message = null, ?string $flag = null): self
    {
        return $this->withCheck('max', ['max' => $max], static fn (int $value): bool => $value <= $max, $message, flag: $flag);
    }

    /**
     * Fail unless the value is between the two bounds, both included.
     *
     * @param  int                      $min     Smallest accepted value
     * @param  int                      $max     Largest accepted value
     * @param  string|null              $message Message for this rule only
     * @param  string|null              $flag    Flag the result raises when this rule fails (see ValidationResult::hasFlag())
     * @return self
     * @throws InvalidArgumentException When $min is greater than $max, or the message template or the flag name is malformed
     */
    #[\NoDiscard('IntRule::between() returns a new rule. The original is not modified.')]
    public function between(int $min, int $max, ?string $message = null, ?string $flag = null): self
    {
        if ($min > $max) {
            throw new InvalidArgumentException('between() needs min <= max, got ' . $min . ' and ' . $max . '.');
        }

        return $this->withCheck(
            'between',
            ['min' => $min, 'max' => $max],
            static fn (int $value): bool => $value >= $min && $value <= $max,
            $message,
            flag: $flag,
        );
    }

    /**
     * Fail unless the value is one of the given ints.
     *
     * @param  list<int>                $values  Accepted values
     * @param  string|null              $message Message for this rule only
     * @param  string|null              $flag    Flag the result raises when this rule fails (see ValidationResult::hasFlag())
     * @return self
     * @throws InvalidArgumentException When $values is empty, or the message template or the flag name is malformed
     * @throws TypeError                When a value in the list is not an int
     */
    #[\NoDiscard('IntRule::in() returns a new rule. The original is not modified.')]
    public function in(array $values, ?string $message = null, ?string $flag = null): self
    {
        self::assertCandidates($values, 'in');

        return $this->withCheck('in', ['values' => $values], static fn (int $value): bool => \in_array($value, $values, true), $message, flag: $flag);
    }

    /**
     * Fail when the value is one of the given ints.
     *
     * @param  list<int>                $values  Rejected values
     * @param  string|null              $message Message for this rule only
     * @param  string|null              $flag    Flag the result raises when this rule fails (see ValidationResult::hasFlag())
     * @return self
     * @throws InvalidArgumentException When $values is empty, or the message template or the flag name is malformed
     * @throws TypeError                When a value in the list is not an int
     */
    #[\NoDiscard('IntRule::notIn() returns a new rule. The original is not modified.')]
    public function notIn(array $values, ?string $message = null, ?string $flag = null): self
    {
        self::assertCandidates($values, 'notIn');

        return $this->withCheck('notIn', ['values' => $values], static fn (int $value): bool => !\in_array($value, $values, true), $message, flag: $flag);
    }

    /**
     * Reject an empty candidate list, or a candidate that is not an int, which no validated value could equal.
     *
     * A candidate of another type is named by its position, not by its key,
     * so nothing from the array itself reaches the message.
     *
     * @param  array<array-key, mixed>  $values Candidates as declared
     * @param  string                   $rule   Rule name for the message
     * @return void
     * @throws InvalidArgumentException When $values is empty
     * @throws TypeError                When a candidate is not an int
     */
    private static function assertCandidates(array $values, string $rule): void
    {
        if ($values === []) {
            throw new InvalidArgumentException($rule . '() needs at least one value.');
        }
        $position = 0;
        foreach ($values as $value) {
            if (!\is_int($value)) {
                throw new TypeError($rule . '() needs ints, got ' . get_debug_type($value) . ' at position ' . $position . '.');
            }
            ++$position;
        }
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

    /**
     * Type of the validated value as PHP names it.
     *
     * @internal Read by ValidatedRequest.
     *
     * @return string
     */
    #[\Override]
    public function valueType(): string
    {
        return 'int';
    }
}
