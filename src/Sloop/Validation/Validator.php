<?php

declare(strict_types=1);

namespace Sloop\Validation;

use BackedEnum;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use Sloop\Clock\SystemClock;

/**
 * Validates input arrays against a fixed set of field rules.
 *
 *     $validator = new Validator([
 *         'name' => Rule::string(Sanitize::Trim)->required()->maxLength(50),
 *         'age'  => Rule::int()->between(0, 150),
 *     ]);
 *     $result = $validator->validate($input);
 *
 * One validator can validate any number of inputs. A failure in one field does
 * not stop the others; what runs for one field, and in what order, is described
 * on FieldRule. The rules that look at another field run after all fields, so
 * the order of the fields does not matter: first requiredWith() / requiredIf(),
 * each field after the fields it names, then same(), different() and the
 * other comparisons with another field, and when(), which see the fields as
 * the first step left them.
 *
 * The rules comparing with the current time read it from the clock given to
 * the constructor, once per validate(), so every field of one input is judged
 * against the same moment. A test passes a FrozenClock to fix that moment.
 *
 * A validator does not change once built: its properties are private and set
 * only while it is built. They are not readonly because forKeys() builds one
 * without the constructor, and PHPStan reports a readonly property set outside
 * the constructor, which this project does not suppress.
 */
final class Validator
{
    /**
     * Field rules keyed by field name.
     *
     * @var array<string, FieldRule<covariant mixed>>
     */
    private array $rules;

    /**
     * Fields with a requiredWith() / requiredIf(), in the order they are judged.
     *
     * @var list<string>
     */
    private array $requiredOrder;

    /**
     * Flags the rules declare with `flag:`, as keys; empty in a validator built by forKeys(), which builds no result.
     *
     * @var array<string, true>
     */
    private array $flags = [];

    /**
     * Clock read once at the start of each validation.
     *
     * @var ClockInterface
     */
    private ClockInterface $clock;

    /**
     * Create a validator.
     *
     * @param  array<array-key, FieldRule<covariant mixed>> $rules Field rules keyed by field name (PHP turns a numeric key into an int, which is refused here)
     * @param  ClockInterface|null                          $clock Clock the rules comparing with the current time read; null for the system clock
     * @throws InvalidArgumentException                     When a field name is a number; a rule names a field that has no rule; a same() / different() side cannot be compared;
     *                                                      a comparison names its own field, or an order or containment comparison names a field of another type;
     *                                                      notContainsField() is declared without maxLength() / exactLength() in code points or bytes;
     *                                                      a requiredWith() / requiredIf() names its own field; a requiredIf() / when() value cannot be
     *                                                      the named field's value; fields required on each other include two with a default;
     *                                                      or a rule given to using() has no message, from `message:` or the message() of its field or of a field holding it
     */
    public function __construct(array $rules, ?ClockInterface $clock = null)
    {
        $this->setUp($rules, $clock);

        foreach ($this->rules as $rule) {
            foreach ($rule->declaredFlags() as $flag) {
                $this->flags[$flag] = true;
            }
        }
        foreach ($this->rules as $field => $rule) {
            $name = $rule->usingWithoutMessage();
            if ($name !== null) {
                throw new InvalidArgumentException(
                    'Field "' . $field . '" has a using("' . $name . '") rule without a message; '
                    . 'give it with message: or with message() on the field.',
                );
            }
        }
    }

    /**
     * A validator for the keys of a shape, which leaves the messages of using() to the validator holding the shape.
     *
     * The shape's own message(), declared after its keys, can still give them
     * one, so only the outermost validator can tell whether one is missing.
     *
     * @internal Called by ShapeRule.
     *
     * @param  array<string, FieldRule<covariant mixed>> $rules Rules of each key
     * @return self
     * @throws InvalidArgumentException                  When the keys' rules are refused the way the constructor refuses them, apart from the messages of using()
     */
    public static function forKeys(array $rules): self
    {
        $validator = new ReflectionClass(self::class)->newInstanceWithoutConstructor();
        $validator->setUp($rules, null);

        return $validator;
    }

