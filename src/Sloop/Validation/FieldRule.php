<?php

declare(strict_types=1);

namespace Sloop\Validation;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use TypeError;
use UnexpectedValueException;

/**
 * Rules declared on one field, starting from the type the field must have.
 *
 * Each builder method returns a modified copy, so a rule held in a shared
 * variable is never changed by a later call on it.
 *
 * Processing order for one value, by the rules declared on the field itself:
 * sanitize (only when the raw value is a valid UTF-8 string), then the
 * emptiness check (a missing key, null and '' count as empty, and a subclass
 * may add to that), then the type check, then the rules on the size of the
 * value, then what it holds, then every other declared rule. An empty value
 * fails required() or yields the declared default; a value of the wrong type
 * or the wrong size stops before what it holds is read. The rules that look
 * at other fields (requiredWith(), requiredIf(), same(), different(), the
 * other comparisons with another field and when()) are judged by Validator
 * after every field has gone through these.
 *
 * A subclass that can have a default declares default() with a native type
 * and passes the value on to withDefault().
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
     * What an empty field gives once the default has been handed out, built on first use.
     *
     * Keyed by the name of the time zone of the validation, since a date is
     * handed out as the start of that date where the clock reads.
     *
     * @var array<string, FieldOutcome>
     */
    private array $defaultOutcomes = [];

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
     * Declared rules on the size of the value, which run before what it holds is read.
     *
     * @var list<Check<T>>
     */
    private array $sizeChecks = [];

    /**
     * Declared rules that read the validated elements, which run only when every element passed.
     *
     * @var list<Check<T>>
     */
    private array $elementChecks = [];

    /**
     * Declared rules comparing this field with another field.
     *
     * @var list<Comparison>
     */
    private array $comparisons = [];

    /**
     * How each comparison that is not a same() / different() decides, keyed by its position in $comparisons.
     *
     * Each receives this field's typed value and the other field's validated
     * value, which is null when that field was empty and had no default.
     *
     * @var array<int, Closure(T, mixed): bool>
     */
    private array $comparisonTests = [];

    /**
     * Declared requiredWith() / requiredIf() rules.
     *
     * @var list<RequiredCondition>
     */
    private array $requiredConditions = [];

    /**
     * Declared when() conditions, each paired by position with its rules in $whenChecks.
     *
     * @var list<WhenCondition>
     */
    private array $whens = [];

    /**
     * Rules each when() adds: on the size of the value, on what it holds, and on its elements.
     *
     * @var list<array{list<Check<T>>, list<Check<T>>, list<Check<T>>}>
     */
    private array $whenChecks = [];

    /**
     * Create a rule set for one field.
     *
     * @param  list<Sanitize|Closure(string): mixed> $sanitizers Sanitizers applied before validation, in order
     * @throws InvalidArgumentException              When Sanitize::StripNewlines, StripTabs or StripControlChars follows Sanitize::StripTags
     */
    public function __construct(
        private array $sanitizers,
    ) {
        $strippedTags = false;
        foreach ($sanitizers as $sanitizer) {
            if ($sanitizer === Sanitize::StripTags) {
                $strippedTags = true;
                continue;
            }
            // strip_tags() leaves a `<` as text when the next character is
            // whitespace; deleting that character afterwards turns the text
            // back into a tag.
            if ($strippedTags && \in_array($sanitizer, [Sanitize::StripNewlines, Sanitize::StripTabs, Sanitize::StripControlChars], true)) {
                throw new InvalidArgumentException(
                    'Sanitize::' . $sanitizer->name . ' must come before Sanitize::StripTags, not after it.',
                );
            }
        }
    }

    /**
     * Drop what was built from the default, which a copy may change.
     *
     * @return void
     */
    public function __clone(): void
    {
        $this->defaultOutcomes = [];
    }

    /**
     * Fail when the field is empty: missing, null or '', or what a type adds to
     * that, such as an upload carrying UPLOAD_ERR_NO_FILE.
     *
     * @param  string|null               $message Message for this rule only
     * @return static
     * @throws LogicException            When a default has been declared, since the default would never be used, or requiredWith() / requiredIf() has, since the field would always be required
     * @throws \InvalidArgumentException When the message template is malformed
     */
    public function required(?string $message = null): static
    {
        if ($this->hasDefault) {
            throw new LogicException('A field cannot be both required and have a default.');
        }
        if ($this->requiredConditions !== []) {
            throw new LogicException('A field cannot be both required and required on a condition.');
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
     * Both sides are compared after sanitizing and type conversion. The rule
     * is not evaluated when the other field failed a rule of its own; a
     * failure of the other field's own same() / different(), or of the rules
     * its when() adds, does not stop it, so the result does not depend on the
     * order of the fields.
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
     * Fail when the field is empty while any of the given fields is filled.
     *
     * A field counts as filled when it is not empty or has a default, and has
     * not failed a rule of its own (a failure of its same() / different() or
     * of the rules its when() adds does not count). To require two fields together, declare
     * this on both.
     *
     * @param  string|list<string>      $fields  Name of the other field, or a list of names (keys are ignored)
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws LogicException           When the field is required
     * @throws InvalidArgumentException When $fields is an empty list, or the message template is malformed
     * @throws TypeError                When a name in the list is not a string
     */
    public function requiredWith(string|array $fields, ?string $message = null): static
    {
        if ($fields === []) {
            throw new InvalidArgumentException('requiredWith() needs at least one field.');
        }
        $names = [];
        foreach (\is_string($fields) ? [$fields] : $fields as $name) {
            try {
                $names[] = self::fieldName($name);
            } catch (TypeError $e) {
                // The engine's message names the private helper; say which name it was by
                // position, not by key, so nothing from the array itself reaches the message.
                throw new TypeError('requiredWith() needs strings, got ' . get_debug_type($name) . ' at position ' . \count($names) . '.', previous: $e);
            }
        }

        return $this->withRequiredCondition(new RequiredCondition('requiredWith', $names, [], $message));
    }

    /**
     * Fail when the field is empty while another field has one of the given values.
     *
     * The other field's validated value is compared with `===`, so it has to
     * be of the type that field yields: a string for Rule::string(), an int
     * for Rule::int(), a float for Rule::float(), a bool for Rule::bool() and
     * a case for Rule::enum(). The other field is not looked at when it is
     * empty without a default or failed a rule of its own, counted the same
     * way as in requiredWith().
     *
     * @param  string                   $field   Name of the other field
     * @param  mixed                    $value   Value, or list of values, that makes this field required
     * @param  string|null              $message Message for this rule only
     * @return static
     * @throws LogicException           When the field is required
     * @throws InvalidArgumentException When $value is null, an empty list or holds something no field yields, or the message template is malformed
     */
    public function requiredIf(string $field, mixed $value, ?string $message = null): static
    {
        return $this->withRequiredCondition(
            new RequiredCondition('requiredIf', [$field], self::conditionValues($value, 'requiredIf'), $message),
        );
    }

    /**
     * Apply more rules while another field has one of the given values.
     *
     * The closure receives this rule with no rules of its own and returns it
     * with the rules to add. It runs once, here, must add at least one rule and
     * may only add rules on the value: required(), default(), label(),
     * message(), same(), different() and the other comparisons with another
     * field, requiredWith(), requiredIf() and when() are refused. The added
     * rules run after the field's own, and like those, not when the field is
     * empty. A rule on the size of the value added here stops only the other
     * rules added by the same when().
     *
     * The other field is compared as in requiredIf(). A failure added by
     * another field's when() does not stop the comparison.
     *
     * @param  string                   $field Name of the other field
     * @param  mixed                    $value Value, or list of values, that makes the rules apply
     * @param  Closure(static): mixed   $then  Adds the rules to the rule it receives and returns it
     * @return static
     * @throws InvalidArgumentException When $value is null, an empty list or holds something no field yields, or the closure adds no rules or does anything other than add rules
     */
    public function when(string $field, mixed $value, Closure $then): static
    {
        $values = self::conditionValues($value, 'when');
        $probe  = $this->withoutRules()->withoutRuleMarks();
        $added  = $then($probe);
        if (!$added instanceof static || $added::class !== static::class) {
            throw new InvalidArgumentException('when() needs the closure to return the rule it was given, with rules added.');
        }
        if ((array) $added->withoutChecks()->withoutRuleMarks() !== (array) $probe) {
            throw new InvalidArgumentException('when() can only add rules on the value; the closure changed something else about the field.');
        }
        if ($added->sizeChecks === [] && $added->checks === [] && $added->elementChecks === []) {
            throw new InvalidArgumentException('when() needs the closure to add at least one rule.');
        }

        $copy               = clone $this;
        $copy->whens[]      = new WhenCondition($field, $values);
        $copy->whenChecks[] = [$added->sizeChecks, $added->checks, $added->elementChecks];

        return $copy;
    }

    /**
     * Validate one raw value.
     *
     * @internal Called by Validator, by ListRule for each of its elements and for an empty
     *           element of its default, and by ShapeRule when its default says nothing for a key.
     *
     * @param  mixed                    $raw Raw input value; null when the key is missing
     * @param  DateTimeImmutable        $now Time of the validation, read once from the Validator's clock
     * @return FieldOutcome
     * @throws UnexpectedValueException When a sanitizer closure returns the wrong type
     * @throws RuntimeException         When PCRE aborts while a Sanitize case is running, a file's stream cannot be read, or ICU cannot split a string into grapheme clusters or count them
     * @throws InvalidArgumentException When a date limit or default cannot be read back as a date in the time zone of the validation
     */
    public function evaluate(mixed $raw, DateTimeImmutable $now): FieldOutcome
    {
        $value = $raw;
        if (\is_string($value) && mb_check_encoding($value, 'UTF-8')) {
            $value = $this->sanitize($value);
        }

        if ($this->isEmpty($value)) {
            return $this->emptyOutcome($now);
        }

        $typed = $this->coerce($value);
        if ($typed instanceof TypeMismatch) {
            $rule   = $typed->rule ?? $this->typeRule();
            $params = $typed->rule === null ? $this->typeParams() : $typed->params;

            return new FieldOutcome(null, null, [new Failure($rule, $params)]);
        }
        $typed = $this->inFrame($typed, $now);

        // A value of the wrong size stops here, the way one of the wrong type does.
        $failures = $this->run($this->sizeChecks, $typed, $now);
        if ($failures !== []) {
            return new FieldOutcome(null, null, $failures);
        }

        $sized = $typed;

        [$failures, $typed, $comparable, $elements] = $this->validateChildren($typed, $now);
        $checked      = $this->run($this->checks, $typed, $now);
        $lengthFailed = self::failedLength($checked);

        return new FieldOutcome(
            $this->output($typed),
            $comparable,
            [
                ...$failures,
                ...$checked,
                ...($failures === [] ? $this->run($this->elementChecks, $elements, $now) : []),
            ],
            // The Validator knows which when() applies only once every field
            // has its value, so it runs them through this, with the typed
            // value kept here; a when() that does not apply never runs.
            runWhen: fn (int $index): array => $this->runWhen($index, $sized, $typed, $elements, $failures === [], $now),
            compare: fn (int $index, FieldOutcome $other): bool => $this->holds($index, $sized, $comparable, $other, $lengthFailed),
        );
    }

    /**
     * What an empty value gives: the required failure, null, or the default.
     *
     * @param  DateTimeImmutable        $now Time of the validation
     * @return FieldOutcome
     * @throws InvalidArgumentException When a date default cannot be read back as a date in the time zone of the validation
     */
    private function emptyOutcome(DateTimeImmutable $now): FieldOutcome
    {
        if ($this->required) {
            return new FieldOutcome(null, null, [new Failure('required', [], $this->requiredMessage)]);
        }

        if ($this->default === null) {
            return new FieldOutcome(null, null, [], empty: true);
        }

        // Built once per time zone: a container's default can be large, and
        // one input can leave a list element empty many times over. A rule
        // validated through one clock keeps a single entry.
        return $this->defaultOutcomes[$now->getTimezone()->getName()] ??= new FieldOutcome(
            $this->outputOfDefault($this->default, $now),
            $this->comparableOfDefault($this->default, $now),
            [],
            empty: true,
        );
    }

    /**
     * Run the rules one when() adds.
     *
     * @param  int               $index          Position of the when() among those declared
     * @param  T                 $sized          Value the rules on the size test, before what it holds was read
     * @param  T                 $typed          Value the other rules test
     * @param  T                 $elements       Value the rules reading the elements test
     * @param  bool              $elementsPassed Whether every element passed, without which those rules do not run
     * @param  DateTimeImmutable $now            Time of the validation
     * @return list<Failure>
     * @throws RuntimeException  When a file's stream cannot be read, or ICU cannot split a string into grapheme clusters or count them
     */
    private function runWhen(int $index, mixed $sized, mixed $typed, mixed $elements, bool $elementsPassed, DateTimeImmutable $now): array
    {
        [$sizeChecks, $checks, $elementChecks] = $this->whenChecks[$index];
        $sizeFailures = $this->run($sizeChecks, $sized, $now);

        return $sizeFailures !== [] ? $sizeFailures : [
            ...$this->run($checks, $typed, $now),
            ...($elementsPassed ? $this->run($elementChecks, $elements, $now) : []),
        ];
    }

    /**
     * Run a set of rules over a value, keeping every failure.
     *
     * @template V
     * @param  list<Check<V>>    $checks Rules to run
     * @param  V                 $typed  Value the rules test
     * @param  DateTimeImmutable $now    Time of the validation
     * @return list<Failure>
     * @throws RuntimeException  When a file's stream cannot be read, or ICU cannot split a string into grapheme clusters or count them
     */
    private function run(array $checks, mixed $typed, DateTimeImmutable $now): array
    {
        $failures = [];
        foreach ($checks as $check) {
            if (!($check->passes)($typed, $now)) {
                $params     = $check->paramsAt === null ? $check->params : ($check->paramsAt)($now);
                $failures[] = new Failure($check->rule, $params, $check->message);
            }
        }

        return $failures;
    }

    /**
     * The label set with label(), if any.
     *
     * @internal Read by Validator and ShapeRule when building messages.
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
     * @internal Read by Validator and by the array rules when building messages.
     *
     * @return string|null
     */
    public function fieldMessage(): ?string
    {
        return $this->fieldMessage;
    }

    /**
     * Whether same() / different() can say anything about this field.
     *
     * A type whose validated value is an object that no two inputs can share
     * answers false, so that a comparison naming it, or declared on it, is
     * refused when it is declared rather than reporting the same verdict for
     * every input.
     *
     * @internal Read by Validator for both sides of a declared comparison.
     *
     * @return bool
     */
    public function comparesByValue(): bool
    {
        return true;
    }

    /**
     * Prepare a value a container declares as the default of this field.
     *
     * The value goes through what default() does with it and is refused
     * where default() would refuse it, with the types checked strictly
     * whatever the caller's strict_types. A type without default() refuses
     * every value.
     *
     * @param  mixed                    $value Value the container's default holds for this field, not empty
     * @return mixed
     * @throws InvalidArgumentException When default() would refuse the value
     */
    protected function prepareDefault(mixed $value): mixed
    {
        throw new InvalidArgumentException($this->typeRule() . '() has no default.');
    }

    /**
     * The exception for a default of the wrong type.
     *
     * @param  string                   $expected What default() takes, with its article
     * @param  mixed                    $value    Value given
     * @return InvalidArgumentException
     */
    protected static function refusedDefault(string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException('default() needs ' . $expected . ', got ' . get_debug_type($value) . '.');
    }

    /**
     * Turn the default this field holds into the value handed to the caller.
     *
     * @param  mixed                    $default Default as declared or prepared
     * @param  DateTimeImmutable        $now     Time of the validation
     * @return mixed
     * @throws InvalidArgumentException When a date default cannot be read back as a date in the time zone of the validation
     */
    protected function outputOfDefault(mixed $default, DateTimeImmutable $now): mixed
    {
        return $this->output($default);
    }

    /**
     * Turn the default this field holds into the value same() / different() compare with `===`.
     *
     * @param  mixed                    $default Default as declared or prepared
     * @param  DateTimeImmutable        $now     Time of the validation
     * @return mixed
     * @throws InvalidArgumentException When a date default cannot be read back as a date in the time zone of the validation
     */
    protected function comparableOfDefault(mixed $default, DateTimeImmutable $now): mixed
    {
        return $this->comparable($default);
    }

    /**
     * Declared rules comparing this field with another field.
     *
     * @internal Read by Validator, which evaluates them once every field has its value, and by ListRule, which refuses them.
     *
     * @return list<Comparison>
     */
    public function comparisons(): array
    {
        return $this->comparisons;
    }

    /**
     * The declared requiredWith() / requiredIf() rules.
     *
     * @internal Read by Validator, which evaluates them once every field has its value, and by ListRule, which refuses them.
     *
     * @return list<RequiredCondition>
     */
    public function requiredConditions(): array
    {
        return $this->requiredConditions;
    }

    /**
     * The declared when() conditions, in the order their failures appear in an outcome.
     *
     * @internal Read by Validator, which decides which of them apply, and by ListRule, which refuses them.
     *
     * @return list<WhenCondition>
     */
    public function whens(): array
    {
        return $this->whens;
    }

    /**
     * Whether default() was declared.
     *
     * @internal Read by Validator, which refuses two fields with a default required on each other.
     *
     * @return bool
     */
    public function declaresDefault(): bool
    {
        return $this->hasDefault;
    }

    /**
     * Whether required() was declared, so that an empty value fails.
     *
     * Read by ListRule and ShapeRule, which refuse a default leaving a
     * required element or key empty.
     *
     * @return bool
     */
    protected function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Whether a validated value of this field can be the given value.
     *
     * Only the types whose validated value is compared as it is answer yes;
     * the others are never named by requiredIf() / when().
     *
     * @internal Read by Validator to check the values of requiredIf() / when() against the field they name.
     *
     * @param  mixed $value Value given to requiredIf() / when()
     * @return bool
     */
    public function canHold(mixed $value): bool
    {
        return false;
    }

    /**
     * Whether the value counts as nothing having been submitted.
     *
     * A missing key reaches evaluate() as null, and a form submits a field the
     * visitor left alone as ''. A subclass widens this where its own transport
     * has another way of saying the same thing.
     *
     * @param  mixed $value Raw input after sanitizing, or a declared default as it is
     * @return bool
     */
    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Validate what the value holds, for a type that holds other values.
     *
     * Runs after the type check and after the rules on the size of the value,
     * and before every other rule declared on this field. A type that holds
     * nothing answers with no failures and the value unchanged.
     *
     * The third value is what same() / different() compare, built from what
     * each element compares by where the type holds elements, so that a date
     * nested in a list is compared by the instant it names. The fourth is what
     * the rules reading the elements receive.
     *
     * @param  T                                 $typed Value of the declared type
     * @param  DateTimeImmutable                 $now   Time of the validation, handed on to the elements
     * @return array{list<Failure>, T, mixed, T} Failures of the elements, the value to carry on with, the value same() / different() compare, and the value the rules reading the elements receive
     */
    protected function validateChildren(mixed $typed, DateTimeImmutable $now): array
    {
        return [[], $typed, $this->comparable($typed), $typed];
    }

    /**
     * Convert a non-empty raw value to the declared type.
     *
     * @param  mixed          $value Raw value that isEmpty() does not count as empty
     * @return T|TypeMismatch
     */
    abstract protected function coerce(mixed $value): mixed;

    /**
     * Bring a value of the declared type into the frame of the validation.
     *
     * Runs right after coerce(). A type whose value does not depend on where
     * the clock reads hands it back unchanged.
     *
     * @param  T                 $typed Value coerce() produced
     * @param  DateTimeImmutable $now   Time of the validation
     * @return T
     */
    protected function inFrame(mixed $typed, DateTimeImmutable $now): mixed
    {
        return $typed;
    }

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
     * @param  string                                                                                    $rule     Rule name, also the language file key
     * @param  array<string, int|float|string|list<int|float|string>>                                    $params   Parameters keyed by placeholder name
     * @param  Closure(T, DateTimeImmutable): bool                                                       $passes   Returns true when the value satisfies the rule, given the time of the validation
     * @param  string|null                                                                               $message  Message for this rule only
     * @param  (Closure(DateTimeImmutable): array<string, int|float|string|list<int|float|string>>)|null $paramsAt Builds the parameters from the time of the validation, in place of $params
     * @return static
     * @throws \InvalidArgumentException                                                                 When the message template is malformed
     */
    protected function withCheck(string $rule, array $params, Closure $passes, ?string $message, ?Closure $paramsAt = null): static
    {
        if ($message !== null) {
            ValidationMessages::assertValidPattern($message);
        }

        $copy           = clone $this;
        $copy->checks[] = new Check($rule, $params, $passes, $message, $paramsAt);

        return $copy;
    }

    /**
     * Append a rule that reads the validated elements, which runs only when every element passed.
     *
     * An element that failed has no validated value to read, so judging the
     * array on it would report a failure no input caused.
     *
     * @param  string                              $rule    Rule name, also the language file key
     * @param  Closure(T, DateTimeImmutable): bool $passes  Returns true when the elements, as validateChildren() hands them to these rules, satisfy the rule, given the time of the validation
     * @param  string|null                         $message Message for this rule only
     * @return static
     * @throws \InvalidArgumentException           When the message template is malformed
     */
    protected function withElementsCheck(string $rule, Closure $passes, ?string $message): static
    {
        if ($message !== null) {
            ValidationMessages::assertValidPattern($message);
        }

        $copy                  = clone $this;
        $copy->elementChecks[] = new Check($rule, [], $passes, $message);

        return $copy;
    }

    /**
     * Append a rule on the size of the value, which runs before what it holds is read.
     *
     * A failure here ends the field: the value is not handed to the caller and
     * the comparisons with another field do not run. Use it only where reading
     * what the value holds is pointless once the rule has failed; anything else
     * belongs in withCheck().
     *
     * @param  string                                                 $rule    Rule name, also the language file key
     * @param  array<string, int|float|string|list<int|float|string>> $params  Parameters keyed by placeholder name
     * @param  Closure(T, DateTimeImmutable): bool                    $passes  Returns true when the value satisfies the rule, given the time of the validation
     * @param  string|null                                            $message Message for this rule only
     * @return static
     * @throws \InvalidArgumentException                              When the message template is malformed
     */
    protected function withSizeCheck(string $rule, array $params, Closure $passes, ?string $message): static
    {
        if ($message !== null) {
            ValidationMessages::assertValidPattern($message);
        }

        $copy               = clone $this;
        $copy->sizeChecks[] = new Check($rule, $params, $passes, $message);

        return $copy;
    }

    /**
     * Append a requiredWith() / requiredIf() rule.
     *
     * @param  RequiredCondition        $condition Rule to append
     * @return static
     * @throws LogicException           When the field is required
     * @throws InvalidArgumentException When the message template is malformed
     */
    private function withRequiredCondition(RequiredCondition $condition): static
    {
        if ($this->required) {
            throw new LogicException('A field cannot be both required and required on a condition.');
        }
        if ($condition->message !== null) {
            ValidationMessages::assertValidPattern($condition->message);
        }

        $copy = clone $this;
        $copy->requiredConditions[] = $condition;

        return $copy;
    }

    /**
     * Read the value, or list of values, given to requiredIf() / when().
     *
     * @param  mixed                                            $value  Value as the caller gave it
     * @param  string                                           $method Method name, for the message
     * @return non-empty-list<string|int|float|bool|BackedEnum>
     * @throws InvalidArgumentException                         When $value is null, an empty list, or holds something no field yields
     */
    private static function conditionValues(mixed $value, string $method): array
    {
        $values = \is_array($value) ? $value : [$value];
        if ($values === []) {
            throw new InvalidArgumentException($method . '() needs at least one value.');
        }
        $checked = [];
        foreach ($values as $one) {
            if (!\is_string($one) && !\is_int($one) && !\is_float($one) && !\is_bool($one) && !$one instanceof BackedEnum) {
                throw new InvalidArgumentException(
                    $method . '() compares with a string, int, float, bool or backed enum case, got ' . get_debug_type($one) . '.',
                );
            }
            $checked[] = $one;
        }

        return $checked;
    }

    /**
     * Take a name given to requiredWith(), letting its string type refuse anything else.
     *
     * The string parameter turns a name of any other type, null included,
     * into a TypeError, which requiredWith() catches and throws again with
     * the position of the name.
     *
     * @param  string $name Name of another field
     * @return string
     */
    private static function fieldName(string $name): string
    {
        return $name;
    }

    /**
     * This rule with nothing declared on it beyond its type, for a when() closure to add to.
     *
     * @return static
     */
    private function withoutRules(): static
    {
        $copy                  = $this->withoutChecks();
        $copy->required        = false;
        $copy->requiredMessage = null;
        $copy->hasDefault      = false;
        $copy->default         = null;
        $copy->fieldMessage    = null;
        $copy->displayLabel    = null;
        $copy->comparisons     = [];
        $copy->comparisonTests = [];
        $copy->requiredConditions = [];
        $copy->whens              = [];
        $copy->whenChecks         = [];

        return $copy;
    }

    /**
     * This rule without the rules on its value.
     *
     * @return static
     */
    private function withoutChecks(): static
    {
        $copy                = clone $this;
        $copy->checks        = [];
        $copy->sizeChecks    = [];
        $copy->elementChecks = [];

        return $copy;
    }

    /**
     * This rule without the marks a type keeps about which of its rules were added.
     *
     * when() gives its closure a rule without rules or marks, so the rules the
     * closure adds bring everything they need with them, and compares what
     * the closure returns with it, leaving out the rules. A mark set while
     * adding a rule is part of adding it, not a change to the field, so it is
     * left out as well.
     *
     * @return static
     */
    protected function withoutRuleMarks(): static
    {
        return $this;
    }

    /**
     * Append a rule that compares with another field and decides with the given test.
     *
     * @param  string                    $rule    Rule name, also the language file key and the declaring method's name
     * @param  string                    $field   Name of the other field
     * @param  string|null               $message Message for this rule only
     * @param  Closure(T, mixed): bool   $test    Whether this field's typed value passes against the other field's validated value (null when that one was empty without a default)
     * @param  bool                      $bounded Whether the field must bound its length in code points or bytes (see Comparison)
     * @return static
     * @throws \InvalidArgumentException When the message template is malformed
     */
    protected function withFieldComparison(string $rule, string $field, ?string $message, Closure $test, bool $bounded = false): static
    {
        $copy = $this->withComparison(new Comparison($rule, $field, $message, static::class, $this->typeRule(), $bounded));

        $copy->comparisonTests[\count($copy->comparisons) - 1] = $test;

        return $copy;
    }

    /**
     * Whether the comparison at the given position holds against the other field.
     *
     * @param  int          $index        Position of the comparison
     * @param  T            $typed        This field's typed value
     * @param  mixed        $comparable   This field's comparable value
     * @param  FieldOutcome $other        Outcome of the other field
     * @param  bool         $lengthFailed Whether the field failed maxLength() or exactLength()
     * @return bool
     */
    private function holds(int $index, mixed $typed, mixed $comparable, FieldOutcome $other, bool $lengthFailed): bool
    {
        // A value already refused for its length is not searched: the bound is what keeps the search short.
        if ($lengthFailed && $this->comparisons[$index]->bounded) {
            return true;
        }

        $test = $this->comparisonTests[$index] ?? null;

        return $test === null
            ? $this->comparisons[$index]->passes($comparable, $other->comparable)
            : $test($typed, $other->value);
    }

    /**
     * Whether the failures include a rule on the length of the value.
     *
     * @param  list<Failure> $failures Failures of the declared rules
     * @return bool
     */
    private static function failedLength(array $failures): bool
    {
        foreach ($failures as $failure) {
            if ($failure->rule === 'maxLength' || $failure->rule === 'exactLength') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the field declares maxLength() or exactLength() counted in code points or bytes.
     *
     * A length in grapheme clusters bounds nothing: one cluster can hold any
     * number of code points (a letter followed by zero width joiners).
     *
     * @internal Read by Validator for the comparisons that need a bound on the length.
     *
     * @return bool
     */
    public function boundsLength(): bool
    {
        foreach ($this->checks as $check) {
            $unit = $check->params['unit'] ?? null;
            if (($check->rule === 'maxLength' || $check->rule === 'exactLength')
                && ($unit === LengthUnit::Codepoints->value || $unit === LengthUnit::Bytes->value)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append a rule comparing with another field.
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
