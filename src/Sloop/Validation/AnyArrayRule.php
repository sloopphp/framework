<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Rules for a field that must be an array, whatever it holds.
 *
 * The elements are handed to the caller as they came in. Use Rule::list() to
 * give every element a type, or Rule::shape() to give each key its own.
 */
final class AnyArrayRule extends ArrayRule
{
    use CountsElements;
}
