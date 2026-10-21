<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Config\InvalidConfigException;
use Sloop\Database\Exception\DatabaseConfigException;
use Sloop\Encryption\Exception\EncryptionConfigException;
use Sloop\Log\Exception\LogConfigException;
use Sloop\Security\Exception\SecurityConfigException;

final class InvalidConfigExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<InvalidConfigException>}>
     */
    public static function areaExceptionProvider(): iterable
    {
        yield 'database'   => [DatabaseConfigException::class];
        yield 'encryption' => [EncryptionConfigException::class];
        yield 'log'        => [LogConfigException::class];
        yield 'security'   => [SecurityConfigException::class];
    }

    public function testIsRuntimeException(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new InvalidConfigException('test'));
    }

    /**
     * @param class-string<InvalidConfigException> $class
     */
    #[DataProvider('areaExceptionProvider')]
    public function testEachAreaExceptionIsCaughtAsInvalidConfigException(string $class): void
    {
        $this->assertInstanceOf(InvalidConfigException::class, new $class('test'));
    }
}
