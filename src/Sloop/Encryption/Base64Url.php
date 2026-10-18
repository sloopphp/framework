<?php

declare(strict_types=1);

namespace Sloop\Encryption;

/**
 * Unpadded base64url, accepting on decode only the spelling encode() writes.
 *
 * @internal Shared by Encryptor and Signer for their payload bodies.
 */
final class Base64Url
{
    /**
     * Encode bytes as base64url without padding.
     *
     * @param  string $bytes Raw bytes
     * @return string
     */
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode unpadded base64url, rejecting any other spelling of the same bytes.
     *
     * @param  string       $encoded Encoded text
     * @return string|false The bytes, or false when the text is empty, uses another alphabet, is padded, or sets unused bits
     */
    public static function decode(string $encoded): string|false
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1) {
            return false;
        }

        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($bytes === false || self::encode($bytes) !== $encoded) {
            return false;
        }

        return $bytes;
    }
}
