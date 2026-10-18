<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Config\Config;
use Sloop\Encryption\Exception\InvalidConfigException;
use Sloop\Encryption\PasswordAlgorithm;
use Sloop\Encryption\PasswordHasher;
use Sloop\Foundation\Application;
use Sloop\Foundation\Path;
use Sloop\Log\Log;
use Sloop\Validation\ValidationMessages;

final class ApplicationHashingTest extends TestCase
{
    private string $tmpDir;

    private function writeHashingConfig(string $array): void
    {
        file_put_contents($this->tmpDir . '/config/hashing.php', '<?php return ' . $array . ';');
    }

    private function resolveHasher(): PasswordHasher
    {
        $hasher = new Application($this->tmpDir)->container->get(PasswordHasher::class);
        $this->assertInstanceOf(PasswordHasher::class, $hasher);

        return $hasher;
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_app_hashing_test_' . bin2hex(random_bytes(8));
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

        $configFile = $this->tmpDir . '/config/hashing.php';
        if (is_file($configFile)) {
            unlink($configFile);
        }
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir . '/routes');
        rmdir($this->tmpDir);
    }

    public function testResolvesABcryptHasherWithPhpsDefaultsWithoutAConfigFile(): void
    {
        $hasher = $this->resolveHasher();

        $this->assertSame(PasswordAlgorithm::Bcrypt, $hasher->algorithm);
        $this->assertSame(12, $hasher->bcryptCost);
        $this->assertSame(65536, $hasher->argon2Memory);
        $this->assertSame(4, $hasher->argon2Time);
        $this->assertSame(1, $hasher->argon2Threads);
    }

    public function testResolvesTheSameHasherEveryTime(): void
    {
        $container = new Application($this->tmpDir)->container;

        $this->assertSame($container->get(PasswordHasher::class), $container->get(PasswordHasher::class));
    }

    public function testHasherUsesTheSettingsFromTheConfig(): void
    {
        $this->writeHashingConfig("['algorithm' => 'argon2id', 'bcrypt' => ['cost' => 5], 'argon2id' => ['memory' => 16, 'time' => 2, 'threads' => 2]]");

        $hasher = $this->resolveHasher();

        $this->assertSame(PasswordAlgorithm::Argon2id, $hasher->algorithm);
        $this->assertSame(5, $hasher->bcryptCost);
        $this->assertSame(16, $hasher->argon2Memory);
        $this->assertSame(2, $hasher->argon2Time);
        $this->assertSame(2, $hasher->argon2Threads);
    }

    public function testHasherAcceptsTheAlgorithmNameInAnyCase(): void
    {
        $this->writeHashingConfig("['algorithm' => 'Argon2ID']");

        $this->assertSame(PasswordAlgorithm::Argon2id, $this->resolveHasher()->algorithm);
    }

    public function testHasherUsesTheDefaultsForNullEntries(): void
    {
        $this->writeHashingConfig("['algorithm' => null, 'bcrypt' => ['cost' => null], 'argon2id' => ['memory' => null, 'time' => null, 'threads' => null]]");

        $hasher = $this->resolveHasher();

        $this->assertSame(PasswordAlgorithm::Bcrypt, $hasher->algorithm);
        $this->assertSame(12, $hasher->bcryptCost);
        $this->assertSame(65536, $hasher->argon2Memory);
        $this->assertSame(4, $hasher->argon2Time);
        $this->assertSame(1, $hasher->argon2Threads);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedConfigProvider(): array
    {
        return [
            'unknown algorithm'        => ["['algorithm' => 'argon2i']", 'hashing.algorithm must be one of: bcrypt, argon2id.'],
            'algorithm not a string'   => ["['algorithm' => 1]", 'hashing.algorithm must be one of: bcrypt, argon2id.'],
            'cost as a string'         => ["['bcrypt' => ['cost' => '12']]", 'hashing.bcrypt.cost must be an integer.'],
            'memory as a float'        => ["['argon2id' => ['memory' => 65536.0]]", 'hashing.argon2id.memory must be an integer.'],
            'time as a bool'           => ["['argon2id' => ['time' => true]]", 'hashing.argon2id.time must be an integer.'],
            'threads as an array'      => ["['argon2id' => ['threads' => [1]]]", 'hashing.argon2id.threads must be an integer.'],
            'cost out of range'        => ["['bcrypt' => ['cost' => 3]]", 'The bcrypt cost must be between 4 and 31, got 3.'],
            'threads out of range'     => ["['argon2id' => ['threads' => 0]]", 'The argon2id thread count must be between 1 and 16777215, got 0.'],
            'argon2id unused but bad'  => ["['algorithm' => 'bcrypt', 'argon2id' => ['time' => 0]]", 'The argon2id time cost must be at least 1, got 0.'],
        ];
    }

    #[DataProvider('malformedConfigProvider')]
    public function testBootFailsForAMalformedHashingConfig(string $config, string $message): void
    {
        $this->writeHashingConfig($config);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new Application($this->tmpDir);
    }
}
