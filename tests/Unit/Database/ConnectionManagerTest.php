<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Connection;
use Sloop\Database\ConnectionManager;
use Sloop\Database\ConnectionRoute;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Factory\ConnectionFactory;
use Sloop\Database\FixedConnectionRoute;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Query;
use Sloop\Database\Query\Select;
use Sloop\Database\ReadConnectionRoute;
use Sloop\Database\Replica\InMemoryDeadReplicaCache;
use Sloop\Database\Replica\ReplicaSelectorRegistry;
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
            replicaSelectors: new ReplicaSelectorRegistry(['random' => $this->selector]),
            deadCache: $this->deadCache,
        );
    }

    private function realConnection(): Connection
    {
        return new Connection(new PDO('sqlite::memory:'), 'test');
    }

    private function pdoSeededWith(string $value): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE reads (v TEXT)');
        $pdo->exec("INSERT INTO reads (v) VALUES ('" . $value . "')");

        return $pdo;
    }

    private function routeBehind(Select $select): ConnectionRoute
    {
        $reflection = new ReflectionProperty(Query::class, 'route');
        $route      = $reflection->getValue($select);
        $this->assertInstanceOf(ConnectionRoute::class, $route);

        return $route;
    }

    private function connectionResolvedBy(Select $select): Connection
    {
        // Asking the route the way execute() asks it. Which route arrived is a
        // separate question, pinned by testSelectHandsTheBuilderTheReadRoute;
        // that the statement itself goes through the route rather than a
        // remembered connection is pinned by the three executes in
        // testOneBuilderAnswersFromWhicheverRouteItsTransactionStateSelects.
        return $this->routeBehind($select)->connection();
    }

    private function connectionInTransaction(): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->beginTransaction();

        return new Connection($pdo, 'test');
    }

    private function connectionFailingToRollBack(): Connection
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('rollBack')->willThrowException(new \PDOException('rollback failed'));

        return new Connection($pdo, 'test');
    }

    /**
     * @param array<string, array<string, mixed>> $configs
     */
    private function managerWithLogger(
        string $defaultName,
        array $configs,
        ConnectionFactory $factory,
        TestHandler $handler,
    ): ConnectionManager {
        return new ConnectionManager(
            defaultName: $defaultName,
            configs: $configs,
            factory: $factory,
            replicaSelectors: new ReplicaSelectorRegistry(['random' => $this->selector]),
            deadCache: $this->deadCache,
            logger: new Logger('database', [$handler]),
        );
    }

    /**
     * Build a Connection whose every query takes at least $elapsedMicroseconds.
     *
     * The slow-query branch compares real elapsed time, so a stub that returns
     * instantly cannot tell a propagated threshold from a missing one. Pair a
     * deliberately slow statement with a threshold of 1ms instead of waiting on
     * a threshold that a fast query might or might not cross.
     */
    private function slowConnection(int $elapsedMicroseconds, string $connectionName): Connection
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(
            static function () use ($elapsedMicroseconds): bool {
                usleep($elapsedMicroseconds);

                return true;
            },
        );
        $statement->method('fetchAll')->willReturn([]);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('exec')->willReturn(0);

        return new Connection($pdo, $connectionName);
    }

    // -------------------------------------------------------
    // pool resolution / config validation
    // -------------------------------------------------------

    private function pingableConnection(): Connection
    {
        $pdoMock = $this->createStub(PDO::class);
        $pdoMock->method('exec')
            ->willReturnCallback(function (string $sql): int {
                $this->assertSame('DO 1', $sql);

                return 0;
            });

        return new Connection($pdoMock, 'replica');
    }

    /**
     * Build a mock PDO with a representative MySQL VERSION() response that asserts
     * SET SESSION fires once with the expected SQL and accepts the trailing user query.
     *
     * Dialect-specific output formatting (MariaDB max_statement_time) is covered
     * by ConnectionTest's dialect provider; this helper focuses on the manager's
     * forwarding contract using a single representative dialect.
     *
     * @param  string $expectedSetSessionSql Expected SET SESSION SQL passed to exec()
     * @return PDO    Mock PDO with the exec/query/prepare expectations wired
     */
    private function primaryPdoExpectingTimeout(string $expectedSetSessionSql): PDO
    {
        $versionStmt = $this->createStub(\PDOStatement::class);
        $versionStmt->method('fetchColumn')->willReturn('8.0.37');

        $userStmt = $this->createStub(\PDOStatement::class);
        $userStmt->method('execute')->willReturn(true);
        $userStmt->method('fetchAll')->willReturn([]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function (string $sql) use ($versionStmt): PDOStatement {
            $this->assertSame('SELECT VERSION()', $sql);

            return $versionStmt;
        });
        $pdo->expects($this->once())
            ->method('exec')
            ->with($expectedSetSessionSql)
            ->willReturn(0);
        $pdo->method('prepare')->willReturn($userStmt);

        return $pdo;
    }

    public function testResolvePoolRejectsReplicaSelectorMissingFromRegistry(): void
    {
        $manager = $this->manager('master', ['master' => [
            'driver'           => 'mysql',
            'host'             => 'primary.example.com',
            'database'         => 'app',
            'replica_selector' => 'round_robin',
        ]], new AlwaysFailConnectionFactory());

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains(
            'No replica selector is registered for "round_robin". Registered: random.'
        );

        // Verify it fails before entering the read path (at pool resolution time).
        $manager->connection();
    }

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
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
                'foo'      => true,
            ],
        ], new AlwaysFailConnectionFactory());

        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: unsupported config key "foo".',
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
            public function make(ValidatedConfig $config, string $name, bool $persistent): Connection
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

    public function testPrimaryWritesTableNamesWithThePoolsPrefix(): void
    {
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'prefix'   => 'app_',
            ],
        ], $factory);

        $this->assertSame(
            'SELECT * FROM `app_users`',
            $manager->connection()->select()->from('users')->toSql(),
        );
    }

    public function testReplicaWritesTableNamesWithTheSamePrefixAsThePrimary(): void
    {
        // The prefix belongs to the pool, so a statement has to read the same
        // table whichever route it takes; a replica writing unprefixed names
        // would query a table that does not exist.
        $replica = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'prefix'       => 'app_',
                'health_check' => false,
                'read'         => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $this->assertSame(
            'SELECT * FROM `app_users`',
            $manager->connection(writable: false)->select()->from('users')->toSql(),
        );
    }

    public function testATableNameIsWrittenAsGivenWhenThePoolHasNoPrefix(): void
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

        $this->assertSame(
            'SELECT * FROM `users`',
            $manager->connection()->select()->from('users')->toSql(),
        );
    }

    public function testSelectStartsTheStatementOnTheReplica(): void
    {
        $replica = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'health_check' => false,
                'read'         => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $select = $manager->select()->from('users');

        // Building connects to nothing; the route is asked when the statement runs.
        $this->assertSame([], $factory->invocations);

        // The primary was never contacted: select() takes the read route.
        $this->assertSame($replica, $this->connectionResolvedBy($select));
        $this->assertSame(['replica.internal:0'], $factory->invocations);
    }

    public function testSelectHandsTheBuilderTheReadRoute(): void
    {
        // Which route object arrives decides whether the builder can move
        // between the routes at all, so it is pinned apart from where any one
        // statement lands. A builder started from a connection cannot move.
        $replica = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'health_check' => false,
                'read'         => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $this->assertInstanceOf(ReadConnectionRoute::class, $this->routeBehind($manager->select()));
        $this->assertInstanceOf(
            FixedConnectionRoute::class,
            $this->routeBehind($manager->connection(writable: false)->select()),
        );
    }

    public function testSelectFallsBackToThePrimaryWhenThePoolDeclaresNoReplica(): void
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

        $select = $manager->select()->from('users');

        $this->assertSame($primary, $this->connectionResolvedBy($select));
        $this->assertSame(['primary.internal:0'], $factory->invocations);
    }

    public function testSelectStaysOnThePrimaryWhileItIsInTransaction(): void
    {
        // A read issued inside a write transaction has to see that transaction's
        // own changes, so select() follows the same rule connection() applies.
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'health_check' => false,
                'read'         => [['host' => 'replica.internal']],
            ],
        ], $factory);

        // Cache the primary first so transaction-aware routing has a Connection to inspect.
        $manager->connection();
        $primary->begin();

        try {
            $select = $manager->select()->from('users');

            $this->assertSame($primary, $this->connectionResolvedBy($select));
            $this->assertSame(['primary.internal:0'], $factory->invocations);
        } finally {
            $primary->rollback();
        }
    }

    public function testSelectAsksForItsRouteWhenTheStatementRunsSoALaterTransactionMovesItToThePrimary(): void
    {
        // The route is asked for when the statement runs, not when select() is
        // called. A builder started before begin() therefore reaches the primary
        // when it is executed inside the transaction and sees that transaction's
        // own changes. Pinned here because the opposite — a builder holding the
        // connection it was started on — is what a method handing a part-built
        // Select back to its caller would otherwise expose.
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
                'health_check' => false,
                'read'         => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $startedBeforeTransaction = $manager->select()->from('users');

        $manager->connection(writable: true)->begin();

        try {
            $startedInsideTransaction = $manager->select()->from('users');

            $this->assertSame($primary, $this->connectionResolvedBy($startedBeforeTransaction));
            $this->assertSame($primary, $this->connectionResolvedBy($startedInsideTransaction));

            // Neither of them reached the replica, so it was never connected.
            $this->assertSame(['primary.internal:0'], $factory->invocations);
        } finally {
            $manager->connection(writable: true)->rollback();
        }
    }

    public function testOneBuilderAnswersFromWhicheverRouteItsTransactionStateSelects(): void
    {
        // The test above pins which connection a builder resolves to; this one
        // pins what that means for the rows it returns, and does it through
        // execute() rather than the route. The two sqlite databases are
        // separate, standing in for a replica on its own session, and they are
        // seeded with different values so that every answer names the route it
        // came from: 'replica-row' exists only on the replica, 'uncommitted'
        // only on the primary inside its open transaction.
        //
        // One builder is executed three times around the transaction, so a
        // connection remembered at any level — the route or the builder — would
        // repeat an earlier answer and fail here.
        $primaryPdo = $this->pdoSeededWith('primary-row');
        $replicaPdo = $this->pdoSeededWith('replica-row');
        $primary    = new Connection($primaryPdo, 'test');
        $replica    = new Connection($replicaPdo, 'test');
        $factory    = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'health_check' => false,
                'read'         => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $select = $manager->select('v')->from('reads');

        // No transaction yet, so the read route answers.
        $this->assertSame([['v' => 'replica-row']], $select->execute()->asArray());

        // Cache the primary first so transaction-aware routing has a Connection to inspect.
        $manager->connection(writable: true)->begin();

        try {
            $primaryPdo->exec("UPDATE reads SET v = 'uncommitted'");

            // The same builder, now inside the transaction.
            $this->assertSame([['v' => 'uncommitted']], $select->execute()->asArray());
        } finally {
            $manager->connection(writable: true)->rollback();
        }

        // And back to the read route once the transaction has ended. Answering
        // 'primary-row' here would mean the statement stayed on the primary.
        $this->assertSame([['v' => 'replica-row']], $select->execute()->asArray());
    }

    public function testSelectResumesTheReadRouteAfterTheTransactionEnds(): void
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
                'health_check' => false,
                'read'         => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $manager->connection();
        $primary->begin();

        $startedInsideTransaction = $manager->select()->from('users');
        $this->assertSame($primary, $this->connectionResolvedBy($startedInsideTransaction));

        $primary->rollback();

        // The same builder, asked again once the transaction has ended: a route
        // that had remembered its first answer would still say the primary.
        $this->assertSame($replica, $this->connectionResolvedBy($startedInsideTransaction));
        $this->assertSame(['primary.internal:0', 'replica.internal:0'], $factory->invocations);
    }

    public function testSelectHandsEveryColumnToTheBuilder(): void
    {
        $replica = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'health_check' => false,
                'read'         => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $this->assertSame(
            'SELECT `id`, `name`, COUNT(*) FROM `users`',
            $manager->select('id', 'name', Expression::of('COUNT(*)'))->from('users')->toSql(),
        );

        // Writing the statement needed no connection.
        $this->assertSame([], $factory->invocations);
    }

    public function testSelectWritesTableNamesWithThePoolsPrefix(): void
    {
        $replica = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'prefix'       => 'app_',
                'health_check' => false,
                'read'         => [['host' => 'replica.internal']],
            ],
        ], $factory);

        $this->assertSame(
            'SELECT * FROM `app_users`',
            $manager->select()->from('users')->toSql(),
        );

        // The prefix reached the grammar from the pool config, so writing the
        // statement needed no connection.
        $this->assertSame([], $factory->invocations);
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

    public function testEmptyReadListSurfacesThePrimaryFailureUnwrapped(): void
    {
        // With no replicas the read route is the primary route, so a primary
        // failure has to surface as itself. Falling through into the selection
        // loop would report it as "replica + primary fallback exhausted"
        // instead, describing attempts that never happened.
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'primary.internal',
            0,
            new DatabaseConnectionException('refused', 'master', null, 2002),
        );

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
            ],
        ], $factory);

        try {
            $manager->connection(writable: false);
            $this->fail('Expected DatabaseConnectionException');
        } catch (DatabaseConnectionException $e) {
            $this->assertSame('refused', $e->getMessage());
        }
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

        $this->deadCache->markServerDead('replica1.internal', 3306, 300);

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

    public function testReplicaRouteSkipsDeadReplicaMarkedOnNonDefaultPort(): void
    {
        // The dead-cache lookup must key on the configured port, not the 3306
        // fallback: a replica on 3307 marked dead has to stay skipped.
        $replica2 = $this->realConnection();
        $factory  = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica2.internal', 0, $replica2);

        $this->deadCache->markServerDead('replica1.internal', 3307, 300);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [
                    ['host' => 'replica1.internal', 'port' => 3307],
                    ['host' => 'replica2.internal'],
                ],
                'health_check' => false,
            ],
        ], $factory);

        // replica1 never reaches the factory — only replica2 is attempted.
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

        $this->deadCache->markServerDead('replica1.internal', 3306, 300);

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

    public function testDiagnosticMessageRendersDeclaredPortsRatherThanThePlaceholder(): void
    {
        // The "?" placeholder is the fallback for an undeclared port. When the
        // config does declare one, both the dead-cache skip line and the
        // attempt-failure line must show it — an operator reading this message
        // needs to know which endpoint was tried.
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica2.internal',
            3308,
            new DatabaseConnectionException('refused', 'replica2', null, 2002),
        );
        $factory->expectFailure(
            'primary.internal',
            3309,
            new DatabaseConnectionException('refused', 'primary', null, 2002),
        );

        $this->deadCache->markServerDead('replica1.internal', 3307, 300);

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'port'                    => 3309,
                'database'                => 'app',
                'read'                    => [
                    ['host' => 'replica1.internal', 'port' => 3307],
                    ['host' => 'replica2.internal', 'port' => 3308],
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
            $this->assertStringContainsString('replica1.internal:3307 → skipped (dead-cache)', $message);
            $this->assertStringContainsString('replica2.internal:3308 → refused', $message);
            $this->assertStringContainsString('primary.internal:3309 → refused', $message);
        }
    }

    public function testReplicaRoutePassesReplicaPortToDeadCache(): void
    {
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica.internal',
            3307,
            new DatabaseConnectionException('refused', 'replica', null, 2002),
        );

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'read'                    => [['host' => 'replica.internal', 'port' => 3307]],
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

        // A non-default port is required here: the key falls back to 3306 when
        // `port` is omitted, so asserting with 3306 could not tell the
        // configured value apart from the fallback.
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3307, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica.internal', 3306, 'master'));
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
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'unrelated_pool'));
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
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica.internal', 3306, 'unrelated_pool'));
    }

    public function testReplicaRouteRecordsPoolDeadOnUnknownDatabase(): void
    {
        // Error 1049 (unknown database) reports SQLSTATE 42000, not 28000, but
        // it is still pool-specific: only this pool's database name is wrong,
        // so other pools sharing the same host must not be blocked.
        $factory = new ScriptedConnectionFactory();
        $factory->expectFailure(
            'replica.internal',
            0,
            new DatabaseConnectionException('unknown database', 'replica', '42000', 1049),
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

        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica.internal', 3306, 'unrelated_pool'));
    }

    public function testReplicaRouteRecordsServerDeadOnPingFailure(): void
    {
        // Use a PDO mock that throws on exec('DO 1') so the test reproduces a
        // server-side ping failure without depending on SQLite's `DO` rejection.
        $pdoMock = $this->createStub(PDO::class);
        $pdoMock->method('exec')
            ->willReturnCallback(function (string $sql): never {
                $this->assertSame('DO 1', $sql);

                throw new \PDOException('ping failed');
            });

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
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'unrelated_pool'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function healthCheckEnabledProvider(): array
    {
        return [
            'health_check explicitly true' => [['health_check' => true]],
            'health_check omitted'         => [[]],
        ];
    }

    /**
     * @param array<string, mixed> $healthCheckConfig
     */
    #[DataProvider('healthCheckEnabledProvider')]
    public function testPersistentPoolStillRunsTheHealthCheckPing(array $healthCheckConfig): void
    {
        // A persistent handle is the case that needs the ping most: the server
        // may have dropped the session on wait_timeout while the handle sat in
        // the pool, and nothing would notice before the first real query.
        // Opting into persistent must therefore not turn the check off,
        // whether health_check is spelled out or left at its default.
        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->expects($this->once())
            ->method('exec')
            ->with('DO 1')
            ->willReturn(0);

        $replica = new Connection($pdoMock, 'replica');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => array_merge([
                'driver'     => 'mysql',
                'host'       => 'primary.internal',
                'database'   => 'app',
                'read'       => [['host' => 'replica.internal']],
                'persistent' => true,
            ], $healthCheckConfig),
        ], $factory);

        $this->assertSame($replica, $manager->connection(writable: false));
    }

    public function testPersistentPoolSkipsThePingWhenHealthCheckIsDisabled(): void
    {
        // The flag must not force the check back on either. A pool that
        // measured the per-connection ping cost and opted out keeps lazy
        // failover only, and turning on persistent is not a reason to
        // reinstate a round trip it declined.
        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->expects($this->never())->method('exec');

        $replica = new Connection($pdoMock, 'replica');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [['host' => 'replica.internal']],
                'health_check' => false,
                'persistent'   => true,
            ],
        ], $factory);

        $this->assertSame($replica, $manager->connection(writable: false));
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
            ['replica1.internal:3306' => true, 'replica2.internal:3306' => true],
            $manager->probeReplicas(),
        );
        $this->assertSame(
            ['replica1.internal:0', 'replica2.internal:0'],
            $factory->invocations,
        );
        $this->assertFalse($this->deadCache->isDead('replica1.internal', 3306, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica2.internal', 3306, 'master'));
    }

    public function testProbeReplicasUsesDeclaredPortInResultKey(): void
    {
        // A non-default port is required: the key falls back to 3306 when
        // `port` is omitted, so 3306 here could not tell the declared value
        // apart from the fallback.
        $replica = $this->pingableConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 3307, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
                'read'     => [['host' => 'replica.internal', 'port' => 3307]],
            ],
        ], $factory);

        $this->assertSame(['replica.internal:3307' => true], $manager->probeReplicas());
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

        $this->assertSame(['replica.internal:3306' => false], $manager->probeReplicas());
        // server-wide dead → also dead in any other pool
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'unrelated_pool'));
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

        $this->assertSame(['replica.internal:3306' => false], $manager->probeReplicas());
        // pool-specific dead → dead for 'master' only, alive elsewhere
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica.internal', 3306, 'unrelated_pool'));
    }

    public function testProbeReplicasMarksServerDeadOnPingFailure(): void
    {
        // PDO::exec('DO 1') throws → ping() raises DatabaseException, which the
        // probe must record as a server-wide failure (matches request-time semantics).
        $pdoMock = $this->createStub(PDO::class);
        $pdoMock->method('exec')
            ->willReturnCallback(function (string $sql): never {
                $this->assertSame('DO 1', $sql);

                throw new \PDOException('ping failed');
            });

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

        $this->assertSame(['replica.internal:3306' => false], $manager->probeReplicas());
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'unrelated_pool'));
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
                'replica1.internal:3306' => false,
                'replica2.internal:3306' => true,
                'replica3.internal:3306' => false,
            ],
            $manager->probeReplicas(),
        );
        // Failed replicas marked, healthy replica untouched.
        $this->assertTrue($this->deadCache->isDead('replica1.internal', 3306, 'master'));
        $this->assertFalse($this->deadCache->isDead('replica2.internal', 3306, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica3.internal', 3306, 'master'));
        // replica1 went to the shared key (server-wide), replica3 only to the pool key.
        $this->assertTrue($this->deadCache->isDead('replica1.internal', 3306, 'unrelated_pool'));
        $this->assertFalse($this->deadCache->isDead('replica3.internal', 3306, 'unrelated_pool'));
    }

    public function testProbeReplicasRefreshesDeadMarkTtlOnRepeatedFailure(): void
    {
        // Use a controllable clock so the assertion proves the dead mark was
        // re-stamped by the probe rather than just lingering from the pre-mark.
        $clock     = new MutableClock(1000);
        $deadCache = new InMemoryDeadReplicaCache($clock(...));

        // Pre-mark with a short TTL that would naturally expire at 1010.
        $deadCache->markServerDead('replica.internal', 3306, 10);

        // Advance past the original expiry; sanity-check the pre-mark is gone.
        $clock->now = 1100;
        $this->assertFalse($deadCache->isDead('replica.internal', 3306, 'master'));

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
            replicaSelectors: new ReplicaSelectorRegistry(['random' => $this->selector]),
            deadCache: $deadCache,
        );

        $this->assertSame(['replica.internal:3306' => false], $manager->probeReplicas());

        // The new dead mark stamped at clock=1100 with TTL=60 expires at 1160.
        // Still dead between the original 1010 expiry and the new 1160 expiry,
        // and gone again past 1160. This proves a fresh stamp, not a stale entry.
        $this->assertTrue($deadCache->isDead('replica.internal', 3306, 'master'));
        $clock->now = 1150;
        $this->assertTrue($deadCache->isDead('replica.internal', 3306, 'master'));
        $clock->now = 1170;
        $this->assertFalse($deadCache->isDead('replica.internal', 3306, 'master'));
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
        $this->deadCache->markServerDead('replica1.internal', 3306, 300);

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
            ['replica1.internal:3306' => true, 'replica2.internal:3306' => true],
            $manager->probeReplicas(),
        );
        $this->assertSame(
            ['replica1.internal:0', 'replica2.internal:0'],
            $factory->invocations,
        );
        // Existing dead mark is preserved; recovery still depends on TTL expiry.
        $this->assertTrue($this->deadCache->isDead('replica1.internal', 3306, 'master'));
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
            ['default-replica.internal:3306' => true],
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
            ['analytics-replica.internal:3306' => true],
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
            replicaSelectors: new ReplicaSelectorRegistry(['random' => $this->selector]),
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
            replicaSelectors: new ReplicaSelectorRegistry(['random' => $this->selector]),
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
                    // The slow-query branch returns early, so a threshold the
                    // query could plausibly exceed would turn the debug
                    // assertion below into a warning on a slow runner (this
                    // failed on the Windows runner at 100). The value itself is
                    // not what this test covers — ConnectionConfigResolverTest
                    // asserts the key reaches PoolConfig — so pick one that no
                    // in-memory SELECT can reach.
                    'slow_query_threshold_ms' => 60000,
                ],
            ],
            factory: $factory,
            replicaSelectors: new ReplicaSelectorRegistry(['random' => $this->selector]),
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

    public function testSlowQueryThresholdFromPoolConfigMakesASlowQueryWarn(): void
    {
        // testLoggingOptionsArePropagatedFromPoolConfig deliberately picks a
        // threshold no in-memory SELECT can reach, so it cannot distinguish a
        // propagated threshold from an unset one (both stay off the warning
        // branch). Force the query to outlast a 1ms threshold instead: the
        // warning can only appear if the pool value reached LoggingOptions.
        $handler = new TestHandler();
        $primary = $this->slowConnection(20000, 'master');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->managerWithLogger('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'log_all_queries'         => false,
                'slow_query_threshold_ms' => 1,
            ],
        ], $factory, $handler);

        $manager->connection()->query('SELECT 1');

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Warning, $records[0]->level);
        $this->assertSame('slow query', $records[0]->message);
        $this->assertGreaterThan(1, $records[0]->context['elapsed_ms']);
    }

    public function testNoWarningWhenThePoolOmitsTheSlowQueryThreshold(): void
    {
        // Counterpart to the test above: the same slow query must stay silent
        // when the pool sets no threshold. Without this, a warning hard-wired
        // to something other than the config would still pass there.
        $handler = new TestHandler();
        $primary = $this->slowConnection(20000, 'master');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->managerWithLogger('master', [
            'master' => [
                'driver'          => 'mysql',
                'host'            => 'primary.internal',
                'database'        => 'app',
                'log_all_queries' => false,
            ],
        ], $factory, $handler);

        $manager->connection()->query('SELECT 1');

        $this->assertSame([], $handler->getRecords());
    }

    public function testProbeReplicasLeavesTheProbeConnectionWithoutALogger(): void
    {
        // Probe connections are transient and never serve queries, so
        // probeReplicas() skips applyLogger(). Nothing asserted that directly:
        // routing the probe through applyLogger() would have gone unnoticed.
        $handler = new TestHandler();
        $replica = $this->slowConnection(0, 'replica');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->managerWithLogger('master', [
            'master' => [
                'driver'          => 'mysql',
                'host'            => 'primary.internal',
                'database'        => 'app',
                'read'            => [['host' => 'replica.internal']],
                'health_check'    => false,
                'log_all_queries' => true,
            ],
        ], $factory, $handler);

        $manager->probeReplicas();
        $replica->query('SELECT 1');

        $this->assertCount(0, $handler->getRecords());

        // Positive control: the same Connection does log once it is handed out
        // as a real replica, so the silence above is the missing logger rather
        // than a query that never reached the logging path.
        $manager->connection(writable: false)->query('SELECT 1');

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Debug, $records[0]->level);
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

    // -------------------------------------------------------
    // query timeout propagation
    // -------------------------------------------------------

    public function testPrimaryConnectionReceivesQueryTimeoutFromPoolConfig(): void
    {
        // Pool config carries query_timeout_ms; the manager forwards it to the
        // primary Connection, which lazy-applies SET SESSION on first query.
        $pdo     = $this->primaryPdoExpectingTimeout('SET SESSION max_execution_time = 5000');
        $primary = new Connection($pdo, 'master');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'           => 'mysql',
                'host'             => 'primary.internal',
                'database'         => 'app',
                'query_timeout_ms' => 5000,
            ],
        ], $factory);

        $manager->connection()->query('SELECT 1');
    }

    public function testReplicaConnectionReceivesQueryTimeoutFromPoolConfig(): void
    {
        // Replica path also flows through applyQueryTimeout, so reads served
        // off a replica pick up the same per-session timeout.
        $pdo     = $this->primaryPdoExpectingTimeout('SET SESSION max_execution_time = 3000');
        $replica = new Connection($pdo, 'replica');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);

        $manager = $this->manager('master', [
            'master' => [
                'driver'           => 'mysql',
                'host'             => 'primary.internal',
                'database'         => 'app',
                'read'             => [['host' => 'replica.internal']],
                'health_check'     => false,
                'query_timeout_ms' => 3000,
            ],
        ], $factory);

        $manager->connection(writable: false)->query('SELECT 1');
    }

    public function testQueryTimeoutNotPropagatedWhenPoolConfigOmitsKey(): void
    {
        // Without query_timeout_ms in config, the manager skips
        // setQueryTimeoutMs entirely — the lazy apply path stays inert and no
        // SET SESSION fires (only the user query reaches prepare()).
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);
        $pdo->method('prepare')->willReturn($stmt);

        $primary = new Connection($pdo, 'master');
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'   => 'mysql',
                'host'     => 'primary.internal',
                'database' => 'app',
            ],
        ], $factory);

        $manager->connection()->query('SELECT 1');
    }

    public function testProbeReplicasDoesNotForwardQueryTimeoutToProbeConnection(): void
    {
        // probeReplicas() builds short-lived Connections that only run a single
        // ping(); applyQueryTimeout() is intentionally bypassed so we don't
        // burn an extra SELECT VERSION() + SET SESSION round trip on a probe
        // that gets discarded. The lazy-apply semantics make this contract
        // unobservable through query / exec mock expectations
        // (setQueryTimeoutMs alone has no on-the-wire side effect), so we
        // verify it directly via Reflection on the probe Connection's
        // $queryTimeoutMs property.
        $probeConnection = new Connection(new PDO('sqlite::memory:'), 'replica');
        $factory         = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $probeConnection);

        $manager = $this->manager('master', [
            'master' => [
                'driver'           => 'mysql',
                'host'             => 'primary.internal',
                'database'         => 'app',
                'read'             => [['host' => 'replica.internal']],
                'health_check'     => false,
                'query_timeout_ms' => 5000,
            ],
        ], $factory);

        // probeReplicas always issues `DO 1`; on sqlite this fails as a
        // QueryException and is recorded as a probe failure. The ordering
        // factory.make → (apply* hooks) → ping is what matters here, and
        // applyQueryTimeout must not have been invoked before ping ran.
        $manager->probeReplicas();

        $reflection = new \ReflectionProperty(Connection::class, 'queryTimeoutMs');
        $this->assertNull($reflection->getValue($probeConnection));
    }

    // -------------------------------------------------------
    // persistent flag propagation
    // -------------------------------------------------------

    /**
     * @return array<string, array{0: bool}>
     */
    public static function persistentFlagProvider(): array
    {
        return [
            'persistent=true'  => [true],
            'persistent=false' => [false],
        ];
    }

    #[DataProvider('persistentFlagProvider')]
    public function testPrimaryConnectionPropagatesPersistentFlagFromPoolConfig(bool $persistent): void
    {
        // The pool's persistent setting must reach the factory's make() call
        // so the primary Connection opens its PDO with the matching
        // ATTR_PERSISTENT value (true → on, false → off).
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'     => 'mysql',
                'host'       => 'primary.internal',
                'database'   => 'app',
                'persistent' => $persistent,
            ],
        ], $factory);

        $manager->connection();

        $this->assertSame([$persistent], $factory->persistentFlags);
    }

    #[DataProvider('persistentFlagProvider')]
    public function testReplicaConnectionPropagatesPersistentFlagFromPoolConfig(bool $persistent): void
    {
        // The replica route must forward the same flag; reads served off a
        // replica must reflect the pool's persistent setting symmetrically
        // with the primary route.
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
                'persistent'   => $persistent,
            ],
        ], $factory);

        $manager->connection(writable: false);

        $this->assertSame([$persistent], $factory->persistentFlags);
    }

    public function testPersistentDefaultsToFalseWhenPoolConfigOmitsKey(): void
    {
        // Omitting persistent must result in persistent=false reaching make() —
        // ATTR_PERSISTENT defaults off so the typical pool keeps its current
        // (non-persistent) behavior unchanged.
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

        $manager->connection();

        $this->assertSame([false], $factory->persistentFlags);
    }

    #[DataProvider('persistentFlagProvider')]
    public function testReplicaFailoverPropagatesPersistentFlagToEachMake(bool $persistent): void
    {
        // Multiple make() calls during replica failover must each receive the
        // pool's persistent value so the eventually selected replica opens
        // with the matching ATTR_PERSISTENT. Guards against the flag being
        // scoped to the first attempt only.
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
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [
                    ['host' => 'replica1.internal'],
                    ['host' => 'replica2.internal'],
                ],
                'health_check' => false,
                'persistent'   => $persistent,
            ],
        ], $factory);

        $manager->connection(writable: false);

        $this->assertSame([$persistent, $persistent], $factory->persistentFlags);
    }

    #[DataProvider('persistentFlagProvider')]
    public function testPrimaryFallbackPropagatesPersistentFlagToEachMake(bool $persistent): void
    {
        // When every replica fails and the manager falls back to primary, the
        // primary make() must also receive the pool's persistent value.
        // Without this guard a fallback could silently drop ATTR_PERSISTENT.
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
                'persistent'              => $persistent,
            ],
        ], $factory);

        $manager->connection(writable: false);

        $this->assertSame([$persistent, $persistent, $persistent], $factory->persistentFlags);
    }

    #[DataProvider('persistentFlagProvider')]
    public function testTransactionAwareRoutingPreservesPersistentFlag(bool $persistent): void
    {
        // While a transaction is active, connection(writable: false) returns
        // the cached primary instead of selecting a replica. Confirm the
        // primary was originally opened with the pool's persistent value so
        // transaction routing inherits it consistently for true and false.
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
                'persistent'   => $persistent,
            ],
        ], $factory);

        $primaryConnection = $manager->connection();
        $primaryConnection->begin();
        $duringTransaction = $manager->connection(writable: false);
        $primaryConnection->rollback();

        $this->assertSame($primary, $duringTransaction);
        $this->assertSame([$persistent], $factory->persistentFlags);
    }

    #[DataProvider('persistentFlagProvider')]
    public function testPersistentFlagPropagatesAlongsideOtherPoolFeatures(bool $persistent): void
    {
        // Co-existence guard: persistent must still reach make() even when
        // other pool-level features (logging options, slow query threshold,
        // query timeout) are enabled together. Protects against future
        // applyXxx hook ordering bugs from shadowing the persistent argument
        // for either true or false.
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'                  => 'mysql',
                'host'                    => 'primary.internal',
                'database'                => 'app',
                'log_all_queries'         => true,
                'log_bindings'            => false,
                'slow_query_threshold_ms' => 100,
                'query_timeout_ms'        => 5000,
                'persistent'              => $persistent,
            ],
        ], $factory);

        $manager->connection();

        $this->assertSame([$persistent], $factory->persistentFlags);
    }

    /**
     * @return array<string, array{0: bool, 1: list<array{host: string}>, 2: list<bool>}>
     */
    public static function probeReplicasPersistentProvider(): array
    {
        return [
            'pool persistent=true, single replica'      => [true,  [['host' => 'replica.internal']], [false]],
            'pool persistent=false, single replica'     => [false, [['host' => 'replica.internal']], [false]],
            'pool persistent=true, multiple replicas'   => [true,  [['host' => 'replica1.internal'], ['host' => 'replica2.internal']], [false, false]],
            'pool persistent=false, multiple replicas'  => [false, [['host' => 'replica1.internal'], ['host' => 'replica2.internal']], [false, false]],
        ];
    }

    /**
     * @param list<array{host: string}> $replicas
     * @param list<bool>                $expectedFlags
     */
    #[DataProvider('probeReplicasPersistentProvider')]
    public function testProbeReplicasAlwaysOpensNonPersistentRegardlessOfPoolConfig(
        bool $poolPersistent,
        array $replicas,
        array $expectedFlags,
    ): void {
        // probeReplicas() opens short-lived Connections it never reuses, so it
        // forces persistent=false even when the pool config opts in.
        // Otherwise each probe would leak a slot into the server-side
        // persistent pool. Verified across single / multiple replicas and
        // both pool persistent settings.
        $factory = new ScriptedConnectionFactory();
        foreach ($replicas as $replica) {
            $factory->expectSuccess($replica['host'], 0, $this->realConnection());
        }

        $manager = $this->manager('master', [
            'master' => [
                'driver'     => 'mysql',
                'host'       => 'primary.internal',
                'database'   => 'app',
                'read'       => $replicas,
                'persistent' => $poolPersistent,
            ],
        ], $factory);

        $manager->probeReplicas();

        $this->assertSame($expectedFlags, $factory->persistentFlags);
    }

    // -------------------------------------------------------
    // residual transaction recovery (persistent connections)
    // -------------------------------------------------------

    public function testPersistentPrimaryConnectionRollsBackResidualTransaction(): void
    {
        // PDO performs no cleanup when a persistent handle returns to the pool,
        // so a request that died mid-transaction can hand the next one an open
        // transaction along with the rows it locked. Acquiring the primary must
        // roll it back and say so at warning level.
        $primary = $this->connectionInTransaction();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);
        $handler = new TestHandler();

        $manager = $this->managerWithLogger('master', [
            'master' => [
                'driver'     => 'mysql',
                'host'       => 'primary.internal',
                'database'   => 'app',
                'persistent' => true,
            ],
        ], $factory, $handler);

        $connection = $manager->connection();

        $this->assertFalse($connection->inTransaction());
        $this->assertTrue($handler->hasWarningThatContains(
            'residual transaction detected on persistent connection; rolled back'
        ));
        $this->assertSame(
            ['connection_name' => 'master'],
            $handler->getRecords()[0]->context,
        );
    }

    public function testPersistentReplicaConnectionRollsBackResidualTransaction(): void
    {
        // The read path opens its own handles from the same persistent pool,
        // so it needs the identical recovery; a replica serving a stale
        // transaction would return a snapshot from the previous request.
        $replica = $this->connectionInTransaction();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);
        $handler = new TestHandler();

        $manager = $this->managerWithLogger('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [['host' => 'replica.internal']],
                'health_check' => false,
                'persistent'   => true,
            ],
        ], $factory, $handler);

        $connection = $manager->connection(writable: false);

        $this->assertFalse($connection->inTransaction());
        $this->assertTrue($handler->hasWarningThatContains(
            'residual transaction detected on persistent connection; rolled back'
        ));
    }

    public function testNonPersistentPoolLeavesAnOpenTransactionUntouched(): void
    {
        // A non-persistent pool opens a fresh session that cannot carry
        // anything over, so the check is skipped entirely and costs nothing.
        // Feeding it a Connection that is already in a transaction proves the
        // gate is the pool flag rather than the transaction state.
        $primary = $this->connectionInTransaction();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);
        $handler = new TestHandler();

        $manager = $this->managerWithLogger('master', [
            'master' => [
                'driver'     => 'mysql',
                'host'       => 'primary.internal',
                'database'   => 'app',
                'persistent' => false,
            ],
        ], $factory, $handler);

        $connection = $manager->connection();

        $this->assertTrue($connection->inTransaction());
        $this->assertSame([], $handler->getRecords());
    }

    public function testPersistentConnectionWithoutResidualTransactionLogsNothing(): void
    {
        // The common case: a clean persistent handle must not produce warning
        // noise on every acquisition.
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);
        $handler = new TestHandler();

        $manager = $this->managerWithLogger('master', [
            'master' => [
                'driver'     => 'mysql',
                'host'       => 'primary.internal',
                'database'   => 'app',
                'persistent' => true,
            ],
        ], $factory, $handler);

        $manager->connection();

        $this->assertSame([], $handler->getRecords());
    }

    public function testResidualTransactionIsRolledBackWithoutALogger(): void
    {
        // Recovery and reporting are independent: a manager built without a
        // logger still has to clear the transaction, otherwise logging config
        // would decide whether locks are released.
        $primary = $this->connectionInTransaction();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'     => 'mysql',
                'host'       => 'primary.internal',
                'database'   => 'app',
                'persistent' => true,
            ],
        ], $factory);

        $this->assertFalse($manager->connection()->inTransaction());
    }

    public function testResidualRollbackFailureOnPrimarySurfacesToTheCaller(): void
    {
        // A rollback that fails leaves a session nobody can reason about, so
        // the primary path reports it as a failed acquisition instead of
        // handing the broken handle out.
        $primary = $this->connectionFailingToRollBack();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'     => 'mysql',
                'host'       => 'primary.internal',
                'database'   => 'app',
                'persistent' => true,
            ],
        ], $factory);

        $this->expectException(DatabaseException::class);

        $manager->connection();
    }

    public function testResidualRollbackFailureLeavesNothingCachedOnTheManager(): void
    {
        // The failed handle must not reach the cache, which is why recovery
        // runs before the assignment. A cached one would still report
        // inTransaction() == true, so the transaction routing at the top of
        // connection() would hand that unusable connection to every later
        // call — silently, without even the exception the first call raised.
        $primary = $this->connectionFailingToRollBack();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'     => 'mysql',
                'host'       => 'primary.internal',
                'database'   => 'app',
                'persistent' => true,
            ],
        ], $factory);

        foreach ([1, 2] as $attempt) {
            try {
                $manager->connection();
                $this->fail('Expected DatabaseException on attempt ' . $attempt);
            } catch (DatabaseException) {
                // empty
            }
        }

        // Two builds, not one cached failure replayed.
        $this->assertCount(2, $factory->invocations);
    }

    public function testResidualRollbackFailureOnReplicaFallsBackToPrimary(): void
    {
        // On the read path the same failure is just another unusable replica:
        // it is marked dead and the manager moves on, which is what keeps a
        // single poisoned replica from taking reads down.
        $replica = $this->connectionFailingToRollBack();
        $primary = $this->realConnection();
        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);
        $factory->expectSuccess('primary.internal', 0, $primary);

        $manager = $this->manager('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [['host' => 'replica.internal']],
                'health_check' => false,
                'persistent'   => true,
            ],
        ], $factory);

        $connection = $manager->connection(writable: false);

        $this->assertSame($primary, $connection);
        // The mark lands on the shared server key rather than this pool's own,
        // so every pool naming the same host and port skips the replica until
        // the entry expires. isDead() answers for either key, which is why both
        // pools are asserted.
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'master'));
        $this->assertTrue($this->deadCache->isDead('replica.internal', 3306, 'unrelated_pool'));
    }

    public function testTransactionOpenedThroughTheReplicaSurvivesAcquiringThePrimary(): void
    {
        // PDO keys persistent handles by DSN, username and password, so a
        // replica that inherits the primary's connection settings shares one
        // server session with it. That is modelled here by handing both
        // Connections the same PDO. A transaction the caller opened through the
        // read route must not be mistaken for a leftover when the primary is
        // acquired afterwards: rolling it back discards the caller's own
        // uncommitted work and leaves its commit() with nothing to close.
        $sharedPdo = new PDO('sqlite::memory:');
        $replica   = new Connection($sharedPdo, 'replica');
        $primary   = new Connection($sharedPdo, 'primary');

        $factory = new ScriptedConnectionFactory();
        $factory->expectSuccess('replica.internal', 0, $replica);
        $factory->expectSuccess('primary.internal', 0, $primary);
        $handler = new TestHandler();

        $manager = $this->managerWithLogger('master', [
            'master' => [
                'driver'       => 'mysql',
                'host'         => 'primary.internal',
                'database'     => 'app',
                'read'         => [['host' => 'replica.internal']],
                'health_check' => false,
                'persistent'   => true,
            ],
        ], $factory, $handler);

        $manager->connection(writable: false)->begin();
        $manager->connection();

        $this->assertTrue($replica->inTransaction());
        $this->assertSame([], $handler->getRecords());
    }
}
