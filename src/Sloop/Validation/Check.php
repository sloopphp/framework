<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use DateTimeImmutable;

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
     * @param string                                                                                    $rule        Rule name, also the language file key
     * @param array<string, int|float|string|list<int|float|string>>                                    $params      Parameters keyed by placeholder name
     * @param Closure(T, DateTimeImmutable): bool                                                       $passes      Returns true when the value satisfies the rule, given the time of the validation
     * @param string|null                                                                               $message     Message given with `message:`, if any
     * @param (Closure(DateTimeImmutable): array<string, int|float|string|list<int|float|string>>)|null $paramsAt    Builds the parameters from the time of the validation, in place of $params; null when they do not depend on it
     * @param bool                                                                                      $afterOthers Whether the check runs only once every other rule declared alongside it has passed (a rule given to using())
     */
    public function __construct(
        public string $rule,
        public array $params,
        public Closure $passes,
        public ?string $message,
        public ?Closure $paramsAt = null,
        public bool $afterOthers = false,
    ) {
    }
}
