<?php

declare(strict_types=1);

namespace Sloop\Validation;

use InvalidArgumentException;

/**
 * Validates input arrays against a fixed set of field rules.
 *
 *     $validator = new Validator([
 *         'name' => Rule::string(Sanitize::Trim)->required()->maxLength(50),
 *         'age'  => Rule::int()->between(0, 150),
 *     ]);
 *     $result = $validator->validate($input);
 *
 * One validator can validate any number of inputs. Every rule of every field
 * runs, so a field reports all its failures at once; an empty value runs only
 * required(), and a type failure stops the remaining rules of that field.
 * same() / different() run after all fields, so the order of the fields does
 * not matter.
 */
final readonly class Validator
{
    /**
     * Field rules keyed by field name.
     *
     * @var array<string, FieldRule<covariant mixed>>
     */
    private array $rules;

    /**
     * Create a validator.
     *
     * @param  array<string, FieldRule<covariant mixed>> $rules Field rules keyed by field name
     * @throws InvalidArgumentException                  When a same() / different() rule names a field that has no rule
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $field => $rule) {
            foreach ($rule->comparisons() as $comparison) {
                if (!\array_key_exists($comparison->other, $rules)) {
                    throw new InvalidArgumentException(
                        'Field "' . $field . '" compares with "' . $comparison->other . '", which has no rule.',
                    );
                }
            }
        }

        $this->rules = $rules;
    }

    /**
     * A copy of this validator with one more field; this validator is unchanged.
     *
     * @param  string                     $field Field name
     * @param  FieldRule<covariant mixed> $rule  Rules of the field
     * @return self
     * @throws InvalidArgumentException   When the field already has rules, or a comparison names a field that has no rule
     */
    public function with(string $field, FieldRule $rule): self
    {
        if (\array_key_exists($field, $this->rules)) {
            throw new InvalidArgumentException('Field "' . $field . '" already has rules.');
        }

        return new self([...$this->rules, $field => $rule]);
    }

    /**
     * Validate one input array.
     *
     * Keys of $data without a rule are ignored and do not appear in the values.
     *
     * @param  array<array-key, mixed>   $data Input, e.g. a decoded JSON body
     * @return ValidationResult
     * @throws \RuntimeException         When a message pattern cannot be formatted, or PCRE aborts while a sanitizer is running
     * @throws \UnexpectedValueException When a sanitizer closure returns something other than a string
     */
    public function validate(array $data): ValidationResult
    {
        [$outcomes, $failures] = $this->evaluateFields($data);

        $values = [];
        $errors = [];
        foreach ($failures as $field => $fieldFailures) {
            if ($fieldFailures === []) {
                $values[$field] = $outcomes[$field]->value;
                continue;
            }
            foreach ($fieldFailures as $failure) {
                $errors[$this->key($field, $failure)][] = $this->error($field, $failure);
            }
        }

        return new ValidationResult($values, $errors);
    }

    /**
     * Resolve the message of a failure.
     *
     * The message comes from the rule's `message:`, then the field's
     * message(), then the language file. `{label}` is the field's label, and
     * `{other}` of same() / different() is the other field's label.
     *
     * @param  string            $field   Field name
     * @param  Failure           $failure Failed rule
     * @return ValidationError
     * @throws \RuntimeException When the message pattern cannot be formatted
     */
    private function error(string $field, Failure $failure): ValidationError
    {
        $rule    = $this->rules[$field];
        $pattern = $failure->message ?? $rule->fieldMessage() ?? ValidationMessages::get($failure->rule);
        $args    = [...$failure->params, 'label' => $this->label($field, $failure)];
        if ($failure->otherLabel !== null) {
            $args['other'] = $failure->otherLabel;
        }

        return new ValidationError($failure->rule, $failure->params, ValidationMessages::format($pattern, $args));
    }

    /**
     * Run every field rule and every comparison, before any message is resolved.
     *
     * ShapeRule validates its keys through this, so that the messages of the
     * failures are resolved once, by the validator of the outermost field,
     * with the whole path in the label.
     *
     * @internal Called by ShapeRule.
     *
     * @param  array<array-key, mixed>                                          $data Input
     * @return array{array<string, FieldOutcome>, array<string, list<Failure>>} Outcome and failures of each field
     * @throws \UnexpectedValueException                                        When a sanitizer closure returns the wrong type
     * @throws \RuntimeException                                                When PCRE aborts while a sanitizer is running
     */
    public function evaluateFields(array $data): array
    {
        $outcomes = [];
        $failures = [];
        foreach ($this->rules as $field => $rule) {
            $outcomes[$field] = $rule->evaluate($data[$field] ?? null);
            $failures[$field] = $outcomes[$field]->failures;
        }

        foreach ($this->rules as $field => $rule) {
            if (!$outcomes[$field]->comparing) {
                continue;
            }
            foreach ($rule->comparisons() as $comparison) {
                $other = $comparison->other;
                if ($outcomes[$other]->failures !== []) {
                    continue;
                }
                if (!$comparison->passes($outcomes[$field]->comparable, $outcomes[$other]->comparable)) {
                    $failures[$field][] = new Failure(
                        $comparison->rule,
                        ['other' => $other],
                        $comparison->message,
                        otherLabel: $this->label($other),
                    );
                }
            }
        }

        return [$outcomes, $failures];
    }

    /**
     * Key the error is reported under: the field name, plus the path to the element that failed.
     *
     * @param  string  $field   Field name
     * @param  Failure $failure Failed rule
     * @return string
     */
    private function key(string $field, Failure $failure): string
    {
        return $failure->path === [] ? $field : $field . '.' . implode('.', $failure->path);
    }

    /**
     * Label of a failure: the field's label, plus the path to the element that failed.
     *
     * The field's label is its label() if set and its name otherwise, and each
     * further step is the element's label() if set and its key otherwise.
     *
     * @param  string       $field   Field name
     * @param  Failure|null $failure Failed rule, when the label names the element it happened on
     * @return string
     */
    private function label(string $field, ?Failure $failure = null): string
    {
        $label = $this->rules[$field]->displayLabel() ?? $field;
        if ($failure === null || $failure->labelPath === []) {
            return $label;
        }

        return $label . '.' . implode('.', $failure->labelPath);
    }
}
