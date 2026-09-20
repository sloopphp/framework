<?php

declare(strict_types=1);

namespace Sloop\Validation;

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
     * @param array<string, mixed>                           $values Validated values of the fields that passed, keyed by field name
     * @param array<string, non-empty-list<ValidationError>> $errors Errors keyed by field name; fields that passed are absent
     */
    public function __construct(
        private array $values,
        private array $errors,
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
     * Errors keyed by field name, in the order the rules ran.
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
