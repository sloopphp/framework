<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Rules for a field that must be an array, whatever it holds.
 *
 * The elements are handed to the caller as they came in. Use Rule::list() to
 * give every element a type, or Rule::shape() to give each key its own.
 */
final class AnyArrayRule extends ArrayRule
{
    use CountsElements;

    /**
     * Fail when two elements are `===`.
     *
     * The elements are compared as they came in, so `1` and `'1'` differ; to
     * compare values given one type, use Rule::list() with distinct(). An empty
     * element (null or '') is not compared. The error is reported once, on the
     * array.
     *
     * @param  string|null               $message Message for this rule only
     * @param  string|null               $flag    Flag the result raises when this rule fails (see ValidationResult::hasFlag())
     * @return static
     * @throws \InvalidArgumentException When the message template or the flag name is malformed
     */
    public function distinct(?string $message = null, ?string $flag = null): static
    {
        return $this->withElementsCheck('distinct', static function (array $value): bool {
            $compared = [];
            foreach ($value as $item) {
                if ($item !== null && $item !== '') {
                    $compared[] = $item;
                }
            }

            return !DistinctValues::hasDuplicate($compared);
        }, $message, flag: $flag);
    }

    /**
     * Prepare a value a container declares as the default of this field.
     *
     * @param  mixed                     $value Value the container's default holds for this field, not empty
     * @return mixed
     * @throws \InvalidArgumentException When the value is not an array
     */
    protected function prepareDefault(mixed $value): mixed
    {
        if (!\is_array($value)) {
            throw self::refusedDefault('an array', $value);
        }

        return $value;
    }
}
