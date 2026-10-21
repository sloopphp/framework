<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Encryption;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Encryption\Encryptor;
use Sloop\Encryption\Signer;

final class SignerTest extends TestCase
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

    private static function macFor(string $key, string $value): string
    {
        $macKey = hash_hkdf('sha256', self::toBytes($key), 32, 'sloop.signing.s1');

        return hash_hmac('sha256', 's1.' . $value, $macKey, true);
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
    // sign / verify
    // -------------------------------------------------------

    #[DataProvider('valueProvider')]
    public function testVerifyReturnsTheSignedValue(string $value): void
    {
        $signer = new Signer(self::KEY);

        $this->assertSame($value, $signer->verify($signer->sign($value)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valueProvider(): array
    {
        return [
            'empty string'   => [''],
            'zero'           => ['0'],
            'ascii'          => ['user:42'],
            'contains dot'   => ['a.b.c'],
            'utf-8'          => ['署名する値'],
            'binary'         => ["\x00\xff\x80binary\x00"],
            'longer than 1k' => [str_repeat('x', 4096)],
            '1 MiB'          => [str_repeat('x', 1024 * 1024)],
        ];
    }

    public function testSignStartsWithTheVersionPrefix(): void
    {
        $this->assertStringStartsWith('s1.', new Signer(self::KEY)->sign('value'));
    }

    public function testSignWritesBase64UrlWithoutPadding(): void
    {
        // Lengths 1 and 2 leave a partial last group, which standard base64 pads with '='.
        foreach (['a', 'ab', 'abc', "\xfb\xff"] as $value) {
            $this->assertMatchesRegularExpression('/\As1\.[A-Za-z0-9_-]+\z/', new Signer(self::KEY)->sign($value));
        }
    }

    public function testSignIsDeterministic(): void
    {
        $signer = new Signer(self::KEY);

        $this->assertSame($signer->sign('value'), $signer->sign('value'));
    }

    public function testSignedBodyIsTheValueFollowedByA32ByteMac(): void
    {
        $body = self::decodeBody(new Signer(self::KEY)->sign('value'));

        $this->assertSame('value', substr($body, 0, -32));
        $this->assertSame(self::macFor(self::KEY, 'value'), substr($body, -32));
    }

    public function testSignUsesTheCurrentKeyNotAPreviousOne(): void
    {
        $payload = new Signer(self::KEY, [self::OTHER_KEY])->sign('current');

        $this->assertSame('current', new Signer(self::KEY)->verify($payload));
        $this->assertFalse(new Signer(self::OTHER_KEY)->verify($payload));
    }

    public function testVerifyAcceptsAPayloadSignedWithAPreviousKey(): void
    {
        $payload = new Signer(self::OTHER_KEY)->sign('old');

        $this->assertSame('old', new Signer(self::KEY, [self::OTHER_KEY])->verify($payload));
    }

    public function testVerifyTriesEveryPreviousKey(): void
    {
        $payload = new Signer(self::OTHER_KEY)->sign('oldest');

        $this->assertSame('oldest', new Signer(self::KEY, [Signer::generateKey(), self::OTHER_KEY])->verify($payload));
    }

    public function testVerifyReturnsFalseForAnUnknownKey(): void
    {
        $payload = new Signer(self::OTHER_KEY)->sign('value');

        $this->assertFalse(new Signer(self::KEY)->verify($payload));
    }

    public function testVerifyReturnsFalseWhenAnyByteOfTheBodyChanges(): void
    {
        $signer = new Signer(self::KEY);
        $body   = self::decodeBody($signer->sign('tamper'));

        for ($i = 0; $i < \strlen($body); $i++) {
            $altered     = $body;
            $altered[$i] = \chr(\ord($altered[$i]) ^ 0x01);

            $this->assertFalse($signer->verify('s1.' . self::encodeBody($altered)), 'byte ' . $i);
        }
    }

    public function testVerifyReturnsFalseWhenTheValueIsMovedIntoTheMac(): void
    {
        // The MAC covers the value, so trailing value bytes cannot be traded for MAC bytes.
        $signer = new Signer(self::KEY);
        $body   = self::decodeBody($signer->sign('value'));

        $this->assertFalse($signer->verify('s1.' . self::encodeBody(substr($body, 1))));
        $this->assertFalse($signer->verify('s1.' . self::encodeBody($body . 'x')));
    }

    public function testVerifyReturnsFalseForABodyShorterThanTheMac(): void
    {
        $signer = new Signer(self::KEY);
        $body   = self::decodeBody($signer->sign(''));

        $this->assertSame(32, \strlen($body));
        $this->assertFalse($signer->verify('s1.' . self::encodeBody(substr($body, 0, 31))));
    }

    public function testVerifyReturnsFalseForAnEncryptorPayloadMadeWithTheSameKey(): void
    {
        $encrypted = new Encryptor(self::KEY)->encrypt('value');

        $this->assertFalse(new Signer(self::KEY)->verify($encrypted));
        $this->assertFalse(new Signer(self::KEY)->verify('s1.' . substr($encrypted, 3)));
    }

    public function testVerifyReturnsFalseForAPayloadSpelledInTheStandardBase64Alphabet(): void
    {
        $signer = new Signer(self::KEY);

        // Only a body containing '-' or '_' changes when spelled with '+' and '/'.
        // sign() is deterministic, so the loop stops at the same value on every run.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $body = substr($signer->sign('standard alphabet ' . $attempt), 3);
            if (strpbrk($body, '-_') !== false) {
                $this->assertFalse($signer->verify('s1.' . strtr($body, '-_', '+/')));

                return;
            }
        }

        $this->fail('No payload with - or _ in 100 attempts.');
    }

    #[DataProvider('malformedPayloadProvider')]
    public function testVerifyReturnsFalseForAMalformedPayload(string $payload): void
    {
        $this->assertFalse(new Signer(self::KEY)->verify($payload));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedPayloadProvider(): array
    {
        $valid = new Signer(self::KEY)->sign('valid');
        $body  = substr($valid, 3);

        return [
            'empty string'            => [''],
            'prefix only'             => ['s1.'],
            'no prefix'               => [$body],
            'unknown version'         => ['s2.' . $body],
            'uppercase prefix'        => ['S1.' . $body],
            'missing separator'       => ['s1' . $body],
            'padding appended'        => [$valid . '='],
            'whitespace inside'       => ['s1.' . substr($body, 0, 10) . ' ' . substr($body, 10)],
            'trailing newline'        => [$valid . "\n"],
            'non-canonical last char' => ['s1.' . self::nonCanonicalTail($body)],
            'not base64 at all'       => ['s1.!!!!'],
        ];
    }

    // -------------------------------------------------------
    // payload format
    // -------------------------------------------------------

    public function testVerifyReadsAPayloadBuiltFromTheFormat(): void
    {
        $payload = 's1.' . self::encodeBody('format check' . self::macFor(self::KEY, 'format check'));

        $this->assertSame('format check', new Signer(self::KEY)->verify($payload));
    }

    public function testMacKeyIsNotTheConfiguredKey(): void
    {
        $plainMac = hash_hmac('sha256', 's1.value', self::toBytes(self::KEY), true);
        $payload  = 's1.' . self::encodeBody('value' . $plainMac);

        $this->assertFalse(new Signer(self::KEY)->verify($payload));
    }

    // -------------------------------------------------------
    // generateKey / keys
    // -------------------------------------------------------

    public function testGenerateKeyReturns64LowercaseHexCharacters(): void
    {
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', Signer::generateKey());
    }

    public function testGenerateKeyReturnsADifferentKeyEachTime(): void
    {
        $this->assertNotSame(Signer::generateKey(), Signer::generateKey());
    }

    public function testGeneratedKeyCanBeUsedAsIs(): void
    {
        $signer = new Signer(Signer::generateKey());

        $this->assertSame('value', $signer->verify($signer->sign('value')));
    }

    public function testConstructorAcceptsUppercaseHex(): void
    {
        $payload = new Signer(self::KEY)->sign('case');

        $this->assertSame('case', new Signer(strtoupper(self::KEY))->verify($payload));
    }

    #[DataProvider('invalidKeyProvider')]
    public function testConstructorRejectsAKeyThatIsNot64HexCharacters(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The signing key must be 64 hexadecimal characters (32 bytes).');

        new Signer($key);
    }

    #[DataProvider('invalidKeyProvider')]
    public function testConstructorRejectsAPreviousKeyThatIsNot64HexCharacters(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Previous signing key #1 must be 64 hexadecimal characters (32 bytes).');

        new Signer(self::KEY, [self::OTHER_KEY, $key]);
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
            new Signer($key);
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
            new Signer(self::KEY, [$badPreviousKey]);
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
            if (($frame['class'] ?? null) === Signer::class) {
                $arguments = [...$arguments, ...($frame['args'] ?? [])];
            }
        }

        $this->assertNotEmpty(array_filter($arguments, static fn (mixed $argument): bool => $argument instanceof \SensitiveParameterValue));
    }

    public function testDebugInfoShowsTheNumberOfKeysOnly(): void
    {
        $this->assertSame(['keys' => '2 key(s), hidden'], new Signer(self::KEY, [self::OTHER_KEY])->__debugInfo());
    }

    public function testDebugInfoDoesNotContainTheKeys(): void
    {
        $debugInfo = var_export(new Signer(self::KEY, [self::OTHER_KEY])->__debugInfo(), true);

        $this->assertStringNotContainsString(self::toBytes(self::KEY), $debugInfo);
        $this->assertStringNotContainsString(self::toBytes(self::OTHER_KEY), $debugInfo);
        $this->assertStringNotContainsString(self::KEY, $debugInfo);
    }
}
