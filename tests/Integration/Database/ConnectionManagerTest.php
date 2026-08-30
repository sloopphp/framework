<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use PDO;
use ReflectionProperty;
use Sloop\Database\Connection;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Factory\PdoConnectionFactory;
use Sloop\Database\Query\Expression;
use Sloop\Database\Replica\InMemoryDeadReplicaCache;
use Sloop\Database\Replica\RandomReplicaSelector;
use Sloop\Database\Replica\ReplicaSelectorRegistry;
use Sloop\Tests\Support\IntegrationTestCase;

final class ConnectionManagerTest extends IntegrationTestCase
{
    private const string READ_ROUTE_PREFIX = 'sloop_manager_';

    private const string READ_ROUTE_TABLE = self::READ_ROUTE_PREFIX . 'widgets';

    private PdoConnectionFactory $factory;

    private RandomReplicaSelector $selector;

    private InMemoryDeadReplicaCache $deadCache;

    protected function setUp(): void
    {
        $this->factory   = new PdoConnectionFactory();
        $this->selector  = new RandomReplicaSelector();
        $this->deadCache = new InMemoryDeadReplicaCache();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function manager(array $config): ConnectionManager
    {
        return new ConnectionManager(
            defaultName: 'master',
            configs: ['master' => $config],
            factory: $this->factory,
            replicaSelectors: new ReplicaSelectorRegistry(['random' => $this->selector]),
            deadCache: $this->deadCache,
        );
    }

    /**
     * Build a default integration config from environment variables.
     *
     * @return array<string, mixed>
     */
    private static function defaultConfig(): array
    {
        $port = getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 3306;

        return [
            'driver'   => 'mysql',
            'host'     => getenv('DB_HOST') !== false ? getenv('DB_HOST') : '127.0.0.1',
            'port'     => $port,
            'database' => getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'sloop_test',
            'username' => getenv('DB_USER') !== false ? getenv('DB_USER') : 'sloop',
            'password' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'secret',
        ];
    }

    /**
     * Extract the underlying PDO instance from a Connection via reflection.
     *
     * Connection::$pdo is private to keep the wrapper API minimal. Tests that
     * need to assert PDO-level attributes (e.g. ATTR_PERSISTENT) reach in
     * through reflection rather than expanding the public surface.
     *
     * @param  Connection $connection Connection whose PDO is read
     * @return PDO        Underlying PDO instance
     */
    private function extractPdo(Connection $connection): PDO
    {
        $reflection = new ReflectionProperty(Connection::class, 'pdo');
        $pdo        = $reflection->getValue($connection);
        $this->assertInstanceOf(PDO::class, $pdo);

        return $pdo;
    }

    private function createReadRouteTable(Connection $connection): void
    {
        $connection->statement('DROP TABLE IF EXISTS ' . self::READ_ROUTE_TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::READ_ROUTE_TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL PRIMARY KEY, '
                . 'label VARCHAR(50) NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->statement(
            'INSERT INTO ' . self::READ_ROUTE_TABLE . ' (id, label) VALUES (1, ?), (2, ?)',
            ['first', 'second'],
        );
    }

    public function testConnectionReturnsUsableConnection(): void
    {
        $manager = $this->manager(self::defaultConfig());

        $rows = $manager->connection()->query('SELECT 1 AS v')->asArray();

        $this->assertSame(1, $rows[0]['v']);
    }

    public function testConnectionIsCachedAcrossCalls(): void
    {
        $manager = $this->manager(self::defaultConfig());

        $first  = $manager->connection();
        $second = $manager->connection();

        $this->assertSame($first, $second);
    }

    public function testConnectionAppliesCharsetFromConfig(): void
    {
        $config            = self::defaultConfig();
        $config['charset'] = 'utf8mb4';

        $manager = $this->manager($config);

        $rows = $manager->connection()
            ->query("SHOW VARIABLES LIKE 'character_set_client'")
            ->asArray();

        $this->assertSame('utf8mb4', $rows[0]['Value']);
    }

    public function testConnectionAppliesCollationWhenSet(): void
    {
        $config              = self::defaultConfig();
        $config['charset']   = 'utf8mb4';
        $config['collation'] = 'utf8mb4_general_ci';

        $manager = $this->manager($config);

        $rows = $manager->connection()
            ->query("SHOW VARIABLES LIKE 'collation_connection'")
            ->asArray();

        $this->assertSame('utf8mb4_general_ci', $rows[0]['Value']);
    }

    public function testConnectionUsesPersistentWhenPersistentIsTrue(): void
    {
        // Verifies that PDO::ATTR_PERSISTENT is true on a connection acquired
        // with persistent => true against a real MySQL server. End-to-end
        // check that PdoConnectionFactory propagates the persistent flag into
        // the PDO options.
        $config               = self::defaultConfig();
        $config['persistent'] = true;

        $manager    = $this->manager($config);
        $connection = $manager->connection();

        $this->assertTrue($this->extractPdo($connection)->getAttribute(PDO::ATTR_PERSISTENT));
    }

    public function testConnectionDoesNotUsePersistentByDefault(): void
    {
        // With the persistent key omitted, PDO::ATTR_PERSISTENT must be
        // false. Regression guard that existing pools keep opening
        // non-persistent connections by default.
        $manager    = $this->manager(self::defaultConfig());
        $connection = $manager->connection();

        $this->assertFalse($this->extractPdo($connection)->getAttribute(PDO::ATTR_PERSISTENT));
    }

    public function testConnectionAppliesUserOptionsFromConfig(): void
    {
        // PDO::ATTR_CASE = CASE_UPPER forces column names to be returned uppercased.
        // Verifying this end-to-end confirms that the `options` config key flows
        // through ConnectionConfigResolver::resolvePdoOptions and Connection::open
        // all the way to the underlying PDO instance.
        $config            = self::defaultConfig();
        $config['options'] = [PDO::ATTR_CASE => PDO::CASE_UPPER];

        $manager = $this->manager($config);

        $rows = $manager->connection()
            ->query('SELECT 1 AS lower_case_alias')
            ->asArray();

        $this->assertArrayHasKey('LOWER_CASE_ALIAS', $rows[0]);
    }

    public function testPoolPersistentFalseOverridesUserOptionsAttrPersistentTrue(): void
    {
        // Pool-level persistent is the single source of truth for
        // ATTR_PERSISTENT. Even when a user puts ATTR_PERSISTENT=true into the
        // `options:` config key, an explicit (or default) persistent=false on
        // the pool must win and open a non-persistent connection.
        $config            = self::defaultConfig();
        $config['options'] = [PDO::ATTR_PERSISTENT => true];

        $manager    = $this->manager($config);
        $connection = $manager->connection();

        $this->assertFalse($this->extractPdo($connection)->getAttribute(PDO::ATTR_PERSISTENT));
    }

    public function testPoolPersistentTrueOverridesUserOptionsAttrPersistentFalse(): void
    {
        // Symmetric counterpart: pool persistent=true must win over a user
        // `options:` entry that says ATTR_PERSISTENT=false.
        $config               = self::defaultConfig();
        $config['persistent'] = true;
        $config['options']    = [PDO::ATTR_PERSISTENT => false];

        $manager    = $this->manager($config);
        $connection = $manager->connection();

        $this->assertTrue($this->extractPdo($connection)->getAttribute(PDO::ATTR_PERSISTENT));
    }

    public function testSelectReadsRowsThroughTheReadRoute(): void
    {
        // The pool declares a replica on the same server the suite runs against,
        // so the statement travels the replica route and still reaches a real
        // table. Routing itself is covered by the unit tests; what this asserts
        // is that a statement started from the manager executes end to end.
        $config         = self::defaultConfig();
        $config['read'] = [['host' => $config['host']]];

        $manager = $this->manager($config);
        $this->createReadRouteTable($manager->connection(writable: true));

        try {
            // Pin the route: on primary fallback the replica lookup hands back
            // the primary instance itself, so identity is what separates a real
            // replica route from a silent degradation to the primary.
            $this->assertNotSame(
                $manager->connection(writable: true),
                $manager->connection(writable: false),
            );

            $rows = $manager->select('label')
                ->from(self::READ_ROUTE_TABLE)
                ->where('id', 2)
                ->execute();

            $this->assertSame([['label' => 'second']], $rows->asArray());
        } finally {
            $manager->connection(writable: true)->statement('DROP TABLE IF EXISTS ' . self::READ_ROUTE_TABLE);
        }
    }

    public function testDeleteWritesThroughThePrimaryEvenWhenAReplicaIsConfigured(): void
    {
        // The pool declares a replica, so a statement that took the read route
        // would land on the replica session. Both sessions here name the same
        // server, so what shows the write went to the primary is that the row
        // is gone and the statement did not fail against a route that has no
        // business writing.
        $config           = self::defaultConfig();
        $config['read']   = [['host' => $config['host']]];
        $config['prefix'] = self::READ_ROUTE_PREFIX;

        $manager = $this->manager($config);
        $this->createReadRouteTable($manager->connection(writable: true));

        try {
            $removed = $manager->delete('widgets')->where('id', 1)->execute();

            $this->assertSame(1, $removed);

            $rows = $manager->select('label')->from('widgets')->orderBy('id')->execute();
            $this->assertSame([['label' => 'second']], $rows->asArray());
        } finally {
            $manager->connection(writable: true)->statement('DROP TABLE IF EXISTS ' . self::READ_ROUTE_TABLE);
        }
    }

    public function testSelectAppliesThePoolsPrefixAgainstTheServer(): void
    {
        // from('widgets') has to reach the prefixed table, which only shows up
        // against a real server: an unprefixed name would be a missing table
        // rather than a wrong string.
        $config           = self::defaultConfig();
        $config['prefix'] = self::READ_ROUTE_PREFIX;

        $manager = $this->manager($config);
        $this->createReadRouteTable($manager->connection(writable: true));

        try {
            $rows = $manager->select('label')
                ->from('widgets')
                ->orderBy('id')
                ->execute();

            $this->assertSame([['label' => 'first'], ['label' => 'second']], $rows->asArray());
        } finally {
            $manager->connection(writable: true)->statement('DROP TABLE IF EXISTS ' . self::READ_ROUTE_TABLE);
        }
    }

    public function testOneBuilderMovesBetweenTheRoutesAsTheTransactionOpensAndCloses(): void
    {
        // Non-persistent, so the read entry lands on its own server session even
        // though it names the same host. Both routes read the same rows, so a
        // committed value cannot tell them apart; the server's own session id
        // can, and that is what this asserts. One builder selecting
        // CONNECTION_ID() is executed three times, so a connection remembered
        // at any level — the route or the builder — would repeat an answer.
        //
        // The uncommitted read is asserted separately: only the primary's
        // session can see 'rewritten' before the rollback.
        $config         = self::defaultConfig();
        $config['read'] = [['host' => $config['host']]];

        $manager = $this->manager($config);
        $primary = $manager->connection(writable: true);
        $this->createReadRouteTable($primary);

        try {
            $replica = $manager->connection(writable: false);
            $this->assertNotSame($primary, $replica);

            $primarySessionId = $primary->query('SELECT CONNECTION_ID() AS id')->asArray()[0]['id'];
            $replicaSessionId = $replica->query('SELECT CONNECTION_ID() AS id')->asArray()[0]['id'];
            $this->assertNotSame($primarySessionId, $replicaSessionId);

            $session = $manager->select(Expression::of('CONNECTION_ID() AS id'))
                ->from(self::READ_ROUTE_TABLE)
                ->where('id', 1);

            $this->assertSame($replicaSessionId, $session->execute()->asArray()[0]['id']);

            $rows = $manager->select('label')
                ->from(self::READ_ROUTE_TABLE)
                ->where('id', 1);

            $primary->begin();
            $primary->statement(
                'UPDATE ' . self::READ_ROUTE_TABLE . ' SET label = ? WHERE id = 1',
                ['rewritten'],
            );

            $this->assertSame($primarySessionId, $session->execute()->asArray()[0]['id']);
            $this->assertSame([['label' => 'rewritten']], $rows->execute()->asArray());

            $primary->rollback();

            $this->assertSame($replicaSessionId, $session->execute()->asArray()[0]['id']);
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollback();
            }

            $primary->statement('DROP TABLE IF EXISTS ' . self::READ_ROUTE_TABLE);
        }
    }

