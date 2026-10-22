<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Strict parsing of booleans that arrive as JSON values or as strings.
 *
 * @internal Shared by BoolRule and RouteRequestHandler.
 */
final class BoolParser
{
    /**
     * Read a bool from a JSON bool, the int 1 or 0, or a string.
     *
     * The strings are `"1"`, `"0"`, `"true"` and `"false"`; `"true"` and
     * `"false"` match without regard to case. Other words (`"on"`, `"yes"`)
     * and other numbers are not read.
     *
     * @param  mixed     $value Raw value
     * @return bool|null The bool, or null when the value is not one of those forms
     */
    public static function bool(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (!\is_string($value)) {
            return null;
        }

        return match (strtolower($value)) {
            '1', 'true'  => true,
            '0', 'false' => false,
            default      => null,
        };
    }
}
