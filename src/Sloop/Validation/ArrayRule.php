<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use UnexpectedValueException;

/**
 * Rules shared by the three array factories.
 *
 * Accepts any PHP array; anything else is a type failure. An empty array is a
 * value, not an empty field, so required() lets `[]` through. Sanitizers run
 * once the value is known to be an array, before the elements and the rules of
 * this field are looked at. The rules on how many elements the array holds are
 * in CountsElements, which the types whose size follows the input use.
 *
 * @extends FieldRule<array<array-key, mixed>>
 */
abstract class ArrayRule extends FieldRule
{
    /**
     * Create a rule set for an array field.
     *
     * @param list<ArraySanitize|Closure(array<array-key, mixed>): mixed> $arraySanitizers Applied in order once the value is an array
     */
    public function __construct(
        private readonly array $arraySanitizers,
    ) {
        parent::__construct([]);
    }

    /**
     * Use this value when the field is empty (missing, null, or '').
     *
     * @param  array<array-key, mixed> $value Value to use for an empty field
     * @return static
     * @throws \LogicException         When the field is required or a default has already been declared
     */
    public function default(array $value): static
    {
        return $this->withDefault($value);
    }

    /**
     * Read an array, running the declared sanitizers over it.
     *
     * @param  mixed                                $value Raw value
     * @return array<array-key, mixed>|TypeMismatch
     * @throws UnexpectedValueException             When a sanitizer closure returns something other than an array
     */
    protected function coerce(mixed $value): array|TypeMismatch
    {
        if (!\is_array($value)) {
            return new TypeMismatch();
        }

        foreach ($this->arraySanitizers as $sanitizer) {
            if ($sanitizer instanceof ArraySanitize) {
                $value = $sanitizer->apply($value);
                continue;
            }

            $result = $sanitizer($value);
            if (!\is_array($result)) {
                throw new UnexpectedValueException(
                    'An array sanitizer closure must return an array, got ' . get_debug_type($result) . '.',
                );
            }
            $value = $result;
        }

        return $value;
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'array';
    }
}
