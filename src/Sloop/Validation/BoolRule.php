<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Rules for a field whose validated value is a bool.
 *
 * Accepts JSON true / false, the ints 1 / 0, and the strings "1" / "0" /
 * "true" / "false" (the words in any letter case). Nothing else is read as a
 * bool: "on", "yes", and 2 are type failures.
 *
 * @extends FieldRule<bool>
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
    public function default(bool $value): self
    {
        return $this->withDefault($value);
    }

    /**
     * Read a bool.
     *
     * @param  mixed             $value Raw value
     * @return bool|TypeMismatch
     */
    protected function coerce(mixed $value): bool|TypeMismatch
    {
        if (\is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (!\is_string($value)) {
            return new TypeMismatch();
        }

        return match (strtolower($value)) {
            '1', 'true'  => true,
            '0', 'false' => false,
            default      => new TypeMismatch(),
        };
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
}
