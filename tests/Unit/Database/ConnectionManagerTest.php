<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Exception\InvalidConfigException;

final class ConnectionManagerTest extends TestCase
{
    public function testConnectionFailsWhenDefaultNameIsNotDefined(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [],
        );

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Database connection [master] is not defined.');

        $manager->connection();
    }

    public function testConnectionFailsWhenDefaultNameDiffersFromAvailableConfig(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'analytics',
            configs: [
                'master' => [
                    'driver'   => 'mysql',
                    'host'     => 'localhost',
                    'database' => 'app',
                ],
            ],
        );

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Database connection [analytics] is not defined.');

        $manager->connection();
    }

    public function testConnectionPropagatesValidationErrorsFromResolver(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver' => 'mysql',
                    // host and database missing
                ],
            ],
        );

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Connection [master]: missing required config key "host".');

        $manager->connection();
    }

    public function testConnectionRejectsUnsupportedDriver(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver'   => 'sqlite',
                    'host'     => 'localhost',
                    'database' => 'app',
                ],
            ],
        );

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("Connection [master]: unsupported driver \"sqlite\". Only 'mysql' is supported.");

        $manager->connection();
    }

    public function testConnectionRejectsUnknownConfigKey(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver'    => 'mysql',
                    'host'      => 'localhost',
                    'database'  => 'app',
                    'read'      => [['host' => 'replica.internal']],
                ],
            ],
        );

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Connection [master]: unsupported config key "read".');

        $manager->connection();
    }
}
