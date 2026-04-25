<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Sloop\Database\ValidatedConfig;

final class ValidatedConfigTest extends TestCase
{
    public function testStoresAllFields(): void
    {
        $config = new ValidatedConfig(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            database: 'app',
            username: 'user',
            password: 'pass',
            charset: 'utf8mb4',
            collation: 'utf8mb4_unicode_ci',
            connectTimeoutSeconds: 5,
            options: [PDO::ATTR_PERSISTENT => true],
        );

        $this->assertSame('mysql', $config->driver);
        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('app', $config->database);
        $this->assertSame('user', $config->username);
        $this->assertSame('pass', $config->password);
        $this->assertSame('utf8mb4', $config->charset);
        $this->assertSame('utf8mb4_unicode_ci', $config->collation);
        $this->assertSame(5, $config->connectTimeoutSeconds);
        $this->assertSame([PDO::ATTR_PERSISTENT => true], $config->options);
    }

    public function testStoresNullableFieldsAsNull(): void
    {
        $config = new ValidatedConfig(
            driver: 'mysql',
            host: 'localhost',
            port: null,
            database: 'app',
            username: null,
            password: null,
            charset: null,
            collation: null,
            connectTimeoutSeconds: null,
            options: [],
        );

        $this->assertNull($config->port);
        $this->assertNull($config->username);
        $this->assertNull($config->password);
        $this->assertNull($config->charset);
        $this->assertNull($config->collation);
        $this->assertNull($config->connectTimeoutSeconds);
        $this->assertSame([], $config->options);
    }
}
