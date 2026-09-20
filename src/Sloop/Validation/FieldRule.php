<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use LogicException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Rules declared on one field, starting from the type the field must have.
 *
 * Each builder method returns a modified copy, so a rule held in a shared
 * variable is never changed by a later call on it.
 *
 * Processing order for one value: sanitize (only when the raw value is a
 * valid UTF-8 string), then the emptiness check (a missing key, null, and ''
 * all count as empty), then the type check, then every declared rule. An empty
 * value runs no rule other than required() and yields the declared default;
 * a value of the wrong type stops before the declared rules.
 *
 * Every subclass declares default() with the native type of its validated
 * value, and passes the value on to withDefault().
 *
 * @template T
 */
abstract class FieldRule
{
    /**
     * Whether an empty value is an error.
     *
     * @var bool
     */
    private bool $required = false;

    /**
     * Message given to required() with `message:`.
     *
     * @var string|null
     */
    private ?string $requiredMessage = null;

    /**
     * Whether default() was called.
     *
     * @var bool
     */
    private bool $hasDefault = false;

    /**
     * Value used when the field is empty.
     *
     * @var T|null
     */
    private mixed $default = null;

    /**
     * Message used for every rule of this field that has no message of its own.
     *
     * @var string|null
     */
    private ?string $fieldMessage = null;

    /**
     * Name shown in messages in place of the field name.
     *
     * @var string|null
     */
    private ?string $displayLabel = null;

    /**
     * Declared rules, in declaration order.
     *
     * @var list<Check<T>>
     */
    private array $checks = [];

    /**
     * Declared same() / different() rules.
     *
     * @var list<Comparison>
     */
    private array $comparisons = [];

    /**
     * Create a rule set for one field.
     *
     * @param list<Sanitize|Closure(string): mixed> $sanitizers Sanitizers applied before validation, in order
     */
    public function __construct(
        private array $sanitizers,
    ) {
    }

    /**
     * Fail when the field is empty (missing, null, or '').
     *
     * @param  string|null               $message Message for this rule only
     * @return static
     * @throws LogicException            When a default has been declared, since the default would never be used
     * @throws \InvalidArgumentException When the message template is malformed
     */
    public function required(?string $message = null): static
    {
        if ($this->hasDefault) {
            throw new LogicException('A field cannot be both required and have a default.');
        }
        if ($message !== null) {
            ValidationMessages::assertValidPattern($message);
        }

        $copy                  = clone $this;
        $copy->required        = true;
        $copy->requiredMessage = $message;

        return $copy;
    }

    /**
     * Use this message for every failure of this field that has no message of its own.
     *
     * The position in the chain does not matter: the message applies to all
     * rules of the field, not to the rule written just before it.
     *
     * @param  string                    $message Message template; `{label}` and the rule parameters are filled in
     * @return static
     * @throws LogicException            When a field message has already been set
     * @throws \InvalidArgumentException When the template is malformed or contains `'{`
     */
    public function message(string $message): static
    {
        if ($this->fieldMessage !== null) {
            throw new LogicException('The field message has already been set.');
        }
        ValidationMessages::assertValidPattern($message);

        $copy               = clone $this;
        $copy->fieldMessage = $message;

        return $copy;
    }

    /**
     * Name to show in messages in place of the field name.
     *
     * @param  string         $label Display name
     * @return static
     * @throws LogicException When a label has already been set
     */
    public function label(string $label): static
    {
        if ($this->displayLabel !== null) {
            throw new LogicException('The label has already been set.');
        }

        $copy               = clone $this;
        $copy->displayLabel = $label;

        return $copy;
    }

    /**
     * Fail unless the validated value equals the validated value of another field.
     *
     * Both sides are compared after sanitizing and type conversion. When the
     * other field itself failed, this rule is not evaluated.
     *
     * @param  string                    $field   Name of the other field
     * @param  string|null               $message Message for this rule only
     * @return static
     * @throws \InvalidArgumentException When the message template is malformed
     */
    public function same(string $field, ?string $message = null): static
    {
        return $this->withComparison(new Comparison('same', $field, $message));
    }

    /**
     * Fail when the validated value equals the validated value of another field.
     *
     * @param  string                    $field   Name of the other field
     * @param  string|null               $message Message for this rule only
     * @return static
     * @throws \InvalidArgumentException When the message template is malformed
     */
    public function different(string $field, ?string $message = null): static
    {
        return $this->withComparison(new Comparison('different', $field, $message));
    }

