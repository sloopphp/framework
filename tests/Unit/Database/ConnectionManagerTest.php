<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Connection;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\Factory\ConnectionFactory;
use Sloop\Database\Replica\InMemoryDeadReplicaCache;
use Sloop\Tests\Unit\Database\Stub\AlwaysFailConnectionFactory;
use Sloop\Tests\Unit\Database\Stub\FixedReplicaSelector;
use Sloop\Tests\Unit\Database\Stub\ScriptedConnectionFactory;

final class ConnectionManagerTest extends TestCase
{
    private FixedReplicaSelector $selector;

    private InMemoryDeadReplicaCache $deadCache;

    protected function setUp(): void
    {
        $this->selector  = new FixedReplicaSelector();
        $this->deadCache = new InMemoryDeadReplicaCache();
    }

    /**
     * @param array<string, array<string, mixed>> $configs
     */
    private function manager(string $defaultName, array $configs, ConnectionFactory $factory): ConnectionManager
    {
        return new ConnectionManager(
            defaultName: $defaultName,
            configs: $configs,
            factory: $factory,
            replicaSelector: $this->selector,
            deadCache: $this->deadCache,
        );
    }

    private function realConnection(): Connection
    {
        return new Connection(new PDO('sqlite::memory:'), 'test');
    }

    // -------------------------------------------------------
    // pool resolution / config validation
    // -------------------------------------------------------

