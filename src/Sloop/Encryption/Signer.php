<?php

declare(strict_types=1);

namespace Sloop\Encryption;

/**
 * Signs strings with HMAC-SHA256 so that they can be handed out and later checked for changes.
 *
 * A signed payload is the prefix `s1.` followed by the unpadded base64url of
 * the value and its 32-byte MAC. The value is readable by anyone who decodes
 * the payload; signing proves only that it has not been changed. The MAC key
 * is derived with HKDF-SHA256 from the configured key, so the configured key
 * is never used directly and differs from every key Encryptor derives even if
 * the same key were configured for both.
 *
 * Verification tries the current key first, then each previous key in order,
 * and returns false when the payload is malformed, has been altered, or
 * matches none of the keys.
 */
final readonly class Signer
{
    /**
     * Prefix of every payload, naming the format version; it is also covered by the MAC.
     */
    private const string PREFIX = 's1.';

    /**
     * Length of the HMAC-SHA256, in bytes.
     */
    private const int MAC_BYTES = 32;

    /**
     * HKDF context for the MAC key, so it differs from every other key derived from the same input.
     */
    private const string HKDF_INFO = 'sloop.signing.s1';

    /**
     * MAC keys derived from the configured keys: the current key first, then the previous keys in order.
     *
     * @var non-empty-list<string>
     */
    private array $macKeys;

    /**
     * Create a signer from hexadecimal keys.
     *
     * @param  string                    $key          Current key as 64 hexadecimal characters; sign() always uses it
     * @param  list<string>              $previousKeys Earlier keys as 64 hexadecimal characters, tried in order after the current key by verify()
     * @throws \InvalidArgumentException If any key is not 64 hexadecimal characters
     */
    public function __construct(
        #[\SensitiveParameter]
        string $key,
        #[\SensitiveParameter]
        array $previousKeys = [],
    ) {
        $macKeys = [self::deriveMacKey(HexKey::toBytes($key, 'The signing key'))];
        foreach ($previousKeys as $index => $previousKey) {
            $macKeys[] = self::deriveMacKey(HexKey::toBytes($previousKey, 'Previous signing key #' . $index));
        }

        $this->macKeys = $macKeys;
    }

    /**
     * Generate a new random key in the form the signing config expects.
     *
     * @return string 64 lowercase hexadecimal characters
     */
    public static function generateKey(): string
    {
        return HexKey::generate();
    }

    /**
     * Sign a string with the current key.
     *
     * The same value always gives the same payload.
     *
     * @param  string $value Value to sign; any bytes, including the empty string
     * @return string Prefix `s1.` followed by unpadded base64url of the value and its MAC
     */
    public function sign(string $value): string
    {
        return self::PREFIX . Base64Url::encode($value . self::mac($value, $this->macKeys[0]));
    }

    /**
     * Check a payload written by sign() with the current or a previous key and return the signed value.
     *
     * @param  string       $payload Payload returned by sign()
     * @return string|false The signed value, or false when the payload is malformed, has been altered, or matches none of the keys
     */
    public function verify(string $payload): string|false
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            return false;
        }

        $body = Base64Url::decode(substr($payload, \strlen(self::PREFIX)));
        if ($body === false || \strlen($body) < self::MAC_BYTES) {
            return false;
        }

        $value = substr($body, 0, -self::MAC_BYTES);
        $mac   = substr($body, -self::MAC_BYTES);
        foreach ($this->macKeys as $macKey) {
            if (hash_equals(self::mac($value, $macKey), $mac)) {
                return $value;
            }
        }

        return false;
    }

    /**
     * Hide the keys from var_dump() and print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'keys' => \count($this->macKeys) . ' key(s), hidden',
        ];
    }

    /**
     * Compute the MAC of a value, covering the prefix as well.
     *
     * @param  string $value  Signed value
     * @param  string $macKey Derived MAC key
     * @return string Raw 32-byte HMAC-SHA256
     */
    private static function mac(string $value, #[\SensitiveParameter] string $macKey): string
    {
        return hash_hmac('sha256', self::PREFIX . $value, $macKey, true);
    }

    /**
     * Derive the MAC key from a configured key.
     *
     * @param  string $configuredKey Configured key as raw bytes
     * @return string
     */
    private static function deriveMacKey(#[\SensitiveParameter] string $configuredKey): string
    {
        return hash_hkdf('sha256', $configuredKey, HexKey::BYTES, self::HKDF_INFO);
    }
}
