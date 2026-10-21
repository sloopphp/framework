<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Encryption;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Encryption\Cipher;
use Sloop\Encryption\Encryptor;

final class EncryptorTest extends TestCase
{
    private const string KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    private const string OTHER_KEY = 'f0f1f2f3f4f5f6f7f8f9fafbfcfdfeff000102030405060708090a0b0c0d0e0f';

    private static function toBytes(string $hex): string
    {
        $bytes = hex2bin($hex);
        self::assertIsString($bytes);

        return $bytes;
    }

    private static function decodeBody(string $payload): string
    {
        $decoded = base64_decode(strtr(substr($payload, 3), '-_', '+/'), true);
        self::assertIsString($decoded);

        return $decoded;
    }

    private static function encodeBody(string $body): string
    {
        return rtrim(strtr(base64_encode($body), '+/', '-_'), '=');
    }

    private static function nonCanonicalTail(string $body): string
    {
        // A body whose last character carries unused low bits decodes to the same
        // bytes when those bits are set; only the canonical spelling is accepted.
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $last     = strpos($alphabet, $body[\strlen($body) - 1]);
        self::assertIsInt($last);

        return substr($body, 0, -1) . $alphabet[$last | 0x01];
    }

    // -------------------------------------------------------
    // encrypt / decrypt
    // -------------------------------------------------------

    #[DataProvider('cipherAndPlaintextProvider')]
    public function testDecryptReturnsTheEncryptedValue(Cipher $cipher, string $plaintext): void
    {
        $encryptor = new Encryptor(self::KEY, cipher: $cipher);

        $this->assertSame($plaintext, $encryptor->decrypt($encryptor->encrypt($plaintext)));
    }

    /**
     * @return array<string, array{Cipher, string}>
     */
    public static function cipherAndPlaintextProvider(): array
    {
        $cases = [];
        foreach (Cipher::cases() as $cipher) {
            $cases[$cipher->value . ' empty string']   = [$cipher, ''];
            $cases[$cipher->value . ' zero']           = [$cipher, '0'];
            $cases[$cipher->value . ' ascii']          = [$cipher, 'hello world'];
            $cases[$cipher->value . ' multibyte']      = [$cipher, '暗号化 🔐'];
            $cases[$cipher->value . ' binary']         = [$cipher, "\x00\xff\x80binary\x00"];
            $cases[$cipher->value . ' one block']      = [$cipher, str_repeat('a', 16)];
            $cases[$cipher->value . ' several blocks'] = [$cipher, str_repeat('xyz', 1000)];
        }

        return $cases;
    }

    public function testEncryptWithGcmStartsWithTheGcmPrefix(): void
    {
        $payload = new Encryptor(self::KEY)->encrypt('value');

        $this->assertStringStartsWith('g1.', $payload);
    }

    public function testEncryptWithCbcStartsWithTheCbcPrefix(): void
    {
        $payload = new Encryptor(self::KEY, cipher: Cipher::Aes256Cbc)->encrypt('value');

        $this->assertStringStartsWith('c1.', $payload);
    }

    #[DataProvider('cipherProvider')]
    public function testEncryptWritesBase64UrlWithoutPadding(Cipher $cipher): void
    {
        $encryptor = new Encryptor(self::KEY, cipher: $cipher);

        for ($length = 0; $length < 20; $length++) {
            $payload = $encryptor->encrypt(str_repeat('a', $length));
            $this->assertMatchesRegularExpression('/\A[gc]1\.[A-Za-z0-9_-]+\z/', $payload);
        }
    }

    #[DataProvider('cipherProvider')]
    public function testEncryptGivesADifferentPayloadEachTime(Cipher $cipher): void
    {
        $encryptor = new Encryptor(self::KEY, cipher: $cipher);

        $this->assertNotSame($encryptor->encrypt('same'), $encryptor->encrypt('same'));
    }

    /**
     * @return array<string, array{Cipher}>
     */
    public static function cipherProvider(): array
    {
        $cases = [];
        foreach (Cipher::cases() as $cipher) {
            $cases[$cipher->value] = [$cipher];
        }

        return $cases;
    }

