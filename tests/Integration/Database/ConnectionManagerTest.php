<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use PDO;
use ReflectionProperty;
use Sloop\Database\Connection;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Factory\PdoConnectionFactory;
use Sloop\Database\Replica\InMemoryDeadReplicaCache;
use Sloop\Database\Replica\RandomReplicaSelector;
use Sloop\Tests\Support\IntegrationTestCase;

final class ConnectionManagerTest extends IntegrationTestCase
{
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
            replicaSelector: $this->selector,
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
     * @return PDO                    Underlying PDO instance
     */
    private function extractPdo(Connection $connection): PDO
    {
        $reflection = new ReflectionProperty(Connection::class, 'pdo');
        $pdo        = $reflection->getValue($connection);
        $this->assertInstanceOf(PDO::class, $pdo);

        return $pdo;
    }

    public function testConnectionReturnsUsableConnection(): void
    {
        $manager = $this->manager(self::defaultConfig());

        $rows = $manager->connection()->query('SELECT 1 AS v')->toArray();

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
            ->toArray();

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
            ->toArray();

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
            ->toArray();

        $this->assertArrayHasKey('LOWER_CASE_ALIAS', $rows[0]);
    }
}
