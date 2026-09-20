<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * A failed rule before its message is resolved.
 *
 * The field rule knows which rule failed and with what parameters, but not
 * the language file; the Validator turns each failure into a ValidationError
 * once it has chosen the message.
 *
 * @internal Produced by FieldRule, consumed by Validator.
 */
final readonly class Failure
{
    /**
     * Create a failure.
     *
     * @param string                                                 $rule    Name of the rule that failed
     * @param array<string, int|float|string|list<int|float|string>> $params  Rule parameters keyed by placeholder name
     * @param string|null                                            $message Message given to that rule with `message:`, if any
     */
    public function __construct(
        public string $rule,
        public array $params = [],
        public ?string $message = null,
    ) {
    }
}