    /**
     * Check the rules and keep them.
     *
     * @param  array<array-key, FieldRule<covariant mixed>> $rules Field rules keyed by field name
     * @param  ClockInterface|null                          $clock Clock, or null for the system clock
     * @return void
     * @throws InvalidArgumentException                     When the rules are refused the way the constructor describes, apart from the messages of using()
     */
    private function setUp(array $rules, ?ClockInterface $clock): void
    {
        $named = [];
        foreach ($rules as $field => $rule) {
            if (!\is_string($field)) {
                throw new InvalidArgumentException(
                    'Validator needs named fields, and ' . $field . ' is a number: PHP turns a numeric key into an int, '
                    . 'which cannot name a field.',
                );
            }
            $named[$field] = $rule;
        }
        foreach ($named as $field => $rule) {
            self::assertComparisons($named, $field, $rule);
            self::assertConditions($named, $field, $rule);
        }

        $this->rules         = $named;
        $this->requiredOrder = self::requiredOrder($named);
        $this->clock         = $clock ?? new SystemClock();
    }

    /**
     * A copy of this validator with one more field, reading the same clock; this validator is unchanged.
     *
     * @param  string                     $field Field name
     * @param  FieldRule<covariant mixed> $rule  Rules of the field
     * @return self
     * @throws InvalidArgumentException   When the field already has rules, or the new set of rules is refused the way the constructor refuses it
     */
    #[\NoDiscard('Validator::with() returns a new Validator. The original is not modified.')]
    public function with(string $field, FieldRule $rule): self
    {
        if (\array_key_exists($field, $this->rules)) {
            throw new InvalidArgumentException('Field "' . $field . '" already has rules.');
        }

        return new self([...$this->rules, $field => $rule], $this->clock);
    }

    /**
     * Validate one input array.
     *
     * Keys of $data without a rule are ignored and do not appear in the values.
     *
     * @param  array<array-key, mixed>   $data Input, e.g. a decoded JSON body
     * @return ValidationResult
     * @throws \RuntimeException         When a message pattern cannot be formatted, PCRE aborts while a sanitizer is running, a file's stream cannot be read, or ICU cannot split a string into grapheme clusters or count them
     * @throws \UnexpectedValueException When a sanitizer closure or a closure given to using() returns the wrong type
     * @throws InvalidArgumentException  When Rule::date()'s format cannot read back, as a date in the clock's time zone, a limit or default (declared where the PHP default zone was another) or today's date (afterNow() and the like)
     */
    public function validate(array $data): ValidationResult
    {
        // A copy, so that each validation has a time object of its own even
        // when the clock hands back the same one (FrozenClock does): the rules
        // tell one validation from the next by it.
        [$outcomes, $failures] = $this->evaluateFields($data, clone $this->clock->now());

        $values = [];
        $errors = [];
        $raised = [];
        foreach ($failures as $field => $fieldFailures) {
            if ($fieldFailures === []) {
                $values[$field] = $outcomes[$field]->value;
                continue;
            }
            foreach ($fieldFailures as $failure) {
                $errors[$this->key($field, $failure)][] = $this->error($field, $failure);
                if ($failure->flag !== null) {
                    $raised[$failure->flag] = true;
                }
            }
        }

        return new ValidationResult($values, $errors, $raised, $this->flags);
    }

