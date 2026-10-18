<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use DateTimeImmutable;
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
     * @throws InvalidArgumentException                                    When no key is declared, a key is not a name, or the keys' rules are refused the way Validator refuses them
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
     * Use this value when the field is empty (missing, null, or '').
     *
     * The default may only hold keys this shape declares: the type of the
     * field is what the caller reads, and a default carrying anything else
     * would break it without any rule having failed.
     *
     * A key the default says nothing for — missing, or empty as the key's own
     * rule reads it — takes what an input without it would have taken. A
     * `false`, a `0` and a `'0'` say something and are kept as written.
     * Emptiness is read the way validation reads it, which is before any
     * sanitizer has run: the default does not go through them, so a value that
     * only a sanitizer would empty (`'  '` under Sanitize::Trim) counts as a
     * value here.
     *
     * Every other key is taken the way the key's own default() takes a value,
     * and refused where that would refuse it. No rule runs on the default,
     * including those between the keys: the comparisons, requiredWith(),
     * requiredIf() and when().
     *
     * @param  array<array-key, mixed>  $value Value to use for an empty field
     * @return static
     * @throws InvalidArgumentException When $value holds a key this shape does not declare, says nothing for a required one, or names a value a key's own rule refuses at declaration
     * @throws \LogicException          When the field is required or a default has already been declared
     */
    public function default(array $value): static
    {
        return $this->withDefault($this->fill($value));
    }

    /**
     * Prepare a value a container declares as the default of this field.
     *
     * @param  mixed                    $value Value the container's default holds for this field
     * @return mixed
     * @throws InvalidArgumentException When $value is not an array this shape takes as a default
     */
    protected function prepareDefault(mixed $value): mixed
    {
        if (!\is_array($value)) {
            throw self::refusedDefault('an array', $value);
        }

        return $this->fill($value);
    }

    /**
     * Hand out every key of the default the way the key hands out its own.
     *
     * @param  mixed                    $default Default as fill() returned it
     * @param  DateTimeImmutable        $now     Time of the validation
     * @return mixed
     * @throws InvalidArgumentException When a date default cannot be read back as a date in the time zone of the validation
     */
    protected function outputOfDefault(mixed $default, DateTimeImmutable $now): mixed
    {
        if (!\is_array($default)) {
            return $default;
        }

        $output = [];
        foreach ($default as $key => $item) {
            $output[$key] = $item === null ? $this->fields[$key]->evaluate(null, $now)->value : $this->fields[$key]->outputOfDefault($item, $now);
        }

        return $output;
    }

    /**
     * Compare every key of the default the way the key compares its own.
     *
     * @param  mixed                    $default Default as fill() returned it
     * @param  DateTimeImmutable        $now     Time of the validation
     * @return mixed
     * @throws InvalidArgumentException When a date default cannot be read back as a date in the time zone of the validation
     */
    protected function comparableOfDefault(mixed $default, DateTimeImmutable $now): mixed
    {
        if (!\is_array($default)) {
            return $default;
        }

        $comparable = [];
        foreach ($default as $key => $item) {
            $comparable[$key] = $item === null ? $this->fields[$key]->evaluate(null, $now)->comparable : $this->fields[$key]->comparableOfDefault($item, $now);
        }

        return $comparable;
    }

    /**
     * Give every declared key the value the default names for it.
     *
     * @param  array<array-key, mixed>  $value Default as the caller declared it
     * @return array<array-key, mixed>
     * @throws InvalidArgumentException When $value holds a key this shape does not declare, says nothing for a required one, or names a value a key's own rule refuses at declaration
     */
    private function fill(array $value): array
    {
        $undeclared = array_diff_key($value, $this->fields);
        if ($undeclared !== []) {
            throw new InvalidArgumentException(
                'The default of shape() holds keys it does not declare: ' . implode(', ', array_keys($undeclared)) . '.',
            );
        }

        $filled = [];
        foreach ($this->fields as $key => $rule) {
            // A key's own rule says what counts as nothing, the same test
            // validation runs, so a missing key reads as null and goes to it.
            if (!$rule->isEmpty($value[$key] ?? null)) {
                try {
                    $filled[$key] = $rule->prepareDefault($value[$key]);
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException(
                        'The default of shape() cannot hold its value for "' . $key . '": ' . $e->getMessage(),
                        previous: $e,
                    );
                }
                continue;
            }

            // A key the default says nothing for takes what it would have taken
            // had the field come in without it. A required key has no such value:
            // every input without it fails, so there is nothing to fill with.
            if ($rule->isRequired()) {
                throw new InvalidArgumentException(
                    'The default of shape() has no value for "' . $key . '", which is required.',
                );
            }
            // Kept as null and read through the key's rule when handed out.
            $filled[$key] = null;
        }

        return $filled;
    }

    /**
     * Whether same() / different() can say anything about this field.
     *
     * A shape holding a value no two inputs can share is one itself, so every
     * declared key has to answer true for the shape to.
     *
     * @internal Read by Validator for both sides of a declared comparison.
     *
     * @return bool
     */
    public function comparesByValue(): bool
    {
        foreach ($this->fields as $field) {
            if (!$field->comparesByValue()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate every declared key, keeping the failures of all of them.
     *
     * @param  array<array-key, mixed>                                                                         $typed Value of the declared type
     * @param  DateTimeImmutable                                                                               $now   Time of the validation, handed on to every key
     * @return array{list<Failure>, array<array-key, mixed>, array<array-key, mixed>, array<array-key, mixed>}
     * @throws \RuntimeException                                                                               When PCRE aborts while a sanitizer is running, a file's stream cannot be read, or ICU cannot split a string into grapheme clusters or count them
     * @throws \UnexpectedValueException                                                                       When a sanitizer closure returns the wrong type
     */
    protected function validateChildren(mixed $typed, DateTimeImmutable $now): array
    {
        [$outcomes, $keyFailures] = $this->keys->evaluateFields($typed, $now);

        $failures   = [];
        $values     = [];
        $comparable = [];
        foreach ($keyFailures as $key => $ownFailures) {
            // Every declared key is in the value, whether it passed, failed or
            // never came in, so that the caller reads the same shape it
            // declared and the Validator does for a field of its own.
            $values[$key]     = $outcomes[$key]->value;
            $comparable[$key] = $outcomes[$key]->comparable;
            if ($ownFailures === []) {
                continue;
            }

            $rule = $this->fields[$key];
            foreach ($ownFailures as $failure) {
                $failures[] = $failure->under($key, $rule->displayLabel() ?? $key, $rule->fieldMessage());
            }
        }

        return [$failures, $values, $comparable, $values];
    }
}
