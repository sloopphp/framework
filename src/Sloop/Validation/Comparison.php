<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * A rule comparing a field with another field, evaluated after every field has its value.
 *
 * same() and different() compare the two values with `===` here. The rules
 * that order two values or look for one inside the other are decided by the
 * declaring field rule, which holds the typed value; this records which type
 * the other field must have for them.
 *
 * @internal Built by FieldRule, evaluated by Validator.
 */
final readonly class Comparison
{
    /**
     * Create a comparison.
     *
     * @param string                                        $rule      Rule name, also the language file key and the declaring method's name
     * @param string                                        $other     Name of the field to compare against
     * @param string|null                                   $message   Message given with `message:`, if any
     * @param class-string<FieldRule<covariant mixed>>|null $otherRule Rule class the other field must have; null when any field compares
     * @param string|null                                   $otherType Factory name of that class, for the message refusing another type
     * @param bool                                          $bounded   Whether the field must bound its length in code points or bytes, and the comparison is
     *                                                                 skipped when the field fails a length rule; true where the cost grows with the product of both lengths
     */
    public function __construct(
        public string $rule,
        public string $other,
        public ?string $message,
        public ?string $otherRule = null,
        public ?string $otherType = null,
        public bool $bounded = false,
    ) {
    }

    /**
     * Whether the two comparable values satisfy a same() or different().
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
