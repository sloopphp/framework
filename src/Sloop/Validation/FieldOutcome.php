<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;

/**
 * What one field rule concluded about one input value.
 *
 * @internal Produced by FieldRule, consumed by Validator.
 */
final readonly class FieldOutcome
{
    /**
     * Create an outcome.
     *
     * @param mixed                              $value      Validated value handed to the caller (the default when the field was empty)
     * @param mixed                              $comparable Value same() / different() compare; differs from $value only where
     *                                                       the output is an object that `===` would compare by identity
     * @param bool                               $comparing  Whether same() / different() should run on this field: false when the
     *                                                       field was empty, did not have the declared type, or failed a rule on its size
     * @param list<Failure>                      $failures   Rules that failed, in the order they were declared
     * @param bool                               $empty      Whether the field was empty and passed, holding null or its default
     * @param (Closure(int): list<Failure>)|null $runWhen    Runs the rules of the when() at the given position on this value; null when the field is
     *                                                       empty, did not have the declared type, or failed a rule on its size
     */
    public function __construct(
        public mixed $value,
        public mixed $comparable,
        public bool $comparing,
        public array $failures,
        public bool $empty = false,
        public ?Closure $runWhen = null,
    ) {
    }
}
