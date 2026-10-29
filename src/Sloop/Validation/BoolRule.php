<?php

declare(strict_types=1);

namespace Sloop\Validation;

use InvalidArgumentException;

/**
 * Rules for a field whose validated value is a bool.
 *
 * Accepts JSON true / false, the ints 1 / 0, and the strings "1" / "0" /
 * "true" / "false" (the words in any letter case). Nothing else is read as a
 * bool: "on", "yes", and 2 are type failures.
 *
 * @extends FieldRule<bool, bool>
 */
final class BoolRule extends FieldRule
{
    /**
     * Use this value when the field is empty.
     *
     * The default is returned as is; the declared rules are not applied to it.
     *
     * @param  bool            $value Value to use for an empty field
     * @return self
     * @throws \LogicException When the field is required or a default has already been declared
     */
    #[\NoDiscard('BoolRule::default() returns a new rule. The original is not modified.')]
    public function default(bool $value): self
    {
        return $this->withDefault($value);
    }

    /**
     * Prepare a value a container declares as the default of this field.
     *
     * @param  mixed                    $value Value the container's default holds for this field, not empty
     * @return mixed
     * @throws InvalidArgumentException When the value is not a bool
     */
    protected function prepareDefault(mixed $value): mixed
    {
        if (!\is_bool($value)) {
            throw self::refusedDefault('a bool', $value);
        }

        return $value;
    }

    /**
     * Read a bool.
     *
     * @param  mixed             $value Raw value
     * @return bool|TypeMismatch
     */
    protected function coerce(mixed $value): bool|TypeMismatch
    {
        return BoolParser::bool($value) ?? new TypeMismatch();
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'bool';
    }

    /**
     * Whether a validated value of this field can be the given value: any bool.
     *
     * @internal Read by Validator to check the values of requiredIf() / when().
     *
     * @param  mixed $value Value given to requiredIf() / when()
     * @return bool
     */
    public function canHold(mixed $value): bool
    {
        return \is_bool($value);
    }

    /**
     * The validated value as it is.
     *
     * @param  bool $value Validated value
     * @return bool
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
        return 'bool';
    }
}
