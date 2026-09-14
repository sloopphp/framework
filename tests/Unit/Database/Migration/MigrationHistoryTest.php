<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Migration;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Migration\MigrationHistory;
use Sloop\Database\Query\Grammar;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Database\Stub\RecordingSqlite;
use UnexpectedValueException;

/*
 * The table itself is MySQL DDL, which SQLite rejects, so the statement that
 * creates it is pinned here by what reaches the driver and exercised against
 * both servers in the integration suite. The reads and writes go through the
 * query builders and run against a SQLite table of the same shape.
 */
final class MigrationHistoryTest extends TestCase
{
    use ThrowsAssertions;

    private RecordingSqlite $pdo;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->pdo = new RecordingSqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->connection = new Connection($this->pdo, 'history_test');
    }

    private function createSqliteTable(string $table, string $nameType = 'TEXT', string $batchType = 'INTEGER'): void
    {
        $this->pdo->exec(
            'CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name ' . $nameType
            . ' NOT NULL UNIQUE, batch ' . $batchType . ' NOT NULL, applied_at TEXT)',
        );
        $this->pdo->calls = [];
    }

    public function testCreateIfMissingSendsTheTableDefinitionWithThePrefixedName(): void
    {
        $this->connection->setGrammar(new Grammar('app_'));
        $this->connection->setMigrationsTable('schema_history');

        $this->assertThrows(
            DatabaseException::class,
            fn () => new MigrationHistory($this->connection)->createIfMissing(),
        );

        $this->assertSame(
            [
                'prepare: CREATE TABLE IF NOT EXISTS `app_schema_history` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`name` VARCHAR(255) NOT NULL, '
                . '`batch` INT UNSIGNED NOT NULL, '
                . '`applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'PRIMARY KEY (`id`), '
                . 'UNIQUE KEY `uk_migrations_name` (`name`))',
            ],
            $this->pdo->calls,
        );
    }

    public function testExistsAsksInformationSchemaAboutTheTable(): void
    {
        $this->connection->setGrammar(new Grammar('app_'));
        $this->connection->setMigrationsTable('schema_history');

        $this->assertThrows(
            DatabaseException::class,
            fn () => new MigrationHistory($this->connection)->exists(),
        );

        $this->assertSame(
            ['prepare: SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'],
            $this->pdo->calls,
        );
    }

    public function testAppliedNamesIsEmptyBeforeAnyMigrationRuns(): void
    {
        $this->createSqliteTable('migrations');

        $this->assertSame([], new MigrationHistory($this->connection)->appliedNames());
    }

    public function testAppliedNamesOrdersByBatchThenByTheOrderRecorded(): void
    {
        $this->createSqliteTable('migrations');
        $history = new MigrationHistory($this->connection);

        $history->record('20260601000000_create_posts_table', 2);
        $history->record('20260501000000_create_users_table', 1);
        $history->record('20260502000000_add_email_to_users', 2);
        $history->record('20260401000000_create_roles_table', 1);

        $this->assertSame(
            [
                '20260501000000_create_users_table',
                '20260401000000_create_roles_table',
                '20260601000000_create_posts_table',
                '20260502000000_add_email_to_users',
            ],
            $history->appliedNames(),
        );
    }

    public function testLastBatchIsZeroBeforeAnyMigrationRuns(): void
    {
        $this->createSqliteTable('migrations');

        $this->assertSame(0, new MigrationHistory($this->connection)->lastBatch());
    }

    public function testLastBatchIsTheHighestBatchRecorded(): void
    {
        $this->createSqliteTable('migrations');
        $history = new MigrationHistory($this->connection);

        $history->record('20260501000000_create_users_table', 3);
        $history->record('20260502000000_create_posts_table', 1);

        $this->assertSame(3, $history->lastBatch());
    }

    public function testNamesInLastBatchAndLastNamesAreEmptyBeforeAnyMigrationRuns(): void
    {
        $this->createSqliteTable('migrations');
        $history = new MigrationHistory($this->connection);

        $this->assertSame([], $history->namesInLastBatch());
        $this->assertSame([], $history->lastNames(1));
    }

    public function testNamesInLastBatchListsTheHighestBatchNewestFirst(): void
    {
        $this->createSqliteTable('migrations');
        $history = new MigrationHistory($this->connection);

        $history->record('20260701000000_create_tags_table', 5);
        $history->record('20260501000000_create_users_table', 1);
        $history->record('20260401000000_create_roles_table', 5);
        $history->record('20260601000000_create_posts_table', 2);

        $this->assertSame(
            ['20260401000000_create_roles_table', '20260701000000_create_tags_table'],
            $history->namesInLastBatch(),
        );
    }

    public function testLastNamesCountsMigrationsAcrossBatchesNewestFirst(): void
    {
        $this->createSqliteTable('migrations');
        $history = new MigrationHistory($this->connection);

        $history->record('20260501000000_create_users_table', 1);
        $history->record('20260601000000_create_posts_table', 2);
        $history->record('20260502000000_add_email_to_users', 2);
        $history->record('20260701000000_create_tags_table', 3);

        $this->assertSame(['20260701000000_create_tags_table'], $history->lastNames(1));
        $this->assertSame(
            [
                '20260701000000_create_tags_table',
                '20260502000000_add_email_to_users',
                '20260601000000_create_posts_table',
            ],
            $history->lastNames(3),
        );
    }

    public function testLastNamesListsEveryMigrationWhenAskedForMoreThanRecorded(): void
    {
        $this->createSqliteTable('migrations');
        $history = new MigrationHistory($this->connection);

        $history->record('20260501000000_create_users_table', 1);
        $history->record('20260601000000_create_posts_table', 2);

        $this->assertSame(
            ['20260601000000_create_posts_table', '20260501000000_create_users_table'],
            $history->lastNames(3),
        );
    }

    public function testRecordsIsEmptyBeforeAnyMigrationRuns(): void
    {
        $this->createSqliteTable('migrations');

        $this->assertSame([], new MigrationHistory($this->connection)->records());
    }

    public function testRecordsReadsEachNameWithItsBatchAndTheTimeItWasRecorded(): void
    {
        $this->createSqliteTable('migrations');
        $this->pdo->exec(
            'INSERT INTO migrations (name, batch, applied_at) VALUES '
            . "('20260502000000_create_posts_table', 2, '2026-09-14 10:30:05'), "
            . "('20260501000000_create_users_table', 1, '2026-09-13 23:59:59')",
        );

        $records = new MigrationHistory($this->connection)->records();

        $this->assertCount(2, $records);
        $this->assertSame('20260502000000_create_posts_table', $records[0]['name']);
        $this->assertSame(2, $records[0]['batch']);
        $this->assertSame('2026-09-14 10:30:05', $records[0]['appliedAt']->format('Y-m-d H:i:s'));
        $this->assertSame('20260501000000_create_users_table', $records[1]['name']);
        $this->assertSame(1, $records[1]['batch']);
        $this->assertSame('2026-09-13 23:59:59', $records[1]['appliedAt']->format('Y-m-d H:i:s'));
    }

    public function testRecordsReadsATimeThatTheDefaultTimezoneSkipsForSummerTime(): void
    {
        $this->createSqliteTable('migrations');
        $this->pdo->exec(
            "INSERT INTO migrations (name, batch, applied_at) VALUES ('20260501000000_create_users_table', 1, '2026-03-29 02:30:00')",
        );
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $records = new MigrationHistory($this->connection)->records();
        } finally {
            date_default_timezone_set($timezone);
        }

        $this->assertCount(1, $records);
        $this->assertSame('2026-03-29 03:30:00 CEST', $records[0]['appliedAt']->format('Y-m-d H:i:s T'));
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function timesNotInTheColumnShape(): array
    {
        return [
            'ISO 8601 separator' => ['2026-09-14T10:30:05', "string '2026-09-14T10:30:05'"],
            'day past the month' => ['2026-02-30 10:30:05', "string '2026-02-30 10:30:05'"],
            'date only'          => ['2026-09-14', "string '2026-09-14'"],
            'zero date'          => ['0000-00-00 00:00:00', "string '0000-00-00 00:00:00'"],
            'unpadded month'     => ['2026-9-14 10:30:05', "string '2026-9-14 10:30:05'"],
            'null'               => [null, 'null'],
        ];
    }

    #[DataProvider('timesNotInTheColumnShape')]
    public function testRecordsRefusesATimeNotInTheColumnShape(?string $appliedAt, string $described): void
    {
        $this->createSqliteTable('migrations');
        $statement = $this->pdo->prepare('INSERT INTO migrations (name, batch, applied_at) VALUES (?, 1, ?)');
        $this->assertNotFalse($statement);
        $statement->execute(['20260501000000_create_users_table', $appliedAt]);

        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            fn () => new MigrationHistory($this->connection)->records(),
        );

        $this->assertSame(
            'Migration history applied_at must be a time written as Y-m-d H:i:s, got ' . $described . '.',
            $thrown->getMessage(),
        );
    }

    public function testDeleteRemovesOnlyTheNamedMigration(): void
    {
        $this->createSqliteTable('migrations');
        $history = new MigrationHistory($this->connection);
        $history->record('20260501000000_create_users_table', 1);
        $history->record('20260502000000_create_posts_table', 1);

        $history->delete('20260501000000_create_users_table');

        $this->assertSame(['20260502000000_create_posts_table'], $history->appliedNames());
    }

    public function testRecordWritesTheNameAndBatch(): void
    {
        $this->createSqliteTable('migrations');

        new MigrationHistory($this->connection)->record('20260501000000_create_users_table', 1);

        $this->assertSame(
            [['name' => '20260501000000_create_users_table', 'batch' => 1]],
            $this->connection->query('SELECT name, batch FROM migrations')->asArray(),
        );
    }

    public function testReadsAndWritesGoToTheConfiguredTableWithThePrefix(): void
    {
        $this->createSqliteTable('app_schema_history');
        $this->connection->setGrammar(new Grammar('app_'));
        $this->connection->setMigrationsTable('schema_history');
        $history = new MigrationHistory($this->connection);

        $history->record('20260501000000_create_users_table', 1);

        $history->record('20260502000000_create_posts_table', 1);
        $history->delete('20260502000000_create_posts_table');

        $this->assertSame(['20260501000000_create_users_table'], $history->appliedNames());
        $this->assertSame(['20260501000000_create_users_table'], $history->namesInLastBatch());
        $this->assertSame(['20260501000000_create_users_table'], $history->lastNames(2));
        $this->assertSame(1, $history->lastBatch());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function batchesBelowOne(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-1],
        ];
    }

    #[DataProvider('batchesBelowOne')]
    public function testRecordRefusesABatchBelowOne(int $batch): void
    {
        $this->createSqliteTable('migrations');

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new MigrationHistory($this->connection)->record('20260501000000_create_users_table', $batch),
        );

        $this->assertSame('Migration batch must be 1 or greater, got ' . $batch . '.', $thrown->getMessage());
        $this->assertSame([], $this->pdo->calls);
    }

    public function testAppliedNamesRefusesANameThatIsNotAString(): void
    {
        $this->createSqliteTable('migrations', nameType: '');
        $this->pdo->exec('INSERT INTO migrations (name, batch) VALUES (20260501, 1)');

        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            fn () => new MigrationHistory($this->connection)->appliedNames(),
        );

        $this->assertSame('Migration history name must be a string, got int.', $thrown->getMessage());
    }

    public function testNamesInLastBatchRefusesANameThatIsNotAString(): void
    {
        $this->createSqliteTable('migrations', nameType: '');
        $this->pdo->exec('INSERT INTO migrations (name, batch) VALUES (20260501, 1)');

        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            fn () => new MigrationHistory($this->connection)->namesInLastBatch(),
        );

        $this->assertSame('Migration history name must be a string, got int.', $thrown->getMessage());
    }

    public function testLastNamesRefusesABatchThatIsNotAnInteger(): void
    {
        $this->createSqliteTable('migrations', batchType: '');
        $this->pdo->exec("INSERT INTO migrations (name, batch) VALUES ('20260501000000_create_users_table', '2x')");

        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            fn () => new MigrationHistory($this->connection)->lastNames(1),
        );

        $this->assertSame('Migration history batch must be an integer, got string.', $thrown->getMessage());
    }

    public function testLastBatchRefusesABatchThatIsNotAnInteger(): void
    {
        $this->createSqliteTable('migrations', batchType: '');
        $this->pdo->exec("INSERT INTO migrations (name, batch) VALUES ('20260501000000_create_users_table', '2x')");

        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            fn () => new MigrationHistory($this->connection)->lastBatch(),
        );

        $this->assertSame('Migration history batch must be an integer, got string.', $thrown->getMessage());
    }
}
