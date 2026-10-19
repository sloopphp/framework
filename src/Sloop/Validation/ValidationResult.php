<?php

declare(strict_types=1);

namespace Sloop\Validation;

use InvalidArgumentException;

/**
 * Outcome of one Validator::validate() call.
 *
 * Holds the validated values and the errors together, so a caller asks one
 * object whether validation failed, what went wrong, and what the clean
 * values are.
 */
final readonly class ValidationResult
{
    /**
     * Create a result.
     *
     * @param array<string, mixed>                           $values        Validated values of the fields that passed, keyed by field name
     * @param array<string, non-empty-list<ValidationError>> $errors        Errors keyed by field name, or by the path to the element that failed; what passed is absent
     * @param list<string>                                   $raisedFlags   Flags of the rules that failed
     * @param list<string>                                   $declaredFlags Flags the rules declare; hasFlag() refuses any other name
     */
    public function __construct(
        private array $values,
        private array $errors,
        private array $raisedFlags = [],
        private array $declaredFlags = [],
    ) {
    }

    /**
     * Whether any field failed.
     *
     * @return bool
     */
    public function failed(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Whether a rule declared with this flag failed.
     *
     * A flag is given to a rule with `flag:`, as in
     * `Rule::string()->in($supported, flag: 'invalidVersion')`, and is raised
     * only when that rule fails: another rule of the same field failing, the
     * type included, does not raise it. Rules of several fields can share one
     * flag; which field failed is in errors().
     *
     * @param  string                   $flag Flag name
     * @return bool
     * @throws InvalidArgumentException When no rule of the validator declares the flag, which is taken for a misspelt name
     */
    public function hasFlag(string $flag): bool
    {
        if (!isset(array_flip($this->declaredFlags)[$flag])) {
            throw new InvalidArgumentException('No rule declares the flag "' . $flag . '".');
        }

        return isset(array_flip($this->raisedFlags)[$flag]);
    }

    /**
     * Message of the first error, or null when validation passed.
     *
     * The first error is the first one errors() lists: the fields come in the
     * order they were declared, and the errors of one field in the order its
     * rules ran.
     *
     * @return string|null
     */
    public function firstMessage(): ?string
    {
        foreach ($this->errors as $errors) {
            return $errors[0]->message;
        }

        return null;
    }

    /**
     * Errors keyed by field name, in the order the rules ran.
     *
     * A failure inside an array is keyed by the path down to the value that
     * failed, with the steps joined by dots: the second element of `items` is
     * `items.1`, and its `price` key is `items.1.price`. A field name or a key
     * holding a dot of its own is therefore ambiguous here: a field `a.b` and
     * the key `b` of a field `a` are both reported as `a.b`.
     *
     * @return array<string, non-empty-list<ValidationError>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Validated values keyed by field name.
     *
     * Only the fields declared in the rules that passed appear; a field that
     * failed and any key of the input without a rule are dropped. Each value
     * has the type its factory declares (an int for Rule::int(), a string for
     * Rule::string(), and so on), or the declared default when the field was
     * empty.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }
}
