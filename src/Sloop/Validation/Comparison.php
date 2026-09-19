<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * A same() or different() rule, evaluated after every field has its value.
 *
 * @internal Built by FieldRule, evaluated by Validator.
 */
final readonly class Comparison
{
    /**
     * Create a comparison.
     *
     * @param string      $rule    'same' or 'different'
     * @param string      $other   Name of the field to compare against
     * @param string|null $message Message given with `message:`, if any
     */
    public function __construct(
        public string $rule,
        public string $other,
        public ?string $message,
    ) {
    }

    /**
     * Whether the two validated values satisfy this comparison.
     *
     * @param  mixed $own   This field's comparable value
     * @param  mixed $other The other field's comparable value
     * @return bool
     */
    public function passes(mixed $own, mixed $other): bool
    {
        return ($own === $other) === ($this->rule === 'same');
    }
}
