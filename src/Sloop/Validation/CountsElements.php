<?php

declare(strict_types=1);

namespace Sloop\Validation;

use InvalidArgumentException;

/**
 * Rules on how many elements an array field holds.
 *
 * An empty array is a value, not an empty field, so required() lets `[]`
 * through and minCount(1) is what rejects it. Only the array types whose size
 * follows the input use these: a shape always holds exactly the keys it
 * declares, so counting it would say nothing about what came in.
 *
 * @phpstan-require-extends ArrayRule
 */
trait CountsElements
{
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
}
