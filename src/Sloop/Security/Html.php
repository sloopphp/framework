<?php

declare(strict_types=1);

namespace Sloop\Security;

/**
 * Output encoding for values written into an HTML document.
 *
 * escape() covers element text and quoted attribute values; json() covers
 * values written into an inline script. Encode at output time and keep the
 * stored value raw, since the output context is unknown when input arrives.
 */
final class Html
{
    /**
     * Flags passed to json_encode() by json().
     *
     * The JSON_HEX_* flags write <, >, &, ' and " as \u escapes so the literal
     * can neither close the script element nor break out of an enclosing
     * quoted attribute; invalid UTF-8 becomes U+FFFD as in escape().
     *
     * @var int
     */
    private const int JSON_FLAGS = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT
        | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR;

    /**
     * Escape a string for HTML element text or a quoted attribute value.
     *
     * Both quote characters are encoded and existing entities are encoded
     * again, so the output always displays the input exactly. Invalid UTF-8
     * is replaced with U+FFFD. Unquoted attribute values are not protected.
     *
     * @param  string|null $value Value to escape; null yields an empty string
     * @return string
     */
    public static function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Encode a value as a JavaScript literal safe to write into an inline script.
     *
     * The result is a JSON text, so it is written without surrounding quotes
     * (`const data = <?= Html::json($data) ?>;`). U+2028 and U+2029 are
     * escaped as well, as PHP does by default.
     *
     * @param  mixed          $value Value to encode
     * @return string
     * @throws \JsonException If the value cannot be encoded (INF, NAN, recursion, resources)
     */
    public static function json(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }
}
