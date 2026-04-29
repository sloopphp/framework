<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use PDO;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Factory\PdoConnectionFactory;
use Sloop\Tests\Support\IntegrationTestCase;

final class ConnectionManagerTest extends IntegrationTestCase
{
    private PdoConnectionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new PdoConnectionFactory();
    }

    public function testConnectionReturnsUsableConnection(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: ['master' => self::defaultConfig()],
            factory: $this->factory,
        );

        $rows = $manager->connection()->query('SELECT 1 AS v')->toArray();

        $this->assertSame(1, $rows[0]['v']);
    }

    public function testConnectionIsCachedAcrossCalls(): void
    {
        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: ['master' => self::defaultConfig()],
            factory: $this->factory,
        );

        $first  = $manager->connection();
        $second = $manager->connection();

        $this->assertSame($first, $second);
    }

    public function testConnectionAppliesCharsetFromConfig(): void
    {
        $config            = self::defaultConfig();
        $config['charset'] = 'utf8mb4';

        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: ['master' => $config],
            factory: $this->factory,
        );

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

        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: ['master' => $config],
            factory: $this->factory,
        );

        $rows = $manager->connection()
            ->query("SHOW VARIABLES LIKE 'collation_connection'")
            ->toArray();

        $this->assertSame('utf8mb4_general_ci', $rows[0]['Value']);
    }

    public function testConnectionAppliesUserOptionsFromConfig(): void
    {
        // PDO::ATTR_CASE = CASE_UPPER forces column names to be returned uppercased.
        // Verifying this end-to-end confirms that the `options` config key flows
        // through ConnectionConfigResolver::resolvePdoOptions and Connection::open
        // all the way to the underlying PDO instance.
        $config            = self::defaultConfig();
        $config['options'] = [PDO::ATTR_CASE => PDO::CASE_UPPER];

        $manager = new ConnectionManager(
            defaultName: 'master',
            configs: ['master' => $config],
            factory: $this->factory,
        );

        $rows = $manager->connection()
            ->query('SELECT 1 AS lower_case_alias')
            ->toArray();

        $this->assertArrayHasKey('LOWER_CASE_ALIAS', $rows[0]);
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
}
