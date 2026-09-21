<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use InvalidArgumentException;

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
     * @param  FieldRule<covariant mixed>                                  $element         Rules every element must satisfy
     * @param  list<ArraySanitize|Closure(array<array-key, mixed>): mixed> $arraySanitizers Applied in order once the value is an array
     * @throws InvalidArgumentException                                    When the element declares same() or different(), which have no sibling to name here
     */
    public function __construct(
        private readonly FieldRule $element,
        array $arraySanitizers,
    ) {
        if ($element->comparisons() !== []) {
            throw new InvalidArgumentException(
                'The element of list() cannot compare with another field: there is no set of siblings to name.',
            );
        }

        parent::__construct($arraySanitizers);
    }

    /**
     * Use this value when the field is empty (missing or null).
     *
     * The default is handed to the caller as it is, so it has to be a list
     * already: the type of the field is what the caller reads, and a default
     * that is not one would break it without any rule having failed.
     *
     * @param  array<array-key, mixed>  $value Value to use for an empty field
     * @return static
     * @throws InvalidArgumentException When $value is not a list
     * @throws \LogicException          When the field is required or a default has already been declared
     */
    public function default(array $value): static
    {
        if (!array_is_list($value)) {
            throw new InvalidArgumentException('The default of list() must be a list, and this one has other keys.');
        }

        return parent::default($value);
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