    public function testAReplicaSharingThePrimarysSessionSeesItsOpenTransaction(): void
    {
        // The two routes are separate Connection objects, but persistent handles
        // are shared per DSN, username and password, so a read entry that
        // overrides none of them puts both on one server session. A statement
        // pinned to the replica object then observes the primary's open
        // transaction — the opposite of what separate sessions do, and the
        // branch the unit suite cannot express.
        //
        // The builder is started from the replica Connection rather than from
        // the manager on purpose: a manager-started builder asks for its route
        // when it runs, and inside a transaction that answers the primary, so
        // it would never reach the replica object this test is about.
        $config               = self::defaultConfig();
        $config['persistent'] = true;
        $config['read']       = [[]];

        $manager = $this->manager($config);
        $primary = $manager->connection(writable: true);
        $this->createReadRouteTable($primary);

        try {
            $replica = $manager->connection(writable: false);
            $this->assertNotSame($primary, $replica);

            $fixedToReadRoute = $replica->select('label')
                ->from(self::READ_ROUTE_TABLE)
                ->where('id', 1);

            $primary->begin();
            $primary->statement(
                'UPDATE ' . self::READ_ROUTE_TABLE . ' SET label = ? WHERE id = 1',
                ['rewritten'],
            );

            $this->assertSame([['label' => 'rewritten']], $fixedToReadRoute->execute()->asArray());
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollback();
            }

            $primary->statement('DROP TABLE IF EXISTS ' . self::READ_ROUTE_TABLE);
        }
    }
}
