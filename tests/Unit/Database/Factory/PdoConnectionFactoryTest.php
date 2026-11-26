<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Factory;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Factory\PdoConnectionFactory;
use Sloop\Tests\Support\ThrowsAssertions;

final class PdoConnectionFactoryTest extends TestCase
{
    use ThrowsAssertions;

    /**
     * @return iterable<string, array{bool}>
     */
    public static function persistentProvider(): iterable
    {
        yield 'not persistent' => [false];
        yield 'persistent' => [true];
    }

    #[DataProvider('persistentProvider')]
    public function testAConnectionThatCannotBeReachedIsReportedUnderThePoolName(bool $persistent): void
    {
        $config = new ValidatedConfig(
            driver: 'mysql',
            host: '127.0.0.1',
            port: 1,
            database: 'nope',
            username: 'nope',
            password: 'nope',
            charset: null,
            collation: null,
            connectTimeoutSeconds: 1,
            options: [],
        );

        $e = $this->assertThrows(
            DatabaseConnectionException::class,
            static fn (): mixed => new PdoConnectionFactory()->make($config, 'pool_name', $persistent),
        );

        $this->assertSame(DatabaseConnectionException::class, $e::class);
        $this->assertSame('pool_name', $e->connectionName);
        $this->assertSame(2002, $e->driverCode);
    }
}
