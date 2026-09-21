<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Rules shared by the three array factories.
 *
 * Accepts any PHP array; anything else is a type failure. An empty array is a
 * value, not an empty field, so required() lets `[]` through and minCount()
 * is what rejects it. Sanitizers run once the value is known to be an array,
 * before the elements and the rules of this field are looked at.
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
     * Use this value when the field is empty (missing or null).
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
     * Fail when the array holds fewer elements than given.
     *
     * @param  int                      $min     Smallest accepted number of elements
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When $min is negative or the message template is malformed
     */
    public function minCount(int $min, ?string $message = null): static
    {
        if ($min < 0) {
            throw new InvalidArgumentException('minCount() needs a count of 0 or more, got ' . $min . '.');
        }

        return $this->withCheck('minCount', ['min' => $min], static fn (array $value): bool => \count($value) >= $min, $message);
    }

    /**
     * Fail when the array holds more elements than given.
     *
     * @param  int                      $max     Largest accepted number of elements
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When $max is negative or the message template is malformed
     */
    public function maxCount(int $max, ?string $message = null): static
    {
        if ($max < 0) {
            throw new InvalidArgumentException('maxCount() needs a count of 0 or more, got ' . $max . '.');
        }

        return $this->withCheck('maxCount', ['max' => $max], static fn (array $value): bool => \count($value) <= $max, $message);
    }

    /**
     * Fail unless the number of elements is between the two bounds, both included.
     *
     * @param  int                      $min     Smallest accepted number of elements
     * @param  int                      $max     Largest accepted number of elements
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When $min is negative, greater than $max, or the message template is malformed
     */
    public function betweenCount(int $min, int $max, ?string $message = null): static
    {
        if ($min < 0) {
            throw new InvalidArgumentException('betweenCount() needs a count of 0 or more, got ' . $min . '.');
        }
        if ($min > $max) {
            throw new InvalidArgumentException('betweenCount() needs min <= max, got ' . $min . ' and ' . $max . '.');
        }

        return $this->withCheck(
            'betweenCount',
            ['min' => $min, 'max' => $max],
            static fn (array $value): bool => \count($value) >= $min && \count($value) <= $max,
            $message,
        );
    }

    /**
     * Fail unless the array holds exactly the given number of elements.
     *
     * @param  int                      $count   Accepted number of elements
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws InvalidArgumentException When $count is negative or the message template is malformed
     */
    public function exactCount(int $count, ?string $message = null): static
    {
        if ($count < 0) {
            throw new InvalidArgumentException('exactCount() needs a count of 0 or more, got ' . $count . '.');
        }

        return $this->withCheck('exactCount', ['count' => $count], static fn (array $value): bool => \count($value) === $count, $message);
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
