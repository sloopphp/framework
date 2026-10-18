<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Config\Config;
use Sloop\Encryption\Cipher;
use Sloop\Encryption\Encryptor;
use Sloop\Encryption\Exception\InvalidConfigException;
use Sloop\Foundation\Application;
use Sloop\Foundation\Path;
use Sloop\Log\Log;
use Sloop\Validation\ValidationMessages;

final class ApplicationEncryptionTest extends TestCase
{
    private const string KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    private const string OTHER_KEY = 'f0f1f2f3f4f5f6f7f8f9fafbfcfdfeff000102030405060708090a0b0c0d0e0f';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_app_encryption_test_' . bin2hex(random_bytes(8));
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

        $configFile = $this->tmpDir . '/config/encryption.php';
        if (is_file($configFile)) {
            unlink($configFile);
        }
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir . '/routes');
        rmdir($this->tmpDir);
    }

    private function writeEncryptionConfig(string $array): void
    {
        file_put_contents($this->tmpDir . '/config/encryption.php', '<?php return ' . $array . ';');
    }

    public function testResolvesAnEncryptorBuiltFromTheConfig(): void
    {
        $this->writeEncryptionConfig("['key' => '" . self::KEY . "']");

        $encryptor = new Application($this->tmpDir)->container->get(Encryptor::class);

        $this->assertInstanceOf(Encryptor::class, $encryptor);
        $this->assertSame('value', new Encryptor(self::KEY)->decrypt($encryptor->encrypt('value')));
    }

    public function testResolvesTheSameEncryptorEveryTime(): void
    {
        $this->writeEncryptionConfig("['key' => '" . self::KEY . "']");

        $container = new Application($this->tmpDir)->container;

        $this->assertSame($container->get(Encryptor::class), $container->get(Encryptor::class));
    }

    public function testEncryptorUsesThePreviousKeysFromTheConfig(): void
    {
        $this->writeEncryptionConfig("['key' => '" . self::KEY . "', 'previous_keys' => ['" . self::OTHER_KEY . "']]");
        $old = new Encryptor(self::OTHER_KEY)->encrypt('old');

        $encryptor = new Application($this->tmpDir)->container->get(Encryptor::class);

        $this->assertInstanceOf(Encryptor::class, $encryptor);
        $this->assertSame('old', $encryptor->decrypt($old));
    }

    public function testEncryptorUsesEveryPreviousKeyFromTheConfig(): void
    {
        $this->writeEncryptionConfig("['key' => '" . self::KEY . "', 'previous_keys' => ['" . Encryptor::generateKey() . "', '" . self::OTHER_KEY . "']]");
        $oldest = new Encryptor(self::OTHER_KEY)->encrypt('oldest');

        $encryptor = new Application($this->tmpDir)->container->get(Encryptor::class);

        $this->assertInstanceOf(Encryptor::class, $encryptor);
        $this->assertSame('oldest', $encryptor->decrypt($oldest));
    }

    public function testEncryptorUsesTheCipherFromTheConfig(): void
    {
        $this->writeEncryptionConfig("['key' => '" . self::KEY . "', 'cipher' => 'aes-256-cbc']");

        $encryptor = new Application($this->tmpDir)->container->get(Encryptor::class);

        $this->assertInstanceOf(Encryptor::class, $encryptor);
        $this->assertStringStartsWith(Cipher::Aes256Cbc->prefix(), $encryptor->encrypt('value'));
    }

    public function testEncryptorUsesGcmWhenTheCipherIsNotWritten(): void
    {
        $this->writeEncryptionConfig("['key' => '" . self::KEY . "', 'cipher' => null]");

        $encryptor = new Application($this->tmpDir)->container->get(Encryptor::class);

        $this->assertInstanceOf(Encryptor::class, $encryptor);
        $this->assertStringStartsWith(Cipher::Aes256Gcm->prefix(), $encryptor->encrypt('value'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingKeyConfigProvider(): array
    {
        return [
            'no encryption config'     => [''],
            'key not written'          => ["['cipher' => 'aes-256-gcm']"],
            'key is null'              => ["['key' => null]"],
            'key is the empty string'  => ["['key' => '']"],
        ];
    }

    #[DataProvider('missingKeyConfigProvider')]
    public function testBootsWithoutAKey(string $config): void
    {
        if ($config !== '') {
            $this->writeEncryptionConfig($config);
        }

        $app = new Application($this->tmpDir);

        $this->assertInstanceOf(Application::class, $app);
    }

    #[DataProvider('missingKeyConfigProvider')]
    public function testResolvingTheEncryptorWithoutAKeyThrows(string $config): void
    {
        if ($config !== '') {
            $this->writeEncryptionConfig($config);
        }
        $container = new Application($this->tmpDir)->container;

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains('The encryption key is not configured.');

        $container->get(Encryptor::class);
    }

    public function testBootsWithoutAConfigDirectory(): void
    {
        rmdir($this->tmpDir . '/config');
        $container = new Application($this->tmpDir)->container;
        mkdir($this->tmpDir . '/config');

        $this->expectException(InvalidConfigException::class);

        $container->get(Encryptor::class);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedConfigProvider(): array
    {
        $short = substr(self::KEY, 0, 63);

        return [
            'key too short'                  => ["['key' => '" . $short . "']", 'The encryption key must be 64 hexadecimal characters (32 bytes).'],
            'key not hex'                    => ["['key' => '" . substr(self::KEY, 0, 63) . "g']", 'The encryption key must be 64 hexadecimal characters (32 bytes).'],
            'key not a string'               => ["['key' => 123]", 'encryption.key must be a string of 64 hexadecimal characters.'],
            'previous keys not a list'       => ["['key' => '" . self::KEY . "', 'previous_keys' => '" . self::OTHER_KEY . "']", 'encryption.previous_keys must be a list of keys.'],
            'previous keys with string keys' => ["['key' => '" . self::KEY . "', 'previous_keys' => ['old' => '" . self::OTHER_KEY . "']]", 'encryption.previous_keys must be a list of keys.'],
            'previous key too short'         => ["['key' => '" . self::KEY . "', 'previous_keys' => ['" . self::OTHER_KEY . "', '" . $short . "']]", 'Previous encryption key #1 must be 64 hexadecimal characters (32 bytes).'],
            'previous key not a string'      => ["['key' => '" . self::KEY . "', 'previous_keys' => [123]]", 'Previous encryption key #0 must be 64 hexadecimal characters (32 bytes).'],
            'previous key malformed, no key' => ["['previous_keys' => ['" . $short . "']]", 'Previous encryption key #0 must be 64 hexadecimal characters (32 bytes).'],
            'unknown cipher'                 => ["['key' => '" . self::KEY . "', 'cipher' => 'aes-128-cbc']", 'encryption.cipher must be one of: aes-256-gcm, aes-256-cbc.'],
            'unknown cipher, no key'         => ["['cipher' => 'AES-256-GCM']", 'encryption.cipher must be one of: aes-256-gcm, aes-256-cbc.'],
            'cipher not a string'            => ["['key' => '" . self::KEY . "', 'cipher' => 1]", 'encryption.cipher must be one of: aes-256-gcm, aes-256-cbc.'],
        ];
    }

    #[DataProvider('malformedConfigProvider')]
    public function testBootFailsForAMalformedEncryptionConfig(string $config, string $message): void
    {
        $this->writeEncryptionConfig($config);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new Application($this->tmpDir);
    }

}
