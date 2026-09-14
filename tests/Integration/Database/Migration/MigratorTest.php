<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database\Migration;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Sloop\Database\CastMode;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Migration\Migrator;
use Sloop\Tests\Support\MigrationIntegrationTestCase;
use Sloop\Tests\Support\ThrowsAssertions;

/*
 * Every fixture declares a class of its own name. A class stays declared for
 * the rest of the process, so no two tests here may reuse one.
 */
final class MigratorTest extends MigrationIntegrationTestCase
{
    use ThrowsAssertions;

    private const string HISTORY_TABLE = 'test_migration_history';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sloop_test_migrator_' . uniqid();
        mkdir($this->directory);
        $this->connection->setMigrationsTable(self::HISTORY_TABLE);
    }

    protected function tearDown(): void
    {
        $paths = glob($this->directory . '/*');

        foreach ($paths === false ? [] : $paths as $path) {
            unlink($path);
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    private function writeMigration(string $fileName, string $className, string $up, string $down = ''): void
    {
        file_put_contents(
            $this->directory . '/' . $fileName,
            '<?php' . "\n\n"
            . 'use Sloop\Database\Connection;' . "\n"
            . 'use Sloop\Database\Migration\Migration;' . "\n\n"
            . 'final class ' . $className . ' extends Migration' . "\n"
            . '{' . "\n"
            . '    public function up(Connection $db): void' . "\n"
            . '    {' . "\n"
            . '        ' . $up . "\n"
            . '    }' . "\n\n"
            . '    public function down(Connection $db): void' . "\n"
            . '    {' . "\n"
            . '        ' . $down . "\n"
            . '    }' . "\n"
            . '}' . "\n",
        );
    }

    /**
     * @return list<array<mixed>>
     */
    private function history(): array
    {
        return $this->connection->select('name', 'batch')
            ->from(self::HISTORY_TABLE)
            ->orderBy('id')
            ->get();
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->connection, $this->directory);
    }

    public function testRunAppliesSchemaChangesAndRecordsThem(): void
    {
        $this->writeMigration(
            '20260501000000_it_create_users.php',
            'ItCreateUsers',
            '$db->statement(\'CREATE TABLE test_migration_users (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
        );
        $this->writeMigration(
            '20260502000000_it_add_email_to_users.php',
            'ItAddEmailToUsers',
            '$db->statement(\'ALTER TABLE test_migration_users ADD COLUMN email VARCHAR(255) NULL\');',
        );

        $this->assertSame(2, $this->migrator()->run());
        $this->assertSame(0, $this->migrator()->run());

        $this->assertSame([self::HISTORY_TABLE, 'test_migration_users'], $this->migrationTableNames());
        $this->assertSame(
            [
                ['name' => '20260501000000_it_create_users', 'batch' => 1],
                ['name' => '20260502000000_it_add_email_to_users', 'batch' => 1],
            ],
            $this->history(),
        );
    }

    public function testRunResumesFromTheMigrationThatFailed(): void
    {
        $this->writeMigration(
            '20260501000000_it_create_accounts.php',
            'ItCreateAccounts',
            '$db->statement(\'CREATE TABLE test_migration_accounts (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
        );
        $this->writeMigration(
            '20260502000000_it_seed_settings.php',
            'ItSeedSettings',
            '$db->statement(\'INSERT INTO test_migration_settings (name) VALUES (\\\'locale\\\')\');',
        );

        $this->assertThrows(DatabaseException::class, fn () => $this->migrator()->run());
        $this->assertSame([['name' => '20260501000000_it_create_accounts', 'batch' => 1]], $this->history());

        $this->connection->statement('CREATE TABLE test_migration_settings (name VARCHAR(32) NOT NULL)');

        $this->assertSame(1, $this->migrator()->run());
        $this->assertSame(
            [
                ['name' => '20260501000000_it_create_accounts', 'batch' => 1],
                ['name' => '20260502000000_it_seed_settings', 'batch' => 2],
            ],
            $this->history(),
        );
    }

    public function testASchemaChangeStaysAppliedWhenItsMigrationFailsAfterIt(): void
    {
        $this->writeMigration(
            '20260501000000_it_create_then_fail.php',
            'ItCreateThenFail',
            '$db->statement(\'CREATE TABLE test_migration_partial (id INT UNSIGNED NOT NULL PRIMARY KEY)\'); '
            . '$db->statement(\'INSERT INTO test_migration_absent (id) VALUES (1)\');',
        );

        $this->assertThrows(DatabaseException::class, fn () => $this->migrator()->run());

        $this->assertSame([self::HISTORY_TABLE, 'test_migration_partial'], $this->migrationTableNames());
        $this->assertSame([], $this->history());
    }

    public function testAMigrationThatLeavesATransactionOpenIsRolledBackAndNotRecorded(): void
    {
        $this->writeMigration(
            '20260501000000_it_leave_open.php',
            'ItLeaveOpen',
            '$db->statement(\'CREATE TABLE test_migration_left_open (id INT UNSIGNED NOT NULL PRIMARY KEY)\'); '
            . '$db->begin(); '
            . '$db->statement(\'INSERT INTO test_migration_left_open (id) VALUES (1)\');',
        );

        $this->assertThrows(LogicException::class, fn () => $this->migrator()->run());

        $this->assertFalse($this->connection->inTransaction());
        $this->assertSame([], $this->connection->select()->from('test_migration_left_open')->get());
        $this->assertSame([], $this->history());
    }

    public function testATransactionInsideUpRollsBackTheDataChangesOfAFailedMigration(): void
    {
        $this->connection->statement('CREATE TABLE test_migration_roles (name VARCHAR(32) NOT NULL PRIMARY KEY)');
        $this->writeMigration(
            '20260501000000_it_seed_roles.php',
            'ItSeedRoles',
            '$db->transaction(function () use ($db): void { '
            . '$db->statement(\'INSERT INTO test_migration_roles (name) VALUES (\\\'admin\\\')\'); '
            . '$db->statement(\'INSERT INTO test_migration_roles (name) VALUES (\\\'admin\\\')\'); '
            . '});',
        );

        $this->assertThrows(DatabaseException::class, fn () => $this->migrator()->run());

        $this->assertSame([], $this->connection->select()->from('test_migration_roles')->get());
        $this->assertSame([], $this->history());
    }

    public function testASchemaChangeInsideATransactionFailsTheMigration(): void
    {
        $this->writeMigration(
            '20260501000000_it_create_inside_transaction.php',
            'ItCreateInsideTransaction',
            '$db->transaction(function () use ($db): void { '
            . '$db->statement(\'CREATE TABLE test_migration_committed (id INT UNSIGNED NOT NULL PRIMARY KEY)\'); '
            . '});',
        );

        $this->assertThrows(DatabaseException::class, fn () => $this->migrator()->run());

        $this->assertSame(['test_migration_committed', self::HISTORY_TABLE], $this->migrationTableNames());
        $this->assertSame([], $this->history());
    }

    public function testRollbackUndoesABatchInTheReverseOfTheOrderItRan(): void
    {
        $this->writeMigration(
            '20260501000000_it_rb1x.php',
            'ItRb1x',
            '$db->statement(\'CREATE TABLE test_migration_orders (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE test_migration_orders\');',
        );
        $this->writeMigration(
            '20260501000000_it_rb_x.php',
            'ItRbX',
            '$db->statement(\'ALTER TABLE test_migration_orders ADD COLUMN total INT NULL\');',
            '$db->statement(\'ALTER TABLE test_migration_orders DROP COLUMN total\');',
        );
        $this->assertSame(2, $this->migrator()->run());

        $this->assertSame(2, $this->migrator()->rollback());

        $this->assertSame([self::HISTORY_TABLE], $this->migrationTableNames());
        $this->assertSame([], $this->history());
    }

    public function testRollbackWithStepsUndoesPartOfABatchAndRunAppliesItAgain(): void
    {
        $this->writeMigration(
            '20260501000000_it_rb_create_shops.php',
            'ItRbCreateShops',
            '$db->statement(\'CREATE TABLE test_migration_shops (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE test_migration_shops\');',
        );
        $this->migrator()->run();
        $this->writeMigration(
            '20260601000000_it_rb_create_items.php',
            'ItRbCreateItems',
            '$db->statement(\'CREATE TABLE test_migration_items (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE test_migration_items\');',
        );
        $this->writeMigration(
            '20260701000000_it_rb_add_price_to_items.php',
            'ItRbAddPriceToItems',
            '$db->statement(\'ALTER TABLE test_migration_items ADD COLUMN price INT NULL\');',
            '$db->statement(\'ALTER TABLE test_migration_items DROP COLUMN price\');',
        );
        $this->migrator()->run();

        $this->assertSame(1, $this->migrator()->rollback(1));

        $this->assertSame(
            [['name' => 'id']],
            $this->connection->query(
                'SELECT column_name AS name FROM information_schema.columns '
                    . 'WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
                ['test_migration_items'],
            )->asArray(),
        );
        $this->assertSame(
            [
                ['name' => '20260501000000_it_rb_create_shops', 'batch' => 1],
                ['name' => '20260601000000_it_rb_create_items', 'batch' => 2],
            ],
            $this->history(),
        );

        $this->assertSame(1, $this->migrator()->run());
        $this->assertSame(
            [
                ['name' => '20260501000000_it_rb_create_shops', 'batch' => 1],
                ['name' => '20260601000000_it_rb_create_items', 'batch' => 2],
                ['name' => '20260701000000_it_rb_add_price_to_items', 'batch' => 3],
            ],
            $this->history(),
        );
    }

    public function testRollbackKeepsTheMigrationsUndoneBeforeOneThatFailsAndTheSchemaChangesItMade(): void
    {
        $this->writeMigration(
            '20260501000000_it_rb_create_carts.php',
            'ItRbCreateCarts',
            '$db->statement(\'CREATE TABLE test_migration_carts (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE test_migration_carts\');',
        );
        $this->writeMigration(
            '20260502000000_it_rb_create_lines.php',
            'ItRbCreateLines',
            '$db->statement(\'CREATE TABLE test_migration_lines (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE test_migration_lines\'); $db->statement(\'DROP TABLE test_migration_absent\');',
        );
        $this->writeMigration(
            '20260503000000_it_rb_create_coupons.php',
            'ItRbCreateCoupons',
            '$db->statement(\'CREATE TABLE test_migration_coupons (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE test_migration_coupons\');',
        );
        $this->migrator()->run();

        $this->assertThrows(DatabaseException::class, fn () => $this->migrator()->rollback());

        $this->assertSame(['test_migration_carts', self::HISTORY_TABLE], $this->migrationTableNames());
        $this->assertSame(
            [
                ['name' => '20260501000000_it_rb_create_carts', 'batch' => 1],
                ['name' => '20260502000000_it_rb_create_lines', 'batch' => 1],
            ],
            $this->history(),
        );
    }

    /**
     * @return array<string, array{CastMode}>
     */
    public static function castModes(): array
    {
        return [
            'off'      => [CastMode::Off],
            'datetime' => [CastMode::Datetime],
        ];
    }

    #[DataProvider('castModes')]
    public function testStatusReadsTheBatchAndTimeOfAppliedMigrationsWhateverThePoolCasts(CastMode $castMode): void
    {
        $this->connection->setCastMode($castMode);
        $suffix = $castMode->name;
        $this->writeMigration(
            '20260501000000_it_st_create_venues_' . strtolower($suffix) . '.php',
            'ItStCreateVenues' . $suffix,
            '$db->statement(\'CREATE TABLE test_migration_venues (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
        );
        $this->migrator()->run();
        $this->writeMigration('20260601000000_it_st_create_stages_' . strtolower($suffix) . '.php', 'ItStCreateStages' . $suffix, '');
        $this->connection->statement(
            'UPDATE ' . self::HISTORY_TABLE . ' SET applied_at = \'2026-09-14 10:30:05\'',
        );

        $statuses = $this->migrator()->status();

        $this->assertCount(2, $statuses);
        $this->assertSame(1, $statuses[0]->batch);
        $this->assertNotNull($statuses[0]->appliedAt);
        $this->assertSame('2026-09-14 10:30:05', $statuses[0]->appliedAt->format('Y-m-d H:i:s'));
        $this->assertTrue($statuses[0]->hasFile);
        $this->assertNull($statuses[1]->batch);
        $this->assertNull($statuses[1]->appliedAt);
    }

    public function testResetUndoesEverySchemaChangeAcrossBatches(): void
    {
        $this->writeMigration(
            '20260501000000_it_rs_create_teams.php',
            'ItRsCreateTeams',
            '$db->statement(\'CREATE TABLE test_migration_teams (id INT UNSIGNED NOT NULL PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE test_migration_teams\');',
        );
        $this->migrator()->run();
        $this->writeMigration(
            '20260601000000_it_rs_add_name_to_teams.php',
            'ItRsAddNameToTeams',
            '$db->statement(\'ALTER TABLE test_migration_teams ADD COLUMN name VARCHAR(32) NULL\');',
            '$db->statement(\'ALTER TABLE test_migration_teams DROP COLUMN name\');',
        );
        $this->migrator()->run();

        $this->assertSame(2, $this->migrator()->reset());

        $this->assertSame([self::HISTORY_TABLE], $this->migrationTableNames());
        $this->assertSame([], $this->history());
    }
}
