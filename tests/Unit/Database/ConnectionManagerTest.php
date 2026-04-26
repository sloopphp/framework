<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\ConnectionFactory;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\ValidatedConfig;
use Sloop\Tests\Unit\Database\Stub\AlwaysFailConnectionFactory;

final class ConnectionManagerTest extends TestCase
{
    private AlwaysFailConnectionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AlwaysFailConnectionFactory();
    }

    public function testConnectionFailsWhenDefaultNameIsNotDefined(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [],
            factory: $this->factory,
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
            factory: $this->factory,
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
            factory: $this->factory,
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
            factory: $this->factory,
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
                    'driver'           => 'mysql',
                    'host'             => 'localhost',
                    'database'         => 'app',
                    'query_timeout_ms' => 5000,
                ],
            ],
            factory: $this->factory,
        );

        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: unsupported config key "query_timeout_ms".',
                $e->getMessage(),
            );
        }
    }

    public function testConnectionPropagatesPoolStructureValidationError(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver'   => 'mysql',
                    'host'     => 'localhost',
                    'database' => 'app',
                    'read'     => [
                        ['host' => 'replica.internal', 'health_check' => false],
                    ],
                ],
            ],
            factory: $this->factory,
        );

        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: "read[0]" has unsupported key "health_check". Pool-level keys must be set on the pool itself, not inside read[].',
                $e->getMessage(),
            );
        }
    }

    public function testConnectionPropagatesFactoryExceptions(): void
    {
        $throwingFactory = new class () implements ConnectionFactory {
            public function make(ValidatedConfig $config, string $name): Connection
            {
                throw new DatabaseConnectionException('simulated connect failure');
            }
        };

        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver'   => 'mysql',
                    'host'     => 'localhost',
                    'database' => 'app',
                ],
            ],
            factory: $throwingFactory,
        );

        try {
            $manager->connection();
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException $e) {
            $this->assertSame('simulated connect failure', $e->getMessage());
        }
    }
}
