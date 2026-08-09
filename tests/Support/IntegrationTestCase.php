<?php

declare(strict_types=1);

namespace Sloop\Tests\Support;

use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;

/**
 * Base class for integration tests that need a live MySQL/MariaDB connection.
 *
 * Connection parameters come from the DB_HOST / DB_PORT / DB_NAME / DB_USER /
 * DB_PASS environment variables, falling back to the defaults defined in
 * framework/compose.yaml (MySQL on 127.0.0.1:3306, sloop/secret credentials).
 *
 * Future additions (per-test begin/rollback, fixture helpers) belong here so
 * every concrete integration test inherits them.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Open a fresh sloop Connection to the integration database.
     *
     * Static so that setUpBeforeClass(), which runs before any instance
     * exists, can open a connection for schema setup.
     *
     * @return Connection Configured Connection with sloop's PDO defaults
     */
    protected static function openConnection(): Connection
    {
        $host = self::envOr('DB_HOST', '127.0.0.1');
        $port = self::envOr('DB_PORT', '3306');
        $name = self::envOr('DB_NAME', 'sloop_test');
        $user = self::envOr('DB_USER', 'sloop');
        $pass = self::envOr('DB_PASS', 'secret');

        return Connection::open(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $pass,
            [],
            'integration',
        );
    }

    private static function envOr(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

}
