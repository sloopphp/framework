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
                    $failures[$field][] = new Failure($comparison->rule, ['other' => $other], $comparison->message);
                }
            }
        }

        $values = [];
        $errors = [];
        foreach ($failures as $field => $fieldFailures) {
            if ($fieldFailures === []) {
                $values[$field] = $outcomes[$field]->value;
                continue;
            }
            $errors[$field] = array_map(fn (Failure $failure): ValidationError => $this->error($field, $failure), $fieldFailures);
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
        $args    = [...$failure->params, 'label' => $this->label($field)];
        if (isset($failure->params['other']) && \is_string($failure->params['other'])) {
            $args['other'] = $this->label($failure->params['other']);
        }

        return new ValidationError($failure->rule, $failure->params, ValidationMessages::format($pattern, $args));
    }

    /**
     * Label of a field: its label() if set, its name otherwise.
     *
     * @param  string $field Field name
     * @return string
     */
    private function label(string $field): string
    {
        return $this->rules[$field]->displayLabel() ?? $field;
    }
}
