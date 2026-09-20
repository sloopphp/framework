<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;

/**
 * One declared rule on a field: its name, parameters, and the test itself.
 *
 * @template T
 *
 * @internal Built by the rule builders.
 */
final readonly class Check
{
    /**
     * Create a check.
     *
     * @param string                                                 $rule    Rule name, also the language file key
     * @param array<string, int|float|string|list<int|float|string>> $params  Parameters keyed by placeholder name
     * @param Closure(T): bool                                       $passes  Returns true when the value satisfies the rule
     * @param string|null                                            $message Message given with `message:`, if any
     */
    public function __construct(
        public string $rule,
        public array $params,
        public Closure $passes,
        public ?string $message,
    ) {
    }
}
