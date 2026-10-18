<?php

declare(strict_types=1);

namespace Sloop\Encryption;

use Sloop\Encryption\Exception\InvalidConfigException;

/**
 * A 32-byte key written as 64 hexadecimal characters.
 *
 * @internal Shared by Encryptor and Signer, whose configs hold keys in this form.
 */
final class HexKey
{
    /**
     * Length of a key, in bytes.
     */
    public const int BYTES = 32;

    /**
     * Generate a new random key.
     *
     * @return string 64 lowercase hexadecimal characters
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    /**
     * Check whether the given string is 64 hexadecimal characters, in either case.
     *
     * @param  string $key Candidate key
     * @return bool
     */
    public static function isValid(#[\SensitiveParameter] string $key): bool
    {
        return preg_match('/\A[0-9a-fA-F]{64}\z/', $key) === 1;
    }

    /**
     * Convert a key to raw bytes.
     *
     * @param  string                 $key   Key as 64 hexadecimal characters
     * @param  string                 $label Name of the key for the exception message
     * @return string
     * @throws InvalidConfigException If the key is not 64 hexadecimal characters
     */
    public static function toBytes(#[\SensitiveParameter] string $key, string $label): string
    {
        $bytes = self::isValid($key) ? hex2bin($key) : false;
        if ($bytes === false) {
            throw new InvalidConfigException($label . ' must be 64 hexadecimal characters (32 bytes).');
        }

        return $bytes;
    }
}
