<?php

declare(strict_types=1);

namespace Sloop\Tests\Support;

use Sloop\Database\Connection;
use Sloop\Database\LikePattern;
use UnexpectedValueException;

/**
 * Base class for integration tests that create and drop tables.
 *
 * DDL commits implicitly, so the begin/rollback of
 * TransactionalIntegrationTestCase cannot undo what a migration test does.
 * Instead every table whose name starts with `test_migration_` is dropped
 * before and after each test. Dropping before as well as after picks up the
 * tables of a run that was killed before its teardown.
 *
 * Tables a test creates, including the migration history table and tables
 * named with a table prefix, must therefore start with that string.
 */
abstract class MigrationIntegrationTestCase extends IntegrationTestCase
{
    /**
     * Name every table these tests may create has to start with.
     *
     * @var string
     */
    protected const string TABLE_PREFIX = 'test_migration_';

    /**
     * Connection opened for the current test, outside any transaction.
     *
     * @var Connection
     */
    protected Connection $connection;

    /**
     * Open the connection and drop the tables an earlier run left behind.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = static::openConnection();
        $this->dropMigrationTables();
    }

    /**
     * Drop the tables this test created.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->dropMigrationTables();

        parent::tearDown();
    }

    /**
     * List the tables in the test database whose names start with TABLE_PREFIX.
     *
     * The prefix is matched literally: its underscores are escaped, so a table
     * such as `testXmigrationY` is not picked up.
     *
     * @return list<string>
     */
    protected function migrationTableNames(): array
    {
        $rows = $this->connection->query(
            'SELECT table_name AS name FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name LIKE ? ORDER BY table_name',
            [LikePattern::escape(self::TABLE_PREFIX) . '%'],
        )->asArray();

        $names = [];

        foreach ($rows as $row) {
            if (!\is_string($row['name'])) {
                throw new UnexpectedValueException('information_schema returned a table name that is not a string.');
            }

            $names[] = $row['name'];
        }

        return $names;
    }

    /**
     * Drop every table migrationTableNames() lists.
     *
     * @return void
     */
    private function dropMigrationTables(): void
    {
        foreach ($this->migrationTableNames() as $name) {
            $this->connection->statement('DROP TABLE IF EXISTS ' . $this->connection->quoteTable($name));
        }
    }
}
