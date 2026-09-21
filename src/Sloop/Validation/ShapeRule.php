<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use InvalidArgumentException;

/**
 * Rules for a field that must be an array giving each declared key its own type.
 *
 * Keys without a rule are dropped, the way the Validator drops the keys of the
 * input it has no rule for. Errors are keyed by the path down to the key that
 * failed, so `price` of the first `items` is `items.0.price`. same() and
 * different() declared on a key name another key of the same shape, and the
 * key they name has to be declared here.
 */
final class ShapeRule extends ArrayRule
{
    /**
     * Rules of the declared keys, which resolve the comparisons between them.
     *
     * @var Validator
     */
    private readonly Validator $keys;

    /**
     * Create a rule set for a shape field.
     *
     * @param  array<string, FieldRule<covariant mixed>>                   $fields          Rules of each key
     * @param  list<ArraySanitize|Closure(array<array-key, mixed>): mixed> $arraySanitizers Applied in order once the value is an array
     * @throws InvalidArgumentException                                    When no key is declared, or a comparison names a key that has no rule
     */
    public function __construct(
        private readonly array $fields,
        array $arraySanitizers,
    ) {
        if ($fields === []) {
            throw new InvalidArgumentException('shape() needs at least one key.');
        }

        $this->keys = new Validator($fields);

        parent::__construct($arraySanitizers);
    }

    /**
     * Validate every declared key, keeping the failures of all of them.
     *
     * @param  array<array-key, mixed>                       $typed Value of the declared type
     * @return array{list<Failure>, array<array-key, mixed>}
     * @throws \RuntimeException                             When PCRE aborts while a sanitizer is running
     * @throws \UnexpectedValueException                     When a sanitizer closure returns the wrong type
     */
    protected function validateChildren(mixed $typed): array
    {
        [$outcomes, $keyFailures] = $this->keys->evaluateFields($typed);

        $failures = [];
        $values   = [];
        foreach ($keyFailures as $key => $ownFailures) {
            // A key that failed still counts as submitted, so that a count
            // rule of this field counts what came in rather than what passed.
            if (\array_key_exists($key, $typed) || $outcomes[$key]->value !== null) {
                $values[$key] = $outcomes[$key]->value;
            }
            if ($ownFailures === []) {
                continue;
            }

            $rule = $this->fields[$key];
            foreach ($ownFailures as $failure) {
                $failures[] = $failure->under($key, $rule->displayLabel() ?? $key, $rule->fieldMessage());
            }
        }

        return [$failures, $values];
    }
}
