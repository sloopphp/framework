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
     * @return static
     * @throws \InvalidArgumentException When the message template is malformed
     */
    public function distinct(?string $message = null): static
    {
        return $this->withElementsCheck('distinct', static function (array $value): bool {
            $compared = [];
            foreach ($value as $item) {
                if ($item !== null && $item !== '') {
                    $compared[] = $item;
                }
            }

            return !DistinctValues::hasDuplicate($compared);
        }, $message);
    }
}
