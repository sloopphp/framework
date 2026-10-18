<?php

declare(strict_types=1);

namespace Sloop\Encryption;

use Sloop\Encryption\Exception\InvalidConfigException;

/**
 * Encrypts and decrypts strings with AES-256 and a 32-byte key.
 *
 * Each payload is the cipher prefix (`g1.` for AES-256-GCM, `c1.` for
 * AES-256-CBC with HMAC-SHA256) followed by unpadded base64url. The working
 * keys are derived per payload with HKDF-SHA256 from the configured key and
 * a random 32-byte salt carried in the payload, so the configured key is
 * never used directly and no per-key limit on the number of GCM encryptions
 * applies.
 *
 * Decryption reads the cipher from the prefix, so payloads written before
 * the cipher setting changed still decrypt. It tries the current key first,
 * then each previous key in order, and returns false when the payload is
 * malformed, has been altered, or matches none of the keys.
 */
final readonly class Encryptor
{
    /**
     * Length of the configured key and of each derived working key, in bytes.
     */
    private const int KEY_BYTES = 32;

    /**
     * Length of the random HKDF salt carried in each payload, in bytes.
     */
    private const int SALT_BYTES = 32;

    /**
     * Length of the AES-256-GCM initialization vector, in bytes.
     */
    private const int GCM_IV_BYTES = 12;

    /**
     * Length of the AES-256-GCM authentication tag, in bytes.
     */
    private const int GCM_TAG_BYTES = 16;

    /**
     * AES block size, which is also the AES-256-CBC initialization vector length, in bytes.
     */
    private const int BLOCK_BYTES = 16;

    /**
     * Length of the HMAC-SHA256 that authenticates an AES-256-CBC payload, in bytes.
     */
    private const int MAC_BYTES = 32;

    /**
     * HKDF context for the AES-256-GCM working key, so it differs from every other key derived from the same input.
     */
    private const string GCM_HKDF_INFO = 'sloop.encryption.g1';

    /**
     * HKDF context for the AES-256-CBC encryption and MAC keys.
     */
    private const string CBC_HKDF_INFO = 'sloop.encryption.c1';

    /**
     * Configured keys as raw bytes: the current key first, then the previous keys in order.
     *
     * @var non-empty-list<string>
     */
    private array $keys;

    /**
     * Create an encryptor from hexadecimal keys.
     *
     * @param  string                 $key          Current key as 64 hexadecimal characters; encrypt() always uses it
     * @param  list<string>           $previousKeys Earlier keys as 64 hexadecimal characters, tried in order after the current key by decrypt()
     * @param  Cipher                 $cipher       Cipher used by encrypt() for new payloads
     * @throws InvalidConfigException If any key is not 64 hexadecimal characters
     */
    public function __construct(
        #[\SensitiveParameter]
        string $key,
        #[\SensitiveParameter]
        array $previousKeys = [],
        private Cipher $cipher = Cipher::Aes256Gcm,
    ) {
        $keys = [self::toBytes($key, 'The encryption key')];
        foreach ($previousKeys as $index => $previousKey) {
            $keys[] = self::toBytes($previousKey, 'Previous encryption key #' . $index);
        }

        $this->keys = $keys;
    }

    /**
     * Generate a new random key in the form the encryption config expects.
     *
     * @return string 64 lowercase hexadecimal characters
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(self::KEY_BYTES));
    }

    /**
     * Check whether the given string is a key in the form the encryption config expects.
     *
     * Upper and lower case hexadecimal digits are both accepted.
     *
     * @param  string $key Candidate key
     * @return bool
     */
    public static function isValidKey(#[\SensitiveParameter] string $key): bool
    {
        return preg_match('/\A[0-9a-fA-F]{64}\z/', $key) === 1;
    }

    /**
     * Encrypt a string with the current key and the configured cipher.
     *
     * @param  string            $value Plain text; any bytes, including the empty string
     * @return string            Cipher prefix followed by unpadded base64url
     * @throws \RuntimeException If OpenSSL fails to encrypt
     */
    public function encrypt(#[\SensitiveParameter] string $value): string
    {
        $prefix = $this->cipher->prefix();
        $salt   = random_bytes(self::SALT_BYTES);
        $body   = match ($this->cipher) {
            Cipher::Aes256Gcm => $this->sealGcm($value, $salt, $prefix),
            Cipher::Aes256Cbc => $this->sealCbc($value, $salt, $prefix),
        };

        return $prefix . self::encodeBase64Url($body);
    }

    /**
     * Decrypt a payload written by encrypt() with the current or a previous key.
     *
     * @param  string       $payload Payload returned by encrypt()
     * @return string|false The plain text, or false when the payload is malformed, has been altered, or matches none of the keys
     */
    public function decrypt(string $payload): string|false
    {
        $cipher = Cipher::fromPayload($payload);
        if ($cipher === null) {
            return false;
        }

        $prefix = $cipher->prefix();
        $body   = self::decodeBase64Url(substr($payload, \strlen($prefix)));
        if ($body === false) {
            return false;
        }

        return match ($cipher) {
            Cipher::Aes256Gcm => $this->openGcm($body, $prefix),
            Cipher::Aes256Cbc => $this->openCbc($body, $prefix),
        };
    }

    /**
     * Hide the keys from var_dump() and print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'cipher' => $this->cipher,
            'keys'   => \count($this->keys) . ' key(s), hidden',
        ];
    }

    /**
     * Encrypt with AES-256-GCM under a key derived from the current key and the salt.
     *
     * @param  string            $value  Plain text
     * @param  string            $salt   Random HKDF salt
     * @param  string            $prefix Payload prefix, authenticated as additional data
     * @return string            Salt, IV, ciphertext and tag, concatenated
     * @throws \RuntimeException If OpenSSL fails to encrypt
     */
    private function sealGcm(#[\SensitiveParameter] string $value, string $salt, string $prefix): string
    {
        $key        = self::deriveGcmKey($this->keys[0], $salt);
        $iv         = random_bytes(self::GCM_IV_BYTES);
        $tag        = '';
        $ciphertext = openssl_encrypt($value, Cipher::Aes256Gcm->value, $key, \OPENSSL_RAW_DATA, $iv, $tag, $prefix, self::GCM_TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('OpenSSL failed to encrypt with ' . Cipher::Aes256Gcm->value . '.');
        }

        return $salt . $iv . $ciphertext . $tag;
    }

    /**
     * Encrypt with AES-256-CBC and authenticate with HMAC-SHA256, both under keys derived from the current key and the salt.
     *
     * @param  string            $value  Plain text
     * @param  string            $salt   Random HKDF salt
     * @param  string            $prefix Payload prefix, covered by the MAC
     * @return string            Salt, IV, ciphertext and MAC, concatenated
     * @throws \RuntimeException If OpenSSL fails to encrypt
     */
    private function sealCbc(#[\SensitiveParameter] string $value, string $salt, string $prefix): string
    {
        [$encryptionKey, $macKey] = self::deriveCbcKeys($this->keys[0], $salt);

        $iv         = random_bytes(self::BLOCK_BYTES);
        $ciphertext = openssl_encrypt($value, Cipher::Aes256Cbc->value, $encryptionKey, \OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('OpenSSL failed to encrypt with ' . Cipher::Aes256Cbc->value . '.');
        }

        $authenticated = $salt . $iv . $ciphertext;

        return $authenticated . hash_hmac('sha256', $prefix . $authenticated, $macKey, true);
    }

    /**
     * Decrypt an AES-256-GCM body, trying each key in order.
     *
     * @param  string       $body   Decoded payload body
     * @param  string       $prefix Payload prefix, authenticated as additional data
     * @return string|false
     */
    private function openGcm(string $body, string $prefix): string|false
    {
        if (\strlen($body) < self::SALT_BYTES + self::GCM_IV_BYTES + self::GCM_TAG_BYTES) {
            return false;
        }

        $salt       = substr($body, 0, self::SALT_BYTES);
        $iv         = substr($body, self::SALT_BYTES, self::GCM_IV_BYTES);
        $ciphertext = substr($body, self::SALT_BYTES + self::GCM_IV_BYTES, -self::GCM_TAG_BYTES);
        $tag        = substr($body, -self::GCM_TAG_BYTES);

        foreach ($this->keys as $configuredKey) {
            $plaintext = openssl_decrypt($ciphertext, Cipher::Aes256Gcm->value, self::deriveGcmKey($configuredKey, $salt), \OPENSSL_RAW_DATA, $iv, $tag, $prefix);
            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        return false;
    }

    /**
     * Verify and decrypt an AES-256-CBC body, trying each key in order.
     *
     * @param  string       $body   Decoded payload body
     * @param  string       $prefix Payload prefix, covered by the MAC
     * @return string|false
     */
    private function openCbc(string $body, string $prefix): string|false
    {
        $ciphertextLength = \strlen($body) - self::SALT_BYTES - self::BLOCK_BYTES - self::MAC_BYTES;
        if ($ciphertextLength < self::BLOCK_BYTES || $ciphertextLength % self::BLOCK_BYTES !== 0) {
            return false;
        }

        $salt          = substr($body, 0, self::SALT_BYTES);
        $iv            = substr($body, self::SALT_BYTES, self::BLOCK_BYTES);
        $ciphertext    = substr($body, self::SALT_BYTES + self::BLOCK_BYTES, $ciphertextLength);
        $mac           = substr($body, -self::MAC_BYTES);
        $authenticated = $prefix . substr($body, 0, -self::MAC_BYTES);

        foreach ($this->keys as $configuredKey) {
            [$encryptionKey, $macKey] = self::deriveCbcKeys($configuredKey, $salt);
            if (!hash_equals(hash_hmac('sha256', $authenticated, $macKey, true), $mac)) {
                continue;
            }

            return openssl_decrypt($ciphertext, Cipher::Aes256Cbc->value, $encryptionKey, \OPENSSL_RAW_DATA, $iv);
        }

        return false;
    }

    /**
     * Derive the AES-256-GCM working key.
     *
     * @param  string $configuredKey Configured key as raw bytes
     * @param  string $salt          HKDF salt from the payload
     * @return string
     */
    private static function deriveGcmKey(#[\SensitiveParameter] string $configuredKey, string $salt): string
    {
        return hash_hkdf('sha256', $configuredKey, self::KEY_BYTES, self::GCM_HKDF_INFO, $salt);
    }

    /**
     * Derive the AES-256-CBC encryption key and the HMAC-SHA256 key.
     *
     * @param  string                $configuredKey Configured key as raw bytes
     * @param  string                $salt          HKDF salt from the payload
     * @return array{string, string} Encryption key and MAC key
     */
    private static function deriveCbcKeys(#[\SensitiveParameter] string $configuredKey, string $salt): array
    {
        $material = hash_hkdf('sha256', $configuredKey, self::KEY_BYTES * 2, self::CBC_HKDF_INFO, $salt);

        return [substr($material, 0, self::KEY_BYTES), substr($material, self::KEY_BYTES)];
    }

    /**
     * Convert a hexadecimal key to raw bytes.
     *
     * @param  string                 $key   Key as 64 hexadecimal characters
     * @param  string                 $label Name of the key for the exception message
     * @return string
     * @throws InvalidConfigException If the key is not 64 hexadecimal characters
     */
    private static function toBytes(#[\SensitiveParameter] string $key, string $label): string
    {
        $bytes = self::isValidKey($key) ? hex2bin($key) : false;
        if ($bytes === false) {
            throw new InvalidConfigException($label . ' must be 64 hexadecimal characters (32 bytes).');
        }

        return $bytes;
    }

    /**
     * Encode bytes as base64url without padding.
     *
     * @param  string $bytes Raw bytes
     * @return string
     */
    private static function encodeBase64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode unpadded base64url, accepting only the spelling encodeBase64Url() writes.
     *
     * @param  string       $encoded Encoded text
     * @return string|false
     */
    private static function decodeBase64Url(string $encoded): string|false
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1) {
            return false;
        }

        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($bytes === false || self::encodeBase64Url($bytes) !== $encoded) {
            return false;
        }

        return $bytes;
    }
}
