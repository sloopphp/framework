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
     * @return Connection Configured Connection with sloop's PDO defaults
     */
    protected function openConnection(): Connection
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'sloop_test';
        $user = getenv('DB_USER') ?: 'sloop';
        $pass = getenv('DB_PASS') ?: 'secret';

        return Connection::open(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $pass,
            [],
            'integration',
        );
    }
}
