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
     * Every element is read by the element rule the way that rule reads its own
     * default, so a date is reduced to one the element could itself have
     * produced rather than being kept as it was written.
     *
     * @param  array<array-key, mixed>  $value Value to use for an empty field
     * @return static
     * @throws InvalidArgumentException When $value is not a list, or holds a value the element rule does not take
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
     * Read every element of a declared default the way the element rule reads its own.
     *
     * @param  array<array-key, mixed>  $value Default as the caller declared it
     * @return list<mixed>
     * @throws InvalidArgumentException When $value is not a list, or holds a value the element rule does not take
     */
    private function prepareElements(array $value): array
    {
        if (!array_is_list($value)) {
            throw new InvalidArgumentException('The default of list() must be a list, and this one has other keys.');
        }

        $prepared = [];
        foreach ($value as $item) {
            $prepared[] = $this->element->prepareDefault($item);
        }

        return $prepared;
    }

    /**
     * Validate every element, keeping the failures of all of them.
     *
     * Not reached when a rule on the number of elements has failed.
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