    /**
     * Resolve the message of a failure.
     *
     * The message comes from the rule's `message:`, then the field's
     * message(), then the language file. `{label}` is the field's label, and
     * `{other}` of a comparison with another field is that field's label.
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
     * which is the one that knows the language file.
     *
     * @internal Called by ShapeRule.
     *
     * @param  array<array-key, mixed>                                          $data Input
     * @param  DateTimeImmutable                                                $now  Time of the validation
     * @return array{array<string, FieldOutcome>, array<string, list<Failure>>} Outcome and failures of each field
     * @throws \UnexpectedValueException                                        When a sanitizer closure or a closure given to using() returns the wrong type
     * @throws \RuntimeException                                                When PCRE aborts while a sanitizer is running, a file's stream cannot be read, or ICU cannot split a string into grapheme clusters or count them
     * @throws InvalidArgumentException                                         When Rule::date()'s format cannot read back a limit, a default or today's date in the time zone of the validation
     */
    public function evaluateFields(array $data, DateTimeImmutable $now): array
    {
        $outcomes = [];
        $failures = [];
        foreach ($this->rules as $field => $rule) {
            $outcomes[$field] = $rule->evaluate($data[$field] ?? null, $now);
            $failures[$field] = $outcomes[$field]->failures;
        }

        $failures = $this->judgeRequired($outcomes, $failures);

        return [$outcomes, $this->judgeAgainstOthers($outcomes, $failures)];
    }

    /**
     * Add the failures of requiredWith() / requiredIf(), each field after the fields it names.
     *
     * A field judged later sees a named field that its own condition made fail
     * as failed.
     *
     * @param  array<string, FieldOutcome>  $outcomes Outcome of each field
     * @param  array<string, list<Failure>> $failures Failures of each field so far
     * @return array<string, list<Failure>>
     */
    private function judgeRequired(array $outcomes, array $failures): array
    {
        foreach ($this->requiredOrder as $field) {
            if (!$outcomes[$field]->empty) {
                continue;
            }
            $failure = $this->requiredFailure($field, $outcomes, $failures);
            if ($failure !== null) {
                $failures[$field] = [$failure];
            }
        }

        return $failures;
    }

    /**
     * Add the failures of the comparisons with another field and of the rules of each when() that applies.
     *
     * Every field sees the others as judgeRequired() left them, so a failure
     * added here by one field does not change what another field sees.
     *
     * @param  array<string, FieldOutcome>  $outcomes Outcome of each field
     * @param  array<string, list<Failure>> $settled  Failures of each field after judgeRequired()
     * @return array<string, list<Failure>>
     * @throws \RuntimeException            When a rule added by when() cannot read a file's stream, or ICU cannot split a string into grapheme clusters or count them
     * @throws \UnexpectedValueException    When a closure given to using() in a when() returns something other than a bool
     */
    private function judgeAgainstOthers(array $outcomes, array $settled): array
    {
        $failures = $settled;
        foreach ($this->rules as $field => $rule) {
            $compare = $outcomes[$field]->compare;
            if ($compare === null) {
                continue;
            }
            foreach ($rule->comparisons() as $index => $comparison) {
                $other = $comparison->other;
                if ($settled[$other] === [] && !$compare($index, $outcomes[$other])) {
                    $failures[$field][] = new Failure(
                        $comparison->rule,
                        ['other' => $other],
                        $comparison->message,
                        otherLabel: $this->label($other),
                        flag: $comparison->flag,
                    );
                }
            }
            $runWhen = $outcomes[$field]->runWhen;
            foreach ($rule->whens() as $index => $when) {
                if ($runWhen !== null && self::matchedValue($when->field, $when->values, $outcomes, $settled) !== null) {
                    $failures[$field] = [...$failures[$field], ...$runWhen($index)];
                }
            }
        }

        return $failures;
    }