    public function testGcmPayloadHasSaltIvCiphertextAndTag(): void
    {
        $payload = new Encryptor(self::KEY)->encrypt('12345');

        // 32-byte salt + 12-byte IV + 5-byte ciphertext + 16-byte tag
        $this->assertSame(32 + 12 + 5 + 16, \strlen(self::decodeBody($payload)));
    }

    public function testCbcPayloadHasSaltIvPaddedCiphertextAndMac(): void
    {
        $payload = new Encryptor(self::KEY, cipher: Cipher::Aes256Cbc)->encrypt('12345');

        // 32-byte salt + 16-byte IV + one padded block + 32-byte HMAC-SHA256
        $this->assertSame(32 + 16 + 16 + 32, \strlen(self::decodeBody($payload)));
    }

    public function testDecryptChoosesTheCipherFromThePrefix(): void
    {
        $gcmPayload = new Encryptor(self::KEY)->encrypt('switched');
        $cbcPayload = new Encryptor(self::KEY, cipher: Cipher::Aes256Cbc)->encrypt('switched');

        $this->assertSame('switched', new Encryptor(self::KEY, cipher: Cipher::Aes256Cbc)->decrypt($gcmPayload));
        $this->assertSame('switched', new Encryptor(self::KEY)->decrypt($cbcPayload));
    }

    // -------------------------------------------------------
    // previous keys
    // -------------------------------------------------------

    #[DataProvider('cipherProvider')]
    public function testDecryptAcceptsAPayloadMadeWithAPreviousKey(Cipher $cipher): void
    {
        $old     = new Encryptor(self::OTHER_KEY, cipher: $cipher)->encrypt('rotated');
        $rotated = new Encryptor(self::KEY, [self::OTHER_KEY], $cipher);

        $this->assertSame('rotated', $rotated->decrypt($old));
    }

    public function testDecryptTriesEveryPreviousKey(): void
    {
        $old     = new Encryptor(self::OTHER_KEY)->encrypt('oldest');
        $rotated = new Encryptor(self::KEY, [Encryptor::generateKey(), self::OTHER_KEY]);

        $this->assertSame('oldest', $rotated->decrypt($old));
    }

    public function testEncryptUsesTheCurrentKeyNotAPreviousOne(): void
    {
        $payload = new Encryptor(self::KEY, [self::OTHER_KEY])->encrypt('new');

        $this->assertSame('new', new Encryptor(self::KEY)->decrypt($payload));
        $this->assertFalse(new Encryptor(self::OTHER_KEY)->decrypt($payload));
    }

    #[DataProvider('cipherProvider')]
    public function testDecryptReturnsFalseForAnUnknownKey(Cipher $cipher): void
    {
        $payload = new Encryptor(self::OTHER_KEY, cipher: $cipher)->encrypt('secret');

        $this->assertFalse(new Encryptor(self::KEY, cipher: $cipher)->decrypt($payload));
    }

    // -------------------------------------------------------
    // decrypt failures
    // -------------------------------------------------------

    #[DataProvider('cipherProvider')]
    public function testDecryptReturnsFalseWhenAnyByteOfTheBodyChanges(Cipher $cipher): void
    {
        $encryptor = new Encryptor(self::KEY, cipher: $cipher);
        $payload   = $encryptor->encrypt('tamper me');
        $prefix    = substr($payload, 0, 3);
        $body      = self::decodeBody($payload);

        for ($i = 0; $i < \strlen($body); $i++) {
            $tampered     = $body;
            $tampered[$i] = \chr(\ord($tampered[$i]) ^ 0x01);
            $this->assertFalse($encryptor->decrypt($prefix . self::encodeBody($tampered)), 'byte ' . $i);
        }
    }

    #[DataProvider('cipherProvider')]
    public function testDecryptReturnsFalseForATruncatedBody(Cipher $cipher): void
    {
        $encryptor = new Encryptor(self::KEY, cipher: $cipher);
        $payload   = $encryptor->encrypt('truncate me');
        $prefix    = substr($payload, 0, 3);
        $body      = self::decodeBody($payload);

        for ($length = 0; $length < \strlen($body); $length++) {
            $this->assertFalse($encryptor->decrypt($prefix . self::encodeBody(substr($body, 0, $length))), 'length ' . $length);
        }
    }

