<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Sanitizers applied to a string value before it is validated.
 *
 * Only the cases PHP's own functions do not cover are listed here; any other
 * transformation is passed to a factory as a closure (`mb_strtolower(...)`).
 */
enum Sanitize
{
    /**
     * Remove leading and trailing whitespace, including the ideographic space (U+3000) that trim() keeps.
     */
    case Trim;

    /**
     * Remove HTML and PHP tags (strip_tags()).
     */
    case StripTags;

    /**
     * Remove control characters (C0, DEL, C1), keeping tab, line feed, and carriage return.
     *
     * Those three are kept so that a multi-line field keeps its lines; add
     * StripNewlines and StripTabs for a single-line field.
     */
    case StripControlChars;

    /**
     * Remove line feeds and carriage returns.
     */
    case StripNewlines;

    /**
     * Remove horizontal tabs.
     */
    case StripTabs;

    /**
     * Apply this sanitizer.
     *
     * @param  string $value Valid UTF-8 input
     * @return string
     */
    public function apply(string $value): string
    {
        return match ($this) {
            self::Trim              => preg_replace('/\A[ \t\n\r\0\x0B\x{3000}]+|[ \t\n\r\0\x0B\x{3000}]+\z/u', '', $value) ?? $value,
            self::StripTags         => strip_tags($value),
            self::StripControlChars => preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u', '', $value) ?? $value,
            self::StripNewlines     => str_replace(["\r", "\n"], '', $value),
            self::StripTabs         => str_replace("\t", '', $value),
        };
    }
}
