<?php

declare(strict_types=1);

namespace Sloop\Validation;

use BackedEnum;

/**
 * A when() rule: further rules that apply while another field has one of the given values.
 *
 * @internal Built by FieldRule, evaluated by Validator.
 */
final readonly class WhenCondition
{
    /**
     * Create a condition.
     *
     * @param string                                           $field  Field the condition looks at
     * @param non-empty-list<string|int|float|bool|BackedEnum> $values Values that make the condition hold
     */
    public function __construct(
        public string $field,
        public array $values,
    ) {
    }
}
