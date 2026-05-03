<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Connection;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Factory\ConnectionFactory;
use Sloop\Database\Replica\InMemoryDeadReplicaCache;
use Sloop\Tests\Support\MutableClock;
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

    // -------------------------------------------------------
    // transaction-aware routing
    // -------------------------------------------------------

    public function testConnectionRoutesToPrimaryWhilePrimaryIsInTransaction(): void
    {
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [['host' => 'replica.internal']],
                'health_check' => false,
            ],
        ], $factory);

        // Cache the primary first so transaction-aware routing has a Connection to inspect.
        $manager->connection();
        $primary->begin();

        try {
            // Even though `read` is configured, writable: false must return the
            // primary while a transaction is active so that subsequent SELECTs
            // observe the in-flight changes.
            $duringTx = $manager->connection(writable: false);

            $this->assertSame($primary, $duringTx);
            // Replica was never contacted while the transaction was active.
            $this->assertSame(['primary.internal:0'], $factory->invocations);
        } finally {
            $primary->rollback();
        }
    }

    public function testConnectionResumesReplicaRoutingAfterCommit(): void
    {
        $primary = $this->realConnection();
        $replica = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);
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

        $manager->connection();
        $primary->begin();
        $primary->commit();

        $afterTx = $manager->connection(writable: false);

        $this->assertSame($replica, $afterTx);
    }

    public function testConnectionResumesReplicaRoutingAfterRollback(): void
    {
        $primary = $this->realConnection();
        $replica = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);
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

        $manager->connection();
        $primary->begin();
        $primary->rollback();

        $afterTx = $manager->connection(writable: false);

        $this->assertSame($replica, $afterTx);
    }

    // -------------------------------------------------------
    // probeReplicas
    // -------------------------------------------------------

    public function testProbeReplicasReturnsEmptyMapWhenPoolHasNoReplicas(): void
    {
        $factory = new ScriptedConnectionFactory();

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
            ],
        ], $factory);

        $this->assertSame([], $manager->probeReplicas());
        $this->assertSame([], $factory->invocations);
    }

    public function testProbeReplicasReturnsAllHealthyWhenAllConnectAndPing(): void
    {
        $replica1 = $this->pingableConnection();
        $replica2 = $this->pingableConnection();
        $factory  = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica1.internal', 0, $replica1);
        $factory->expectSuccess('replica2.internal', 0, $replica2);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [
                    ['host' => 'replica1.internal'],
                    ['host' => 'replica2.internal'],
                ],
            ],
        ], $factory);

        $this->assertSame(
            ['replica1.internal:0' => true, 'replica2.internal:0' => true],
            $manager->probeReplicas(),
        );
        $this->assertSame(
            ['replica1.internal:0', 'replica2.internal:0'],
            $factory->invocations,
        );
        $this->assertFalse($this->deadCache->isDead('replica1.internal', 0, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica2.internal', 0, 'master'));
    }

    public function testProbeReplicasUsesDeclaredPortInResultKey(): void
    {
        $replica = $this->pingableConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 3306, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [['host' => 'replica.internal', 'port' => 3306]],
            ],
        ], $factory);

        $this->assertSame(['replica.internal:3306' => true], $manager->probeReplicas());
    }

    public function testProbeReplicasMarksServerDeadOnConnectFailure(): void
    {
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica.internal',
            0,
            new DatabaseConnectionException('refused', 'replica', null, 2002),
        );

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $this->assertSame(['replica.internal:0' => false], $manager->probeReplicas());
        // server-wide dead → also dead in any other pool
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'unrelated_pool'));
    }

    public function testProbeReplicasMarksPoolDeadOnAuthFailure(): void
    {
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica.internal',
            0,
            new DatabaseConnectionException('access denied', 'replica', '28000', 1045),
        );

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $this->assertSame(['replica.internal:0' => false], $manager->probeReplicas());
        // pool-specific dead → dead for 'master' only, alive elsewhere
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica.internal', 0, 'unrelated_pool'));
    }

    public function testProbeReplicasMarksServerDeadOnPingFailure(): void
    {
        // PDO::exec('DO 1') throws → ping() raises DatabaseException, which the
        // probe must record as a server-wide failure (matches request-time semantics).
        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->method('exec')
            ->with('DO 1')
            ->willThrowException(new \PDOException('ping failed'));

        $replicaConn = new Connection($pdoMock, 'replica');
        $factory     = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replicaConn);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $this->assertSame(['replica.internal:0' => false], $manager->probeReplicas());
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica.internal', 0, 'unrelated_pool'));
    }

    public function testProbeReplicasReturnsMixedHealthMap(): void
    {
        $replica2 = $this->pingableConnection();
        $factory  = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica1.internal',
            0,
            new DatabaseConnectionException('refused', 'replica1', null, 2002),
        );
        $factory->expectSuccess('replica2.internal', 0, $replica2);
        $factory->expectFailure(
            'replica3.internal',
            0,
            new DatabaseConnectionException('access denied', 'replica3', '28000', 1045),
        );

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [
                    ['host' => 'replica1.internal'],
                    ['host' => 'replica2.internal'],
                    ['host' => 'replica3.internal'],
                ],
            ],
        ], $factory);

        $this->assertSame(
            [
                'replica1.internal:0' => false,
                'replica2.internal:0' => true,
                'replica3.internal:0' => false,
            ],
            $manager->probeReplicas(),
        );
        // Failed replicas marked, healthy replica untouched.
        $this->assertTrue($this->deadCache->isDead('replica1.internal', 0, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica2.internal', 0, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica3.internal', 0, 'master'));
        // replica1 went to the shared key (server-wide), replica3 only to the pool key.
        $this->assertTrue($this->deadCache->isDead('replica1.internal', 0, 'unrelated_pool'));
        $this->assertFalse($this->deadCache->isDead('replica3.internal', 0, 'unrelated_pool'));
    }

    public function testProbeReplicasRefreshesDeadMarkTtlOnRepeatedFailure(): void
    {
        // Use a controllable clock so the assertion proves the dead mark was
        // re-stamped by the probe rather than just lingering from the pre-mark.
        $clock     = new MutableClock(1000);
        $deadCache = new InMemoryDeadReplicaCache($clock(...));

        // Pre-mark with a short TTL that would naturally expire at 1010.
        $deadCache->markServerDead('replica.internal', 0, 10);

        // Advance past the original expiry; sanity-check the pre-mark is gone.
        $clock->now = 1100;
        $this->assertFalse($deadCache->isDead('replica.internal', 0, 'master'));

        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica.internal',
            0,
            new DatabaseConnectionException('refused', 'replica', null, 2002),
        );

        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver'                 => 'mysql',
                    'host'                   => 'primary.internal',
                    'database'               => 'app',
                    'read'                   => [['host' => 'replica.internal']],
                    'dead_cache_ttl_seconds' => 60,
                ],
            ],
            factory: $factory,
            replicaSelector: $this->selector,
            deadCache: $deadCache,
        );

        $this->assertSame(['replica.internal:0' => false], $manager->probeReplicas());

        // The new dead mark stamped at clock=1100 with TTL=60 expires at 1160.
        // Still dead between the original 1010 expiry and the new 1160 expiry,
        // and gone again past 1160. This proves a fresh stamp, not a stale entry.
        $this->assertTrue($deadCache->isDead('replica.internal', 0, 'master'));
        $clock->now = 1150;
        $this->assertTrue($deadCache->isDead('replica.internal', 0, 'master'));
        $clock->now = 1170;
        $this->assertFalse($deadCache->isDead('replica.internal', 0, 'master'));
    }

    public function testProbeReplicasDoesNotPoisonReplicaSelectionCache(): void
    {
        // The probe must not stash its short-lived Connection in $replicaConnections;
        // a subsequent connection(writable: false) call must build a fresh Connection
        // through the factory rather than being handed the probe's instance back.
        $probeConn   = $this->pingableConnection();
        $requestConn = $this->realConnection();
        $factory     = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $probeConn);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [['host' => 'replica.internal']],
                'health_check' => false,
            ],
        ], $factory);

        $manager->probeReplicas();

        // Re-script the same host with a different Connection. If the probe had
        // cached its Connection, the request below would return $probeConn (and
        // the factory would only show one invocation total).
        $factory->expectSuccess('replica.internal', 0, $requestConn);
        $afterProbe = $manager->connection(writable: false);

        $this->assertSame($requestConn, $afterProbe);
        $this->assertSame(
            ['replica.internal:0', 'replica.internal:0'],
            $factory->invocations,
        );
    }

    public function testProbeReplicasIgnoresDeadCacheAndProbesAllReplicas(): void
    {
        // Pre-mark replica1 dead. Probe must still attempt it so operators can
        // see real-time state, and a healthy probe must NOT clear the dead mark
        // (recovery is bound by TTL, per ConnectionManager design).
        $this->deadCache->markServerDead('replica1.internal', 0, 300);

        $replica1 = $this->pingableConnection();
        $replica2 = $this->pingableConnection();
        $factory  = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica1.internal', 0, $replica1);
        $factory->expectSuccess('replica2.internal', 0, $replica2);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [
                    ['host' => 'replica1.internal'],
                    ['host' => 'replica2.internal'],
                ],
            ],
        ], $factory);

        $this->assertSame(
            ['replica1.internal:0' => true, 'replica2.internal:0' => true],
            $manager->probeReplicas(),
        );
        $this->assertSame(
            ['replica1.internal:0', 'replica2.internal:0'],
            $factory->invocations,
        );
        // Existing dead mark is preserved; recovery still depends on TTL expiry.
        $this->assertTrue($this->deadCache->isDead('replica1.internal', 0, 'master'));
    }

    public function testProbeReplicasUsesDefaultPoolWhenNameIsNull(): void
    {
        $replica = $this->pingableConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('default-replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [['host' => 'default-replica.internal']],
            ],
        ], $factory);

        $this->assertSame(
            ['default-replica.internal:0' => true],
            $manager->probeReplicas(),
        );
    }

    public function testProbeReplicasAcceptsExplicitPoolName(): void
    {
        $analyticsReplica = $this->pingableConnection();
        $factory          = new ScriptedConnectionFactory();
        $factory->expectSuccess('analytics-replica.internal', 0, $analyticsReplica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
            ],
            'analytics' => [
                'driver'   => 'mysql',
                'host'     => 'analytics-primary.internal',
                'database' => 'analytics',
                'read'     => [['host' => 'analytics-replica.internal']],
            ],
        ], $factory);

        $this->assertSame(
            ['analytics-replica.internal:0' => true],
            $manager->probeReplicas('analytics'),
        );
        // Default pool is untouched.
        $this->assertSame(['analytics-replica.internal:0'], $factory->invocations);
    }

    public function testProbeReplicasFailsWhenPoolIsUndefined(): void
    {
        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
            ],
        ], new ScriptedConnectionFactory());

        try {
            $manager->probeReplicas('analytics');
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Database connection [analytics] is not defined.',
                $e->getMessage(),
            );
        }
    }

    public function testProbeReplicasPropagatesValidationErrorsFromResolver(): void
    {
        $manager = $this->manager('master', [
            'master' => [
                'driver' => 'mysql',
                // host and database missing
            ],
        ], new ScriptedConnectionFactory());

        try {
            $manager->probeReplicas();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: missing required config key "host".',
                $e->getMessage(),
            );
        }
    }

    private function pingableConnection(): Connection
    {
        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->method('exec')
            ->with('DO 1')
            ->willReturn(0);

        return new Connection($pdoMock, 'replica');
    }

    // -------------------------------------------------------
    // logger injection
    // -------------------------------------------------------

    public function testPrimaryConnectionReceivesInjectedLogger(): void
    {
        $handler = new TestHandler();
        $logger  = new Logger('database', [$handler]);
        $primary = new Connection(new PDO('sqlite::memory:'), 'master');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver'   => 'mysql',
                    'host'     => 'primary.internal',
                    'database' => 'app',
                ],
            ],
            factory: $factory,
            replicaSelector: $this->selector,
            deadCache: $this->deadCache,
            logger: $logger,
        );

        $connection = $manager->connection();

        // Trigger an error to verify the logger is wired into the Connection.
        try {
            $connection->query('NOT VALID SQL');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // empty
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Error, $records[0]->level);
        $this->assertSame('master', $records[0]->context['connection_name']);
    }

    public function testReplicaConnectionReceivesInjectedLogger(): void
    {
        $handler = new TestHandler();
        $logger  = new Logger('database', [$handler]);
        $replica = new Connection(new PDO('sqlite::memory:'), 'replica');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver'       => 'mysql',
                    'host'         => 'primary.internal',
                    'database'     => 'app',
                    'read'         => [['host' => 'replica.internal']],
                    'health_check' => false,
                ],
            ],
            factory: $factory,
            replicaSelector: $this->selector,
            deadCache: $this->deadCache,
            logger: $logger,
        );

        $connection = $manager->connection(writable: false);

        try {
            $connection->query('NOT VALID SQL');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // empty
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('replica', $records[0]->context['connection_name']);
    }

    public function testLoggingOptionsArePropagatedFromPoolConfig(): void
    {
        $handler = new TestHandler();
        $logger  = new Logger('database', [$handler]);
        $primary = new Connection(new PDO('sqlite::memory:'), 'master');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: [
                'master' => [
                    'driver'                  => 'mysql',
                    'host'                    => 'primary.internal',
                    'database'                => 'app',
                    'log_bindings'            => false,
                    'log_all_queries'         => true,
                    'slow_query_threshold_ms' => 100,
                ],
            ],
            factory: $factory,
            replicaSelector: $this->selector,
            deadCache: $this->deadCache,
            logger: $logger,
        );

        $manager->connection()->query('SELECT 1');

        // log_all_queries=true emits a debug record; log_bindings=false redacts the bindings array.
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Debug, $records[0]->level);
        $this->assertSame('[redacted]', $records[0]->context['bindings']);
    }

    public function testNoLoggerInjectionWhenManagerHasNoLogger(): void
    {
        // Regression: existing ConnectionManager construction without a logger
        // continues to leave Connection's logger unset (no behavior change).
        $primary = new Connection(new PDO('sqlite::memory:'), 'master');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
            ],
        ], $factory);

        try {
            $manager->connection()->query('NOT VALID SQL');
        } catch (QueryException $e) {
            $this->assertSame('master', $e->connectionName);
        }
    }
}
