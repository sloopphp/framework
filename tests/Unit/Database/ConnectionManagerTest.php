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

        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Database connection [master] is not defined.',
                $e->getMessage(),
            );
        }
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

        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Database connection [analytics] is not defined.',
                $e->getMessage(),
            );
        }
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

        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: missing required config key "host".',
                $e->getMessage(),
            );
        }
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

        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                "Connection [master]: unsupported driver \"sqlite\". Only 'mysql' is supported.",
                $e->getMessage(),
            );
        }
    }

    public function testConnectionRejectsUnknownConfigKey(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver'   => 'mysql',
                    'host'     => 'localhost',
                    'database' => 'app',
                    'read'     => [['host' => 'replica.internal']],
                ],
            ],
        );

        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: unsupported config key "read".',
                $e->getMessage(),
            );
        }
    }
}
