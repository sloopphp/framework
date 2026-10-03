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
 * second element of `items` is `items.1`. Any array is accepted, not only a
 * list, and the keys of the input reach nothing the caller reads — the
 * validated value and the errors both count positions from zero.
 *
 * A rule on the number of elements runs before any of them is read, so no
 * element is reported alongside a failure there.
 */
final class ListRule extends ArrayRule
{
    use CountsElements;

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
     * Use this value when the field is empty (missing, null, or '').
     *
     * The default has to be a list already: the type of the field is what the
     * caller reads, and a default that is not one would break it without any
     * rule having failed.
     *
     * An element that is empty as the element rule reads it takes what an
     * empty element of an input would have taken: the element's own default,
     * or null. A `false`, a `0` and a `'0'` say something and are kept as
     * written. The default does not go through the element's sanitizers, so a
     * value only a sanitizer would empty (`'  '` under Sanitize::Trim) counts
     * as a value here.
     *
     * @param  array<array-key, mixed>  $value Value to use for an empty field
     * @return static
     * @throws InvalidArgumentException When $value is not a list, holds an empty element where the element is required, or holds a value the element rule refuses at declaration
     * @throws \LogicException          When the field is required or a default has already been declared
     */
    public function default(array $value): static
    {
        return $this->withDefault($this->prepareElements($value));
    }

    /**
     * Prepare a value a container declares as the default of this field.
     *
     * @param  mixed                    $value Value the container's default holds for this field
     * @return mixed
     * @throws InvalidArgumentException When $value is an array this list does not take as a default
     */
    protected function prepareDefault(mixed $value): mixed
    {
        return \is_array($value) ? $this->prepareElements($value) : $value;
    }

    /**
     * Prepare every element of a declared default with the element rule.
     *
     * @param  array<array-key, mixed>  $value Default as the caller declared it
     * @return list<mixed>
     * @throws InvalidArgumentException When $value is not a list, holds an empty element where the element is required, or holds a value the element rule refuses at declaration
     */
    private function prepareElements(array $value): array
    {
        if (!array_is_list($value)) {
            throw new InvalidArgumentException('The default of list() must be a list, and this one has other keys.');
        }

        $prepared = [];
        foreach ($value as $position => $item) {
            if (!$this->element->isEmpty($item)) {
                $prepared[] = $this->element->prepareDefault($item);
                continue;
            }

            // An empty element takes what it would have taken in an input, as
            // a key a shape's default says nothing for does. A required element
            // has no such value: every input holding it empty fails.
            $outcome = $this->element->evaluate(null);
            if ($outcome->failures !== []) {
                throw new InvalidArgumentException(
                    'The default of list() has no value at position ' . $position . ', which is required.',
                );
            }
            $prepared[] = $outcome->value;
        }

        return $prepared;
    }

    /**
     * Whether same() / different() can say anything about this field.
     *
     * A list of values no two inputs can share is one itself, so the element
     * answers for the list.
     *
     * @internal Read by Validator for both sides of a declared comparison.
     *
     * @return bool
     */
    public function comparesByValue(): bool
    {
        return $this->element->comparesByValue();
    }

    /**
     * Validate every element, keeping the failures of all of them.
     *
     * Not reached when a rule on the number of elements has failed.
     *
     * @param  array<array-key, mixed>                       $typed Value of the declared type
     * @return array{list<Failure>, array<array-key, mixed>}
     * @throws \UnexpectedValueException                     When a sanitizer closure returns the wrong type
     * @throws \RuntimeException                             When PCRE aborts while a sanitizer is running, a file's stream cannot be read, or ICU cannot split a string into grapheme clusters
     */
    protected function validateChildren(mixed $typed): array
    {
        $failures = [];
        $values   = [];
        foreach (array_values($typed) as $position => $item) {
            $outcome = $this->element->evaluate($item);
            foreach ($outcome->failures as $failure) {
                $failures[] = $failure->under(
                    $position,
                    $this->element->displayLabel() ?? (string) $position,
                    $this->element->fieldMessage(),
                );
            }
            $values[] = $outcome->value;
        }

        return [$failures, $values];
    }
}
