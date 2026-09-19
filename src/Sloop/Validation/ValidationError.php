<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * One failed rule on one field.
 *
 * The rule name is the builder method that failed (the type name when the
 * value did not have the declared type), and it doubles as the key of the
 * default message in the language file. Parameter names match the
 * placeholders of that message, so a client can rebuild or translate the
 * message from the structure alone.
 */
final readonly class ValidationError
{
    /**
     * Create an error entry.
     *
     * @param string                                                 $rule    Name of the rule that failed (e.g. 'minLength', 'int')
     * @param array<string, int|float|string|list<int|float|string>> $params  Rule parameters keyed by placeholder name
     * @param string                                                 $message Human-readable message with the placeholders filled in
     */
    public function __construct(
        public string $rule,
        public array $params,
        public string $message,
    ) {
    }
}
