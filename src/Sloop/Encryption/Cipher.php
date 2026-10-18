<?php

declare(strict_types=1);

namespace Sloop\Encryption;

/**
 * Symmetric cipher used by Encryptor to write new payloads.
 *
 * The value is the name read from the `encryption.cipher` config entry.
 * Decryption does not depend on this choice: every payload carries a prefix
 * naming the cipher it was written with.
 */
enum Cipher: string
{
    case Aes256Gcm = 'aes-256-gcm';
    case Aes256Cbc = 'aes-256-cbc';

    /**
     * Payload prefix naming this cipher and the payload format version.
     *
     * @return non-empty-string
     */
    public function prefix(): string
    {
        return match ($this) {
            self::Aes256Gcm => 'g1.',
            self::Aes256Cbc => 'c1.',
        };
    }

    /**
     * Find the cipher whose payload prefix opens the given payload.
     *
     * @param  string    $payload Encrypted payload
     * @return self|null
     */
    public static function fromPayload(string $payload): ?self
    {
        foreach (self::cases() as $cipher) {
            if (str_starts_with($payload, $cipher->prefix())) {
                return $cipher;
            }
        }

        return null;
    }
}