    public function testConnectionFailsWhenDefaultNameIsNotDefined(): void
    {
        $manager = $this->manager('master', [], new AlwaysFailConnectionFactory());

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
        $manager = $this->manager('analytics', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
            ],
        ], new AlwaysFailConnectionFactory());

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
        $manager = $this->manager('master', [
            'master' => [
                'driver' => 'mysql',
                // host and database missing
            ],
        ], new AlwaysFailConnectionFactory());

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
        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'sqlite',
                'host'     => 'localhost',
                'database' => 'app',
            ],
        ], new AlwaysFailConnectionFactory());

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
        $manager = $this->manager('master', [
            'master' => [
                'driver'           => 'mysql',
                'host'             => 'localhost',
                'database'         => 'app',
                'query_timeout_ms' => 5000,
            ],
        ], new AlwaysFailConnectionFactory());

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
        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
                'read'     => [
                    ['host' => 'replica.internal', 'health_check' => false],
                ],
            ],
        ], new AlwaysFailConnectionFactory());

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

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
            ],
        ], $throwingFactory);

        try {
            $manager->connection();
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException $e) {
            $this->assertSame('simulated connect failure', $e->getMessage());
        }
    }

    // -------------------------------------------------------
    // primary routing (writable: null / true)
    // -------------------------------------------------------

    public function testConnectionReturnsPrimaryWhenWritableIsNull(): void
    {
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
            ],
        ], $factory);

        $this->assertSame($primary, $manager->connection());
        $this->assertSame(['primary.internal:0'], $factory->invocations);
    }

    public function testConnectionReturnsPrimaryWhenWritableIsTrue(): void
    {
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $this->assertSame($primary, $manager->connection(writable: true));
        $this->assertSame(['primary.internal:0'], $factory->invocations);
    }

    public function testConnectionReusesPrimaryAcrossCalls(): void
    {
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
            ],
        ], $factory);

        $first  = $manager->connection();
        $second = $manager->connection(writable: true);

        $this->assertSame($first, $second);
        $this->assertCount(1, $factory->invocations);
    }

    // -------------------------------------------------------
    // replica routing (writable: false)
    // -------------------------------------------------------

    public function testReplicaRouteFallsBackToPrimaryWhenReadIsEmpty(): void
    {
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
            ],
        ], $factory);

        $this->assertSame($primary, $manager->connection(writable: false));
    }

    public function testReplicaRouteReturnsHealthyReplicaWithoutPing(): void
    {
        $replica = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [['host' => 'replica.internal']],
                'health_check' => false,
            ],
        ], $factory);

        $this->assertSame($replica, $manager->connection(writable: false));
        $this->assertSame(['replica.internal:0'], $factory->invocations);
    }

    public function testReplicaRouteSkipsReplicaMarkedDeadInCache(): void
    {
        $replica2 = $this->realConnection();
        $factory  = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica2.internal', 0, $replica2);

        $this->deadCache->markServerDead('replica1.internal', 0, 300);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [
                    ['host' => 'replica1.internal'],
                    ['host' => 'replica2.internal'],
                ],
                'health_check' => false,
            ],
        ], $factory);

        $this->assertSame($replica2, $manager->connection(writable: false));
        $this->assertSame(['replica2.internal:0'], $factory->invocations);
    }

    public function testReplicaRouteFallsThroughToNextReplicaOnConnectFailure(): void
    {
        $replica2 = $this->realConnection();
        $factory  = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica1.internal',
            0,
            new DatabaseConnectionException('refused', 'replica1', null, 2002),
        );
        $factory->expectSuccess('replica2.internal', 0, $replica2);

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [
                    ['host' => 'replica1.internal'],
                    ['host' => 'replica2.internal'],
                ],
                'health_check'            => false,
                'max_connection_attempts' => 5,
            ],
        ], $factory);

        $this->assertSame($replica2, $manager->connection(writable: false));
        $this->assertSame(
            ['replica1.internal:0', 'replica2.internal:0'],
            $factory->invocations,
        );
    }

    public function testReplicaRouteFallsBackToPrimaryWhenAllReplicasFail(): void
    {
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica1.internal',
            0,
            new DatabaseConnectionException('refused', 'replica1', null, 2002),
        );
        $factory->expectFailure(
            'replica2.internal',
            0,
            new DatabaseConnectionException('refused', 'replica2', null, 2002),
        );
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [
                    ['host' => 'replica1.internal'],
                    ['host' => 'replica2.internal'],
                ],
                'health_check'            => false,
                'max_connection_attempts' => 5,
            ],
        ], $factory);

        $this->assertSame($primary, $manager->connection(writable: false));
        $this->assertSame(
            ['replica1.internal:0', 'replica2.internal:0', 'primary.internal:0'],
            $factory->invocations,
        );
    }

    public function testReplicaRouteThrowsWhenAllReplicasAndPrimaryFail(): void
    {
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica.internal',
            0,
            new DatabaseConnectionException('refused', 'replica', null, 2002),
        );
        $factory->expectFailure(
            'primary.internal',
            0,
            new DatabaseConnectionException('refused', 'primary', null, 2002),
        );

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [['host' => 'replica.internal']],
                'health_check'            => false,
                'max_connection_attempts' => 5,
            ],
        ], $factory);

        try {
            $manager->connection(writable: false);
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException $e) {
            // port is null in ValidatedConfig → formatAttemptError renders it as "?"
            $message = $e->getMessage();
            $this->assertStringContainsString('Failed to obtain a read connection for pool [master]', $message);
            $this->assertStringContainsString('(replica + primary fallback exhausted)', $message);
            $this->assertStringContainsString('replica.internal:? → refused', $message);
            $this->assertStringContainsString('primary.internal:? → refused', $message);
        }
    }

    public function testReplicaRouteIncludesSkippedDeadCacheReplicasInErrorMessage(): void
    {
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica2.internal',
            0,
            new DatabaseConnectionException('refused', 'replica2', null, 2002),
        );
        $factory->expectFailure(
            'primary.internal',
            0,
            new DatabaseConnectionException('refused', 'primary', null, 2002),
        );

        $this->deadCache->markServerDead('replica1.internal', 0, 300);

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [
                    ['host' => 'replica1.internal'],
                    ['host' => 'replica2.internal'],
                ],
                'health_check'            => false,
                'max_connection_attempts' => 5,
            ],
        ], $factory);

        try {
            $manager->connection(writable: false);
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('replica1.internal:? → skipped (dead-cache)', $message);
            $this->assertStringContainsString('replica2.internal:? → refused', $message);
            $this->assertStringContainsString('primary.internal:? → refused', $message);
        }
    }

    public function testReplicaRoutePassesReplicaPortToDeadCache(): void
    {
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica.internal',
            3306,
            new DatabaseConnectionException('refused', 'replica', null, 2002),
        );

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [['host' => 'replica.internal', 'port' => 3306]],
                'health_check'            => false,
                'max_connection_attempts' => 1,
            ],
        ], $factory);

        try {
            $manager->connection(writable: false);
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException) {
            // empty
        }

        // port=3306 specifically: dead-cache key carries 3306, not 0.
        // Confirms that $replica->port (not the null-coalesce default 0) is
        // forwarded to the cache.
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica.internal', 0, 'master'));
    }

    public function testReplicaRouteRespectsMaxConnectionAttempts(): void
    {
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica1.internal',
            0,
            new DatabaseConnectionException('refused', 'replica1', null, 2002),
        );
        // replica2 should never be invoked because max_connection_attempts = 1.

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [
                    ['host' => 'replica1.internal'],
                    ['host' => 'replica2.internal'],
                ],
                'health_check'            => false,
                'max_connection_attempts' => 1,
            ],
        ], $factory);

        try {
            $manager->connection(writable: false);
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException) {
            // empty
        }

        $this->assertSame(['replica1.internal:0'], $factory->invocations);
    }

    public function testReplicaRouteRecordsServerDeadOnConnectFailure(): void
    {
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica.internal',
            0,
            new DatabaseConnectionException('refused', 'replica', null, 2002),
        );

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [['host' => 'replica.internal']],
                'health_check'            => false,
                'max_connection_attempts' => 1,
            ],
        ], $factory);

        try {
            $manager->connection(writable: false);
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException) {
            // empty
        }

        // server-wide dead → also dead in any other pool
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'unrelated_pool'));
    }

    public function testReplicaRouteRecordsPoolDeadOnAuthFailure(): void
    {
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica.internal',
            0,
            new DatabaseConnectionException('access denied', 'replica', '28000', 1045),
        );

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [['host' => 'replica.internal']],
                'health_check'            => false,
                'max_connection_attempts' => 1,
            ],
        ], $factory);

        try {
            $manager->connection(writable: false);
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException) {
            // empty
        }

        // pool-specific dead → dead for 'master', alive for other pool
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica.internal', 0, 'unrelated_pool'));
    }

    public function testReplicaRouteRecordsServerDeadOnPingFailure(): void
    {
        // Use a PDO mock that throws on exec('DO 1') so the test reproduces a
        // server-side ping failure without depending on SQLite's `DO` rejection.
        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->method('exec')
            ->with('DO 1')
            ->willThrowException(new \PDOException('ping failed'));

        $replicaConn = new Connection($pdoMock, 'replica');
        $factory     = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replicaConn);

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [['host' => 'replica.internal']],
                'health_check'            => true,
                'max_connection_attempts' => 1,
            ],
        ], $factory);

        try {
            $manager->connection(writable: false);
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException) {
            // empty
        }

        // ping failure → server-wide dead: live in master and any other pool
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'unrelated_pool'));
    }

    public function testReplicaRouteCachesSelectedReplicaAcrossCalls(): void
    {
        $replica = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [['host' => 'replica.internal']],
                'health_check' => false,
            ],
        ], $factory);

        $first  = $manager->connection(writable: false);
        $second = $manager->connection(writable: false);

        $this->assertSame($first, $second);
        $this->assertCount(1, $factory->invocations);
    }
}
