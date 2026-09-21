<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;

/**
 * Rules for a field that must be an array whose elements all have one type.
 *
 * Every element is validated with the same rule, and every element that fails
 * is reported: the errors are keyed by the path down to the element, so the
 * second element of `items` is `items.1`. The validated value is a list of
 * the elements' validated values, renumbered from zero, so the keys of the
 * input do not reach the caller.
 */
final class ListRule extends ArrayRule
{
    /**
     * Create a rule set for a list field.
     *
     * @param FieldRule<covariant mixed>                                  $element         Rules every element must satisfy
     * @param list<ArraySanitize|Closure(array<array-key, mixed>): mixed> $arraySanitizers Applied in order once the value is an array
     */
    public function __construct(
        private readonly FieldRule $element,
        array $arraySanitizers,
    ) {
        parent::__construct($arraySanitizers);
    }

    /**
     * Validate every element, keeping the failures of all of them.
     *
     * @param  array<array-key, mixed>                       $typed Value of the declared type
     * @return array{list<Failure>, array<array-key, mixed>}
     * @throws \UnexpectedValueException                     When a sanitizer closure returns the wrong type
     * @throws \RuntimeException                             When PCRE aborts while a sanitizer is running
     */
    protected function validateChildren(mixed $typed): array
    {
        $failures = [];
        $values   = [];
        foreach ($typed as $key => $item) {
            $outcome = $this->element->evaluate($item);
            foreach ($outcome->failures as $failure) {
                $failures[] = $failure->under($key, (string) $key, $this->element->fieldMessage());
            }
            $values[] = $outcome->value;
        }

        return [$failures, $values];
    }
}
