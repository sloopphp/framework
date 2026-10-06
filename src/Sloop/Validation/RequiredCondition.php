<?php

declare(strict_types=1);

namespace Sloop\Validation;

use BackedEnum;

/**
 * A requiredWith() or requiredIf() rule, evaluated after every field has its value.
 *
 * requiredWith() holds when any of $fields is filled; requiredIf() holds when
 * its one field has one of $values.
 *
 * @internal Built by FieldRule, evaluated by Validator.
 */
final readonly class RequiredCondition
{
    /**
     * Create a condition.
     *
     * @param string                                 $rule    'requiredWith' or 'requiredIf', also the language file key
     * @param non-empty-list<string>                 $fields  Fields the condition looks at, in the order they were given
     * @param list<string|int|float|bool|BackedEnum> $values  Values that make requiredIf() hold; empty for requiredWith()
     * @param string|null                            $message Message given with `message:`, if any
     */
    public function __construct(
        public string $rule,
        public array $fields,
        public array $values,
        public ?string $message,
    ) {
    }
}
