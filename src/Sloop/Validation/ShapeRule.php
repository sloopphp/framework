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
     * @param  array<array-key, FieldRule<covariant mixed>>                $fields          Rules of each key, named (PHP turns a numeric key into an int, which is refused here)
     * @param  list<ArraySanitize|Closure(array<array-key, mixed>): mixed> $arraySanitizers Applied in order once the value is an array
     * @throws InvalidArgumentException                                    When no key is declared, a key is not a name, or a comparison names a key that has no rule
     */
    public function __construct(
        /** @var array<array-key, FieldRule<covariant mixed>> */
        private readonly array $fields,
        array $arraySanitizers,
    ) {
        if ($fields === []) {
            throw new InvalidArgumentException('shape() needs at least one key.');
        }
        $named = [];
        foreach ($fields as $key => $rule) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException(
                    'shape() needs named keys, and ' . $key . ' is a number: PHP turns a numeric key into an int, '
                    . 'which cannot name a field.',
                );
            }
            $named[$key] = $rule;
        }

        $this->keys = new Validator($named);

        parent::__construct($arraySanitizers);
    }

    /**
     * Use this value when the field is empty (missing or null).
     *
     * The default is handed to the caller as it is, so it may only hold keys
     * this shape declares: the type of the field is what the caller reads, and
     * a default carrying anything else would break it without any rule having
     * failed.
     *
     * @param  array<array-key, mixed>  $value Value to use for an empty field
     * @return static
     * @throws InvalidArgumentException When $value holds a key this shape does not declare, or leaves out a required one
     * @throws \LogicException          When the field is required or a default has already been declared
     */
    public function default(array $value): static
    {
        $undeclared = array_diff_key($value, $this->fields);
        if ($undeclared !== []) {
            throw new InvalidArgumentException(
                'The default of shape() holds keys it does not declare: ' . implode(', ', array_keys($undeclared)) . '.',
            );
        }

        $filled = [];
        foreach ($this->fields as $key => $rule) {
            if (\array_key_exists($key, $value)) {
                $filled[$key] = $value[$key];
                continue;
            }

            // A key the default leaves out takes what it would have taken had
            // the field come in without it, so that the two ways of reaching a
            // value give the same shape. A required key has no such value:
            // every input without it fails, so there is nothing to fill with.
            $outcome = $rule->evaluate(null);
            if ($outcome->failures !== []) {
                throw new InvalidArgumentException(
                    'The default of shape() leaves out "' . $key . '", which is required.',
                );
            }
            $filled[$key] = $outcome->value;
        }

        return parent::default($filled);
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
            // Every declared key is in the value, whether it passed, failed or
            // never came in, so that the caller reads the same shape it
            // declared and the Validator does for a field of its own.
            $values[$key] = $outcomes[$key]->value;
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
