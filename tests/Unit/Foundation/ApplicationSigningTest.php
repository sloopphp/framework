<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Config\Config;
use Sloop\Encryption\Encryptor;
use Sloop\Encryption\Exception\EncryptionConfigException;
use Sloop\Encryption\Signer;
use Sloop\Foundation\Application;
use Sloop\Foundation\Path;
use Sloop\Log\Log;
use Sloop\Validation\ValidationMessages;

final class ApplicationSigningTest extends TestCase
{
    private const string KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    private const string OTHER_KEY = 'f0f1f2f3f4f5f6f7f8f9fafbfcfdfeff000102030405060708090a0b0c0d0e0f';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_app_signing_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/config');
        mkdir($this->tmpDir . '/routes');

        Path::reset();
        Config::reset();
        Log::reset();
        ValidationMessages::reset();
    }

    protected function tearDown(): void
    {
        Path::reset();
        Config::reset();
        Log::reset();
        ValidationMessages::reset();

        foreach (['signing', 'encryption'] as $name) {
            $configFile = $this->tmpDir . '/config/' . $name . '.php';
            if (is_file($configFile)) {
                unlink($configFile);
            }
        }
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir . '/routes');
        rmdir($this->tmpDir);
    }

    private function writeConfig(string $name, string $array): void
    {
        file_put_contents($this->tmpDir . '/config/' . $name . '.php', '<?php return ' . $array . ';');
    }

    public function testResolvesASignerBuiltFromTheConfig(): void
    {
        $this->writeConfig('signing', "['key' => '" . self::KEY . "']");

        $signer = new Application($this->tmpDir)->container->get(Signer::class);

        $this->assertInstanceOf(Signer::class, $signer);
        $this->assertSame('value', new Signer(self::KEY)->verify($signer->sign('value')));
    }

    public function testResolvesTheSameSignerEveryTime(): void
    {
        $this->writeConfig('signing', "['key' => '" . self::KEY . "']");

        $container = new Application($this->tmpDir)->container;

        $this->assertSame($container->get(Signer::class), $container->get(Signer::class));
    }

    public function testSignerUsesEveryPreviousKeyFromTheConfig(): void
    {
        $this->writeConfig('signing', "['key' => '" . self::KEY . "', 'previous_keys' => ['" . Signer::generateKey() . "', '" . self::OTHER_KEY . "']]");
        $oldest = new Signer(self::OTHER_KEY)->sign('oldest');

        $signer = new Application($this->tmpDir)->container->get(Signer::class);

        $this->assertInstanceOf(Signer::class, $signer);
        $this->assertSame('oldest', $signer->verify($oldest));
    }

    public function testSignerHasNoPreviousKeysWhenTheyAreNull(): void
    {
        $this->writeConfig('signing', "['key' => '" . self::KEY . "', 'previous_keys' => null]");
        $old = new Signer(self::OTHER_KEY)->sign('old');

        $signer = new Application($this->tmpDir)->container->get(Signer::class);

        $this->assertInstanceOf(Signer::class, $signer);
        $this->assertSame(['keys' => '1 key(s), hidden'], $signer->__debugInfo());
        $this->assertFalse($signer->verify($old));
    }

    public function testSignerDoesNotUseTheEncryptionKey(): void
    {
        $this->writeConfig('encryption', "['key' => '" . self::KEY . "']");
        $container = new Application($this->tmpDir)->container;

        $this->assertInstanceOf(Encryptor::class, $container->get(Encryptor::class));
        $this->expectException(EncryptionConfigException::class);
        $this->expectExceptionMessageIsOrContains('The signing key is not configured.');

        $container->get(Signer::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingKeyConfigProvider(): array
    {
        return [
            'no signing config'       => [''],
            'key not written'         => ["['previous_keys' => []]"],
            'key is null'             => ["['key' => null]"],
            'key is the empty string' => ["['key' => '']"],
        ];
    }

    #[DataProvider('missingKeyConfigProvider')]
    public function testBootsWithoutAKey(string $config): void
    {
        if ($config !== '') {
            $this->writeConfig('signing', $config);
        }

        $app = new Application($this->tmpDir);

        $this->assertInstanceOf(Application::class, $app);
    }

    #[DataProvider('missingKeyConfigProvider')]
    public function testResolvingTheSignerWithoutAKeyThrows(string $config): void
    {
        if ($config !== '') {
            $this->writeConfig('signing', $config);
        }
        $container = new Application($this->tmpDir)->container;

        $this->expectException(EncryptionConfigException::class);
        $this->expectExceptionMessageIsOrContains('The signing key is not configured. Set signing.key to 64 hexadecimal characters, for example the output of Signer::generateKey().');

        $container->get(Signer::class);
    }

    public function testBootsWithoutAConfigDirectory(): void
    {
        rmdir($this->tmpDir . '/config');
        $container = new Application($this->tmpDir)->container;
        mkdir($this->tmpDir . '/config');

        $this->expectException(EncryptionConfigException::class);

        $container->get(Signer::class);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedConfigProvider(): array
    {
        $short = substr(self::KEY, 0, 63);

        return [
            'key too short'                  => ["['key' => '" . $short . "']", 'The signing key must be 64 hexadecimal characters (32 bytes).'],
            'key not hex'                    => ["['key' => '" . substr(self::KEY, 0, 63) . "g']", 'The signing key must be 64 hexadecimal characters (32 bytes).'],
            'key not a string'               => ["['key' => 123]", 'signing.key must be a string of 64 hexadecimal characters.'],
            'previous keys not a list'       => ["['key' => '" . self::KEY . "', 'previous_keys' => '" . self::OTHER_KEY . "']", 'signing.previous_keys must be a list of keys.'],
            'previous keys with string keys' => ["['key' => '" . self::KEY . "', 'previous_keys' => ['old' => '" . self::OTHER_KEY . "']]", 'signing.previous_keys must be a list of keys.'],
            'previous key too short'         => ["['key' => '" . self::KEY . "', 'previous_keys' => ['" . self::OTHER_KEY . "', '" . $short . "']]", 'Previous signing key #1 must be 64 hexadecimal characters (32 bytes).'],
            'previous key not a string'      => ["['key' => '" . self::KEY . "', 'previous_keys' => [123]]", 'Previous signing key #0 must be 64 hexadecimal characters (32 bytes).'],
            'previous key malformed, no key' => ["['previous_keys' => ['" . $short . "']]", 'Previous signing key #0 must be 64 hexadecimal characters (32 bytes).'],
            'unknown key'                    => ["['key' => '" . self::KEY . "', 'cipher' => 'aes-256-gcm']", 'signing has an unsupported key "cipher".'],
            'unknown key, no key'            => ["['previous_key' => []]", 'signing has an unsupported key "previous_key".'],
        ];
    }

    #[DataProvider('malformedConfigProvider')]
    public function testBootFailsForAMalformedSigningConfig(string $config, string $message): void
    {
        $this->writeConfig('signing', $config);

        $this->expectException(EncryptionConfigException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new Application($this->tmpDir);
    }
}
