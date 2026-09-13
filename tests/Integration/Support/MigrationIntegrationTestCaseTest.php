<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Support;

use Sloop\Database\Query\Grammar;
use Sloop\Tests\Support\MigrationIntegrationTestCase;

final class MigrationIntegrationTestCaseTest extends MigrationIntegrationTestCase
{
    private const string UNRELATED_TABLE = 'testXmigrationYfixture';

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->connection->statement('DROP TABLE IF EXISTS ' . self::UNRELATED_TABLE);
    }

    public function testSetUpDropsTablesAnEarlierRunLeftBehind(): void
    {
        // Simulates a run killed before its teardown: the table exists when
        // the next test starts, and a fresh setUp() has to remove it.
        $this->connection->statement('CREATE TABLE test_migration_leftover (id INT)');

        $this->setUp();

        $this->assertSame([], $this->migrationTableNames());
    }

    public function testTearDownDropsATableCreatedThroughATablePrefix(): void
    {
        // The names come back from the server with the prefix already on them,
        // so quoting them through a prefixed grammar would name another table.
        $this->connection->setGrammar(new Grammar(self::TABLE_PREFIX));
        $this->connection->statement('CREATE TABLE ' . $this->connection->quoteTable('prefixed') . ' (id INT)');

        $this->tearDown();

        $this->assertSame([], $this->migrationTableNames());
    }

    public function testTheTablePrefixIsMatchedLiterally(): void
    {
        // An unescaped _ in LIKE matches any character, which would drop a
        // table the fixture does not own.
        $this->connection->statement('CREATE TABLE ' . self::UNRELATED_TABLE . ' (id INT)');
        $this->connection->statement('CREATE TABLE test_migration_owned (id INT)');

        $this->assertSame(['test_migration_owned'], $this->migrationTableNames());
    }
}
