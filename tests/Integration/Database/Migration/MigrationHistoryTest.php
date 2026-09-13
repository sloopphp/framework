<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database\Migration;

use Sloop\Database\Exception\UniqueConstraintViolationException;
use Sloop\Database\Migration\MigrationHistory;
use Sloop\Database\Query\Grammar;
use Sloop\Tests\Support\MigrationIntegrationTestCase;
use Sloop\Tests\Support\ThrowsAssertions;

final class MigrationHistoryTest extends MigrationIntegrationTestCase
{
    use ThrowsAssertions;

    private const string TABLE = 'test_migration_history';

    private MigrationHistory $history;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection->setMigrationsTable(self::TABLE);
        $this->history = new MigrationHistory($this->connection);
    }

    /**
     * @return array<string, array{string, string}>
     */
    private function columns(string $table): array
    {
        $rows = $this->connection->query(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        )->asArray();

        $columns = [];

        foreach ($rows as $row) {
            $this->assertIsString($row['name']);
            $this->assertIsString($row['type']);
            $this->assertIsString($row['nullable']);
            $columns[$row['name']] = [$row['type'], $row['nullable']];
        }

        return $columns;
    }

    public function testCreateIfMissingCreatesTheTable(): void
    {
        $this->history->createIfMissing();

        $this->assertSame([self::TABLE], $this->migrationTableNames());
        $this->assertSame(
            [
                'id'         => ['int unsigned', 'NO'],
                'name'       => ['varchar(255)', 'NO'],
                'batch'      => ['int unsigned', 'NO'],
                'applied_at' => ['timestamp', 'NO'],
            ],
            // MariaDB writes the display width (int(10) unsigned), MySQL 8.0 does not.
            array_map(
                static fn (array $column): array => [preg_replace('/^int\(\d+\)/', 'int', $column[0]) ?? $column[0], $column[1]],
                $this->columns(self::TABLE),
            ),
        );
    }

    public function testCreateIfMissingLeavesAnExistingTableAndItsRowsAlone(): void
    {
        $this->history->createIfMissing();
        $this->history->record('20260501000000_create_users_table', 1);

        $this->history->createIfMissing();

        $this->assertSame(['20260501000000_create_users_table'], $this->history->appliedNames());
    }

    public function testCreateIfMissingAppliesTheTablePrefix(): void
    {
        $this->connection->setGrammar(new Grammar(self::TABLE_PREFIX));
        $this->connection->setMigrationsTable('history');

        $this->history->createIfMissing();
        $this->history->record('20260501000000_create_users_table', 1);

        $this->assertSame([self::TABLE_PREFIX . 'history'], $this->migrationTableNames());
        $this->assertSame(['20260501000000_create_users_table'], $this->history->appliedNames());
    }

    public function testRecordedMigrationsReadBackInBatchThenRecordedOrder(): void
    {
        $this->history->createIfMissing();

        $this->history->record('20260601000000_create_posts_table', 2);
        $this->history->record('20260501000000_create_users_table', 1);
        $this->history->record('20260502000000_add_email_to_users', 2);

        $this->assertSame(
            [
                '20260501000000_create_users_table',
                '20260601000000_create_posts_table',
                '20260502000000_add_email_to_users',
            ],
            $this->history->appliedNames(),
        );
        $this->assertSame(2, $this->history->lastBatch());
    }

    public function testAppliedNamesDoesNotReorderByTheServerCollation(): void
    {
        // The migrator applies files in byte order, where a digit sorts before
        // an underscore. MySQL 8.0's default collation puts the underscore
        // first, so reading back by name would reverse these two there.
        $this->history->createIfMissing();

        $this->history->record('20260501000000_add1x', 1);
        $this->history->record('20260501000000_add_x', 1);

        $this->assertSame(['20260501000000_add1x', '20260501000000_add_x'], $this->history->appliedNames());
    }

    public function testLastBatchIsZeroOnAnEmptyTable(): void
    {
        $this->history->createIfMissing();

        $this->assertSame(0, $this->history->lastBatch());
    }

    public function testRecordFillsTheTimeTheMigrationWasApplied(): void
    {
        $this->history->createIfMissing();

        $this->history->record('20260501000000_create_users_table', 1);

        $row = $this->connection->query('SELECT applied_at FROM ' . self::TABLE)->first();
        $this->assertNotNull($row);
        $this->assertIsString($row['applied_at']);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $row['applied_at']);
    }

    public function testRecordingTheSameNameTwiceIsRefusedByTheTable(): void
    {
        $this->history->createIfMissing();
        $this->history->record('20260501000000_create_users_table', 1);

        $this->assertThrows(
            UniqueConstraintViolationException::class,
            fn () => $this->history->record('20260501000000_create_users_table', 2),
        );
    }
}