    /**
     * Validate one raw value.
     *
     * @internal Called by Validator.
     *
     * @param  mixed                    $raw Raw input value; null when the key is missing
     * @return FieldOutcome
     * @throws UnexpectedValueException When a sanitizer closure returns something other than a string
     * @throws RuntimeException         When PCRE aborts while a Sanitize case is running
     */
    public function evaluate(mixed $raw): FieldOutcome
    {
        $value = $raw;
        if (\is_string($value) && mb_check_encoding($value, 'UTF-8')) {
            $value = $this->sanitize($value);
        }

        if ($value === null || $value === '') {
            if ($this->required) {
                return new FieldOutcome(null, null, false, [new Failure('required', [], $this->requiredMessage)]);
            }

            return $this->default === null
                ? new FieldOutcome(null, null, false, [])
                : new FieldOutcome($this->output($this->default), $this->comparable($this->default), false, []);
        }

        $typed = $this->coerce($value);
        if ($typed instanceof TypeMismatch) {
            return new FieldOutcome(null, null, false, [new Failure($this->typeRule(), $this->typeParams())]);
        }

        $failures = [];
        foreach ($this->checks as $check) {
            if (!($check->passes)($typed)) {
                $failures[] = new Failure($check->rule, $check->params, $check->message);
            }
        }

        return new FieldOutcome($this->output($typed), $this->comparable($typed), true, $failures);
    }

    /**
     * The label set with label(), if any.
     *
     * @internal Read by Validator when building messages.
     *
     * @return string|null
     */
    public function displayLabel(): ?string
    {
        return $this->displayLabel;
    }

    /**
     * The message set with message(), if any.
     *
     * @internal Read by Validator when building messages.
     *
     * @return string|null
     */
    public function fieldMessage(): ?string
    {
        return $this->fieldMessage;
    }

    /**
     * The declared same() / different() rules.
     *
     * @internal Read by Validator, which evaluates them once every field has its value.
     *
     * @return list<Comparison>
     */
    public function comparisons(): array
    {
        return $this->comparisons;
    }

    /**
     * Convert a non-empty raw value to the declared type.
     *
     * @param  mixed          $value Raw value after sanitizing; never null or ''
     * @return T|TypeMismatch
     */
    abstract protected function coerce(mixed $value): mixed;

    /**
     * Rule name reported when the value does not have the declared type.
     *
     * @return string
     */
    abstract protected function typeRule(): string;

    /**
     * Parameters reported with the type failure.
     *
     * @return array<string, int|float|string|list<int|float|string>>
     */
    protected function typeParams(): array
    {
        return [];
    }

    /**
     * Turn a validated value into the value handed to the caller.
     *
     * @param  T     $value Validated value
     * @return mixed
     */
    protected function output(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Turn a validated value into the value same() / different() compare with `===`.
     *
     * @param  T     $value Validated value
     * @return mixed
     */
    protected function comparable(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Store the default after the subclass has prepared it.
     *
     * @param  T              $value Default value
     * @return static
     * @throws LogicException When the field is required or a default has already been declared
     */
    protected function withDefault(mixed $value): static
    {
        if ($this->required) {
            throw new LogicException('A field cannot be both required and have a default.');
        }
        if ($this->hasDefault) {
            throw new LogicException('The default has already been set.');
        }

        $copy             = clone $this;
        $copy->hasDefault = true;
        $copy->default    = $value;

        return $copy;
    }

    /**
     * Append a rule.
     *
     * @param  string                                                 $rule    Rule name, also the language file key
     * @param  array<string, int|float|string|list<int|float|string>> $params  Parameters keyed by placeholder name
     * @param  Closure(T): bool                                       $passes  Returns true when the value satisfies the rule
     * @param  string|null                                            $message Message for this rule only
     * @return static
     * @throws \InvalidArgumentException                              When the message template is malformed
     */
    protected function withCheck(string $rule, array $params, Closure $passes, ?string $message): static
    {
        if ($message !== null) {
            ValidationMessages::assertValidPattern($message);
        }

        $copy           = clone $this;
        $copy->checks[] = new Check($rule, $params, $passes, $message);

        return $copy;
    }

    /**
     * Append a same() / different() rule.
     *
     * @param  Comparison                $comparison Rule to append
     * @return static
     * @throws \InvalidArgumentException When the message template is malformed
     */
    private function withComparison(Comparison $comparison): static
    {
        if ($comparison->message !== null) {
            ValidationMessages::assertValidPattern($comparison->message);
        }

        $copy                = clone $this;
        $copy->comparisons[] = $comparison;

        return $copy;
    }

    /**
     * Run the sanitizers in order.
     *
     * @param  string                   $value Valid UTF-8 input
     * @return string
     * @throws UnexpectedValueException When a closure returns something other than a string
     * @throws RuntimeException         When PCRE aborts while a Sanitize case is running
     */
    private function sanitize(string $value): string
    {
        foreach ($this->sanitizers as $sanitizer) {
            if ($sanitizer instanceof Sanitize) {
                $value = $sanitizer->apply($value);
                continue;
            }

            $result = $sanitizer($value);
            if (!\is_string($result)) {
                throw new UnexpectedValueException(
                    'A sanitizer closure must return a string, got ' . get_debug_type($result) . '.',
                );
            }
            $value = $result;
        }

        return $value;
    }
}