    /**
     * The failure of the first requiredWith() / requiredIf() of an empty field that holds, if any.
     *
     * @param  string                       $field    Field name
     * @param  array<string, FieldOutcome>  $outcomes Outcome of each field
     * @param  array<string, list<Failure>> $failures Failures of each field so far
     * @return Failure|null
     */
    private function requiredFailure(string $field, array $outcomes, array $failures): ?Failure
    {
        foreach ($this->rules[$field]->requiredConditions() as $condition) {
            $failure = $this->conditionFailure($condition, $outcomes, $failures);
            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * The failure of one requiredWith() / requiredIf() when it holds, naming the first field that makes it hold.
     *
     * @param  RequiredCondition            $condition Condition to check
     * @param  array<string, FieldOutcome>  $outcomes  Outcome of each field
     * @param  array<string, list<Failure>> $failures  Failures of each field so far
     * @return Failure|null
     */
    private function conditionFailure(RequiredCondition $condition, array $outcomes, array $failures): ?Failure
    {
        foreach ($condition->fields as $other) {
            if ($condition->rule === 'requiredWith') {
                if (self::isFilled($other, $outcomes, $failures)) {
                    return new Failure('requiredWith', ['other' => $other], $condition->message, otherLabel: $this->label($other), flag: $condition->flag);
                }
                continue;
            }
            $matched = self::matchedValue($other, $condition->values, $outcomes, $failures);
            if ($matched !== null) {
                return new Failure(
                    'requiredIf',
                    ['other' => $other, 'value' => self::describe($matched)],
                    $condition->message,
                    otherLabel: $this->label($other),
                    flag: $condition->flag,
                );
            }
        }

        return null;
    }

    /**
     * Whether another field is filled: not empty or with a default, and not failed.
     *
     * @param  string                       $field    Field name
     * @param  array<string, FieldOutcome>  $outcomes Outcome of each field
     * @param  array<string, list<Failure>> $failures Failures of each field
     * @return bool
     */
    private static function isFilled(string $field, array $outcomes, array $failures): bool
    {
        return $failures[$field] === [] && $outcomes[$field]->value !== null;
    }

    /**
     * The given value that another field holds, when it is filled and holds one of them.
     *
     * @param  string                                 $field    Field name
     * @param  list<string|int|float|bool|BackedEnum> $values   Values to look for
     * @param  array<string, FieldOutcome>            $outcomes Outcome of each field
     * @param  array<string, list<Failure>>           $failures Failures of each field
     * @return string|int|float|bool|BackedEnum|null
     */
    private static function matchedValue(string $field, array $values, array $outcomes, array $failures): string|int|float|bool|BackedEnum|null
    {
        if (!self::isFilled($field, $outcomes, $failures)) {
            return null;
        }
        foreach ($values as $candidate) {
            if ($candidate === $outcomes[$field]->value) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Render the value that made requiredIf() hold, for the error parameters.
     *
     * @param  string|int|float|bool|BackedEnum $value Value given to requiredIf() that the named field holds
     * @return int|float|string
     */
    private static function describe(string|int|float|bool|BackedEnum $value): int|float|string
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            \is_bool($value)             => $value ? 'true' : 'false',
            default                      => $value,
        };
    }

    /**
     * Refuse a comparison that names its own field, a field without rules, a side that cannot be compared, or a field of another type.
     *
     * @param  array<string, FieldRule<covariant mixed>> $rules Field rules keyed by field name
     * @param  string                                    $field Field the rules are declared on
     * @param  FieldRule<covariant mixed>                $rule  Rules of that field
     * @return void
     * @throws InvalidArgumentException                  When a comparison names its own field or a field that has no rule, either side's value cannot be compared,
     *                                                   an order or containment comparison names a field of another type, or notContainsField()
     *                                                   is declared without a length bound in code points or bytes
     */
    private static function assertComparisons(array $rules, string $field, FieldRule $rule): void
    {
        foreach ($rule->comparisons() as $comparison) {
            if ($comparison->other === $field) {
                throw new InvalidArgumentException(
                    'Field "' . $field . '" compares with itself in ' . $comparison->rule . '(), which gives the same result for every input.',
                );
            }
            if ($comparison->bounded && !$rule->boundsLength()) {
                throw new InvalidArgumentException(
                    'Field "' . $field . '" uses ' . $comparison->rule . '(), which needs maxLength() or exactLength() on the same field counted in '
                    . 'LengthUnit::Codepoints or LengthUnit::Bytes: the search takes time in the product of both lengths, and a length counted in grapheme clusters does not bound it.',
                );
            }
            if (!$rule->comparesByValue()) {
                throw new InvalidArgumentException(
                    'Field "' . $field . '" compares with "' . $comparison->other . '", but no two inputs can share its own value.',
                );
            }
            if (!\array_key_exists($comparison->other, $rules)) {
                throw new InvalidArgumentException(
                    'Field "' . $field . '" compares with "' . $comparison->other . '", which has no rule.',
                );
            }
            if ($comparison->otherRule !== null && !$rules[$comparison->other] instanceof $comparison->otherRule) {
                throw new InvalidArgumentException(
                    'Field "' . $field . '" compares with "' . $comparison->other . '" in ' . $comparison->rule . '(), which needs a ' . $comparison->otherType . '() field.',
                );
            }
            if (!$rules[$comparison->other]->comparesByValue()) {
                throw new InvalidArgumentException(
                    'Field "' . $field . '" compares with "' . $comparison->other . '", whose value no two inputs can share.',
                );
            }
        }
    }

    /**
     * Refuse a requiredWith() / requiredIf() / when() that names a field it cannot depend on.
     *
     * @param  array<string, FieldRule<covariant mixed>> $rules Field rules keyed by field name
     * @param  string                                    $field Field the rules are declared on
     * @param  FieldRule<covariant mixed>                $rule  Rules of that field
     * @return void
     * @throws InvalidArgumentException                  When a rule names a field that has no rule, a requiredWith() / requiredIf() names its own field, or a value cannot be the named field's value
     */
    private static function assertConditions(array $rules, string $field, FieldRule $rule): void
    {
        foreach ($rule->requiredConditions() as $condition) {
            foreach ($condition->fields as $other) {
                if ($other === $field) {
                    throw new InvalidArgumentException(
                        'Field "' . $field . '" is required depending on itself, which never changes whether it is required.',
                    );
                }
                self::assertNamesField($rules, $field, $other, $condition->rule);
                if ($condition->rule === 'requiredIf') {
                    self::assertCanHold($rules[$other], $field, $other, $condition->values, 'requiredIf');
                }
            }
        }
        foreach ($rule->whens() as $when) {
            self::assertNamesField($rules, $field, $when->field, 'when');
            self::assertCanHold($rules[$when->field], $field, $when->field, $when->values, 'when');
        }
    }

    /**
     * Refuse a rule that names a field without rules.
     *
     * @param  array<string, FieldRule<covariant mixed>> $rules  Field rules keyed by field name
     * @param  string                                    $field  Field the rule is declared on
     * @param  string                                    $other  Field the rule names
     * @param  string                                    $method Method that declared the rule
     * @return void
     * @throws InvalidArgumentException                  When $other has no rule
     */
    private static function assertNamesField(array $rules, string $field, string $other, string $method): void
    {
        if (!\array_key_exists($other, $rules)) {
            throw new InvalidArgumentException(
                'Field "' . $field . '" names "' . $other . '" in ' . $method . '(), which has no rule.',
            );
        }
    }

    /**
     * Refuse a requiredIf() / when() value that the named field never holds.
     *
     * @param  FieldRule<covariant mixed>             $rule   Rule of the named field
     * @param  string                                 $field  Field the rule is declared on
     * @param  string                                 $other  Field the rule names
     * @param  list<string|int|float|bool|BackedEnum> $values Values the rule compares with
     * @param  string                                 $method Method that declared the rule
     * @return void
     * @throws InvalidArgumentException               When a value cannot be a validated value of the named field
     */
    private static function assertCanHold(FieldRule $rule, string $field, string $other, array $values, string $method): void
    {
        foreach ($values as $value) {
            if (!$rule->canHold($value)) {
                throw new InvalidArgumentException(
                    'Field "' . $field . '" compares "' . $other . '" with a ' . get_debug_type($value) . ' in ' . $method
                    . '(), which that field never holds. Compare a string, int, float or bool field with a value of its type, or an enum field with its case.',
                );
            }
        }
    }

    /**
     * Order the fields with a requiredWith() / requiredIf() so that each comes after the fields it names.
     *
     * A field that names another, directly or through others, reaches more
     * fields (counting itself) than the field it names, unless the two reach
     * each other; so sorting by that count puts every field after the fields
     * it names. Fields that reach each other name each other in a cycle. In a
     * cycle the field with a default is judged first: the other fields of the
     * cycle have no default, so an empty one reads as not filled whether it
     * fails or not, and a filled one is never judged, so that field's outcome
     * is settled before the others look at it. Two fields with a default would
     * each take the other's default for a filled field, and which one fails
     * would depend on the order, so such a cycle is refused.
     *
     * @param  array<string, FieldRule<covariant mixed>> $rules Field rules keyed by field name
     * @return list<string>
     * @throws InvalidArgumentException                  When fields required on each other include two with a default
     */
    private static function requiredOrder(array $rules): array
    {
        $named   = self::namedFields($rules);
        $reached = [];
        foreach (array_keys($named) as $field) {
            $reached[$field] = self::reached($field, $named);
        }

        foreach (array_keys($named) as $field) {
            $cycle = [];
            foreach (array_keys($named) as $other) {
                if (\in_array($other, $reached[$field], true) && \in_array($field, $reached[$other], true)) {
                    $cycle[] = $other;
                }
            }
            self::assertOneDefault($rules, $cycle);
        }

        $order = array_keys($named);
        usort(
            $order,
            static fn (string $a, string $b): int => [\count($reached[$a]), !$rules[$a]->declaresDefault()]
                <=> [\count($reached[$b]), !$rules[$b]->declaresDefault()],
        );

        return $order;
    }

    /**
     * The field itself and every field it names, directly or through others.
     *
     * @param  string                      $field Field to start from
     * @param  array<string, list<string>> $named Fields each field names
     * @return list<string>
     */
    private static function reached(string $field, array $named): array
    {
        $reached = [$field];
        $queue   = $named[$field];
        while ($queue !== []) {
            $next = array_shift($queue);
            if (\in_array($next, $reached, true)) {
                continue;
            }
            $reached[] = $next;
            $queue     = [...$queue, ...$named[$next] ?? []];
        }

        return $reached;
    }

    /**
     * The fields each field names in a requiredWith() / requiredIf().
     *
     * @param  array<string, FieldRule<covariant mixed>> $rules Field rules keyed by field name
     * @return array<string, list<string>>
     */
    private static function namedFields(array $rules): array
    {
        $named = [];
        foreach ($rules as $field => $rule) {
            foreach ($rule->requiredConditions() as $condition) {
                $named[$field] = [...$named[$field] ?? [], ...$condition->fields];
            }
        }

        return $named;
    }

    /**
     * Refuse a set of fields required on each other in which more than one has a default.
     *
     * @param  array<string, FieldRule<covariant mixed>> $rules Field rules keyed by field name
     * @param  list<string>                              $group Fields that name each other
     * @return void
     * @throws InvalidArgumentException                  When two or more of them have a default
     */
    private static function assertOneDefault(array $rules, array $group): void
    {
        $withDefault = [];
        foreach ($group as $field) {
            if ($rules[$field]->declaresDefault()) {
                $withDefault[] = $field;
            }
        }
        if (\count($withDefault) > 1) {
            throw new InvalidArgumentException(
                'Fields "' . implode('", "', $group) . '" are required depending on each other, and "' . implode('", "', $withDefault)
                . '" have a default, so which one is required would depend on the order they are checked.',
            );
        }
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
     * Label of a failure: the name of the value that failed, not the way down to it.
     *
     * For a field it is its label() if set and its name otherwise. For a value
     * inside an array it is the innermost step alone — the element's label() if
     * set and its key otherwise — so that a message reads about `price` rather
     * than about `items.0.price`. The way down is in the key of the error.
     *
     * @param  string       $field   Field name
     * @param  Failure|null $failure Failed rule, when the label names the element it happened on
     * @return string
     */
    private function label(string $field, ?Failure $failure = null): string
    {
        if ($failure === null || $failure->labelPath === []) {
            return $this->rules[$field]->displayLabel() ?? $field;
        }

        return $failure->labelPath[\count($failure->labelPath) - 1];
    }
}