    public function testDecryptReturnsFalseWhenTheGcmPrefixIsSwappedForCbc(): void
    {
        $encryptor = new Encryptor(self::KEY);
        $payload   = $encryptor->encrypt(str_repeat('p', 64));

        $this->assertFalse($encryptor->decrypt('c1.' . substr($payload, 3)));
    }

    public function testDecryptReturnsFalseWhenTheCbcPrefixIsSwappedForGcm(): void
    {
        $encryptor = new Encryptor(self::KEY, cipher: Cipher::Aes256Cbc);
        $payload   = $encryptor->encrypt('swap');

        $this->assertFalse($encryptor->decrypt('g1.' . substr($payload, 3)));
    }

    public function testDecryptReturnsFalseForAPayloadSpelledInTheStandardBase64Alphabet(): void
    {
        $encryptor = new Encryptor(self::KEY);

        // Only a body containing '-' or '_' changes when spelled with '+' and '/'.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $body = substr($encryptor->encrypt('standard alphabet'), 3);
            if (strpbrk($body, '-_') !== false) {
                $this->assertFalse($encryptor->decrypt('g1.' . strtr($body, '-_', '+/')));

                return;
            }
        }

        $this->fail('No payload with - or _ in 100 attempts.');
    }

    #[DataProvider('malformedPayloadProvider')]
    public function testDecryptReturnsFalseForAMalformedPayload(string $payload): void
    {
        $this->assertFalse(new Encryptor(self::KEY)->decrypt($payload));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedPayloadProvider(): array
    {
        $valid = new Encryptor(self::KEY)->encrypt('valid');
        $body  = substr($valid, 3);

        return [
            'empty string'            => [''],
            'prefix only'             => ['g1.'],
            'no prefix'               => [$body],
            'unknown version'         => ['g2.' . $body],
            'unknown cipher'          => ['x1.' . $body],
            'uppercase prefix'        => ['G1.' . $body],
            'missing separator'       => ['g1' . $body],
            'padding appended'        => [$valid . '='],
            'whitespace inside'       => ['g1.' . substr($body, 0, 10) . ' ' . substr($body, 10)],
            'trailing newline'        => [$valid . "\n"],
            'non-canonical last char' => ['g1.' . self::nonCanonicalTail($body)],
            'not base64 at all'       => ['g1.!!!!'],
        ];
    }

    // -------------------------------------------------------
    // payload format
    // -------------------------------------------------------

    public function testDecryptReadsAGcmPayloadBuiltFromTheFormat(): void
    {
        $salt = str_repeat("\x11", 32);
        $iv   = str_repeat("\x22", 12);
        $key  = hash_hkdf('sha256', self::toBytes(self::KEY), 32, 'sloop.encryption.g1', $salt);
        $tag  = '';
        $body = openssl_encrypt('format check', 'aes-256-gcm', $key, \OPENSSL_RAW_DATA, $iv, $tag, 'g1.', 16);
        self::assertIsString($body);

        $payload = 'g1.' . self::encodeBody($salt . $iv . $body . $tag);

        $this->assertSame('format check', new Encryptor(self::KEY)->decrypt($payload));
    }

    public function testDecryptReadsACbcPayloadBuiltFromTheFormat(): void
    {
        $salt     = str_repeat("\x33", 32);
        $iv       = str_repeat("\x44", 16);
        $material = hash_hkdf('sha256', self::toBytes(self::KEY), 64, 'sloop.encryption.c1', $salt);
        $body     = openssl_encrypt('format check', 'aes-256-cbc', substr($material, 0, 32), \OPENSSL_RAW_DATA, $iv);
        self::assertIsString($body);
        $mac = hash_hmac('sha256', 'c1.' . $salt . $iv . $body, substr($material, 32), true);

        $payload = 'c1.' . self::encodeBody($salt . $iv . $body . $mac);

        $this->assertSame('format check', new Encryptor(self::KEY)->decrypt($payload));
    }

    // -------------------------------------------------------
    // generateKey
    // -------------------------------------------------------

    public function testGenerateKeyReturns64LowercaseHexCharacters(): void
    {
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', Encryptor::generateKey());
    }

    public function testGenerateKeyReturnsADifferentKeyEachTime(): void
    {
        $this->assertNotSame(Encryptor::generateKey(), Encryptor::generateKey());
    }

    public function testGeneratedKeyCanBeUsedAsIs(): void
    {
        $encryptor = new Encryptor(Encryptor::generateKey());

        $this->assertSame('ok', $encryptor->decrypt($encryptor->encrypt('ok')));
    }

    // -------------------------------------------------------
    // key validation
    // -------------------------------------------------------

    public function testConstructorAcceptsUppercaseHex(): void
    {
        $payload = new Encryptor(self::KEY)->encrypt('case');

        $this->assertSame('case', new Encryptor(strtoupper(self::KEY))->decrypt($payload));
    }

    #[DataProvider('invalidKeyProvider')]
    public function testConstructorRejectsAKeyThatIsNot64HexCharacters(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The encryption key must be 64 hexadecimal characters (32 bytes).');

        new Encryptor($key);
    }

    #[DataProvider('invalidKeyProvider')]
    public function testConstructorRejectsAPreviousKeyThatIsNot64HexCharacters(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Previous encryption key #1 must be 64 hexadecimal characters (32 bytes).');

        new Encryptor(self::KEY, [self::OTHER_KEY, $key]);
    }

    #[DataProvider('invalidKeyProvider')]
    public function testIsValidKeyRejectsAKeyThatIsNot64HexCharacters(string $key): void
    {
        $this->assertFalse(Encryptor::isValidKey($key));
    }

    public function testIsValidKeyAcceptsLowerAndUppercaseHex(): void
    {
        $this->assertTrue(Encryptor::isValidKey(self::KEY));
        $this->assertTrue(Encryptor::isValidKey(strtoupper(self::KEY)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeyProvider(): array
    {
        return [
            'empty'             => [''],
            '63 characters'     => [substr(self::KEY, 0, 63)],
            '65 characters'     => [self::KEY . '0'],
            'non-hex character' => [substr(self::KEY, 0, 63) . 'g'],
            'base64 prefix'     => ['base64:' . base64_encode(random_bytes(32))],
            'raw 32 bytes'      => [str_repeat('k', 32)],
            'trailing newline'  => [self::KEY . "\n"],
            'leading space'     => [' ' . substr(self::KEY, 1)],
        ];
    }

    public function testInvalidKeyMessageDoesNotContainTheKey(): void
    {
        $key = substr(self::KEY, 0, 62) . 'zz';

        try {
            new Encryptor($key);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString($key, $e->getMessage());
        }
    }

    public function testStackTraceDoesNotContainTheKeys(): void
    {
        $badPreviousKey = substr(self::OTHER_KEY, 0, 62) . 'zz';
        $ignoreArgs     = ini_set('zend.exception_ignore_args', '0');

        try {
            new Encryptor(self::KEY, [$badPreviousKey]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $exception = $e;
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '1' : $ignoreArgs);
        }

        $this->assertStringNotContainsString(self::KEY, $exception->getTraceAsString());
        $this->assertStringNotContainsString($badPreviousKey, $exception->getTraceAsString());

        $arguments = [];
        foreach ($exception->getTrace() as $frame) {
            if (($frame['class'] ?? null) === Encryptor::class) {
                $arguments = [...$arguments, ...($frame['args'] ?? [])];
            }
        }

        $this->assertNotContains(self::KEY, $arguments);
        $this->assertNotContains($badPreviousKey, $arguments);
        $this->assertNotContains([$badPreviousKey], $arguments);
        $this->assertNotEmpty(array_filter($arguments, static fn (mixed $argument): bool => $argument instanceof \SensitiveParameterValue));
    }

    public function testDebugInfoShowsTheCipherAndTheNumberOfKeysOnly(): void
    {
        $this->assertSame(
            ['cipher' => Cipher::Aes256Cbc, 'keys' => '2 key(s), hidden'],
            new Encryptor(self::KEY, [self::OTHER_KEY], Cipher::Aes256Cbc)->__debugInfo(),
        );
    }

    public function testDebugInfoDoesNotContainTheKeys(): void
    {
        $debugInfo = var_export(new Encryptor(self::KEY, [self::OTHER_KEY])->__debugInfo(), true);

        $this->assertStringNotContainsString(self::toBytes(self::KEY), $debugInfo);
        $this->assertStringNotContainsString(self::toBytes(self::OTHER_KEY), $debugInfo);
        $this->assertStringNotContainsString(self::KEY, $debugInfo);
    }

}
