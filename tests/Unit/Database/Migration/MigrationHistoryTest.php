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

    public function testAppliedNamesIsEmptyBeforeAnyMigrationRuns(): void
    {
        $this->createSqliteTable('migrations');

        $this->assertSame([], new MigrationHistory($this->connection)->appliedNames());
    }

    public function testAppliedNamesOrdersByBatchThenName(): void
    {
        $this->createSqliteTable('migrations');
        $history = new MigrationHistory($this->connection);

        $history->record('20260601000000_create_posts_table', 2);
        $history->record('20260501000000_create_users_table', 1);
        $history->record('20260502000000_add_email_to_users', 2);
        $history->record('20260401000000_create_roles_table', 1);

        $this->assertSame(
            [
                '20260401000000_create_roles_table',
                '20260501000000_create_users_table',
                '20260502000000_add_email_to_users',
                '20260601000000_create_posts_table',
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

        $this->assertSame(['20260501000000_create_users_table'], $history->appliedNames());
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
