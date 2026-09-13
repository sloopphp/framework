<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Migration;

use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Migration\Migrator;
use Sloop\Database\Query\Grammar;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Database\Stub\MigrationSqlite;
use UnexpectedValueException;

/*
 * Every fixture declares a class of its own name. A class stays declared for
 * the rest of the process, so no two tests here may reuse one.
 */
final class MigratorTest extends TestCase
{
    use ThrowsAssertions;

    private string $directory;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sloop_test_migrator_' . uniqid();
        mkdir($this->directory);

        $this->connection = new Connection(
            new MigrationSqlite('sqlite::memory:', null, null, [
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]),
            'migrator_test',
        );
    }

    protected function tearDown(): void
    {
        $paths = glob($this->directory . '/*');

        foreach ($paths === false ? [] : $paths as $path) {
            unlink($path);
        }

        rmdir($this->directory);
    }

    private function writeFile(string $fileName, string $source): void
    {
        file_put_contents($this->directory . '/' . $fileName, $source);
    }

    private function writeMigration(string $fileName, string $className, string $up): void
    {
        $this->writeFile(
            $fileName,
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
            . '    }' . "\n"
            . '}' . "\n",
        );
    }

    /**
     * @return list<array<mixed>>
     */
    private function history(string $table = 'migrations'): array
    {
        return $this->connection->query('SELECT name, batch FROM ' . $table . ' ORDER BY id')->asArray();
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $names = [];

        foreach ($this->connection->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->asArray() as $row) {
            $this->assertIsString($row['name']);
            $names[] = $row['name'];
        }

        return array_values(array_diff($names, ['sqlite_sequence']));
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->connection, $this->directory);
    }

    public function testConstructorRefusesADirectoryThatDoesNotExist(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new Migrator($this->connection, $this->directory . '/missing'),
        );
    }

    public function testRunOnAnEmptyDirectoryCreatesTheHistoryTableAndAppliesNothing(): void
    {
        $this->assertSame(0, $this->migrator()->run());

        $this->assertSame(['migrations'], $this->tables());
        $this->assertSame([], $this->history());
    }

    public function testRunAppliesPendingMigrationsInFileOrderAsOneBatch(): void
    {
        $this->writeMigration(
            '20260502000000_migrator_add_posts.php',
            'MigratorAddPosts',
            '$db->statement(\'CREATE TABLE posts (user_id INTEGER REFERENCES users (id))\');',
        );
        $this->writeMigration(
            '20260501000000_migrator_add_users.php',
            'MigratorAddUsers',
            '$db->statement(\'CREATE TABLE users (id INTEGER PRIMARY KEY)\');',
        );

        $this->assertSame(2, $this->migrator()->run());

        $this->assertSame(['migrations', 'posts', 'users'], $this->tables());
        $this->assertSame(
            [
                ['name' => '20260501000000_migrator_add_users', 'batch' => 1],
                ['name' => '20260502000000_migrator_add_posts', 'batch' => 1],
            ],
            $this->history(),
        );
    }

    public function testRunSkipsMigrationsAlreadyApplied(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_add_tags.php',
            'MigratorAddTags',
            '$db->statement(\'CREATE TABLE tags (id INTEGER PRIMARY KEY)\');',
        );
        $this->migrator()->run();

        $this->assertSame(0, $this->migrator()->run());

        $this->assertSame([['name' => '20260501000000_migrator_add_tags', 'batch' => 1]], $this->history());
    }

    public function testRunAppliesMigrationsAddedLaterAsTheNextBatch(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_add_roles.php',
            'MigratorAddRoles',
            '$db->statement(\'CREATE TABLE roles (id INTEGER PRIMARY KEY)\');',
        );
        $this->migrator()->run();

        $this->writeMigration(
            '20260601000000_migrator_add_grants.php',
            'MigratorAddGrants',
            '$db->statement(\'CREATE TABLE grants (id INTEGER PRIMARY KEY)\');',
        );
        $this->writeMigration(
            '20260401000000_migrator_add_teams.php',
            'MigratorAddTeams',
            '$db->statement(\'CREATE TABLE teams (id INTEGER PRIMARY KEY)\');',
        );

        $this->assertSame(2, $this->migrator()->run());

        $this->assertSame(
            [
                ['name' => '20260501000000_migrator_add_roles', 'batch' => 1],
                ['name' => '20260401000000_migrator_add_teams', 'batch' => 2],
                ['name' => '20260601000000_migrator_add_grants', 'batch' => 2],
            ],
            $this->history(),
        );
    }

    public function testRunStopsAtAFailingMigrationAndResumesFromItOnTheNextRun(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_add_accounts.php',
            'MigratorAddAccounts',
            '$db->statement(\'CREATE TABLE accounts (id INTEGER PRIMARY KEY)\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_seed_settings.php',
            'MigratorSeedSettings',
            '$db->statement(\'INSERT INTO settings (name) VALUES (\\\'locale\\\')\');',
        );
        $this->writeMigration(
            '20260503000000_migrator_add_invoices.php',
            'MigratorAddInvoices',
            '$db->statement(\'CREATE TABLE invoices (id INTEGER PRIMARY KEY)\');',
        );

        $this->assertThrows(DatabaseException::class, fn () => $this->migrator()->run());

        $this->assertSame(['accounts', 'migrations'], $this->tables());
        $this->assertSame([['name' => '20260501000000_migrator_add_accounts', 'batch' => 1]], $this->history());

        $this->connection->statement('CREATE TABLE settings (name TEXT)');

        $this->assertSame(2, $this->migrator()->run());

        $this->assertSame(['accounts', 'invoices', 'migrations', 'settings'], $this->tables());
        $this->assertSame(
            [
                ['name' => '20260501000000_migrator_add_accounts', 'batch' => 1],
                ['name' => '20260502000000_migrator_seed_settings', 'batch' => 2],
                ['name' => '20260503000000_migrator_add_invoices', 'batch' => 2],
            ],
            $this->history(),
        );
    }

    public function testRunPassesOnTheExceptionAMigrationThrows(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_throw_domain.php',
            'MigratorThrowDomain',
            'throw new \DomainException(\'Migration refused.\');',
        );

        $thrown = $this->assertThrows(DomainException::class, fn () => $this->migrator()->run());

        $this->assertSame('Migration refused.', $thrown->getMessage());
        $this->assertSame([], $this->history());
    }

    public function testRunHandsTheMigrationTheMigratorsConnection(): void
    {
        $this->connection->setGrammar(new Grammar('app_'));
        $this->connection->setMigrationsTable('schema_history');
        $this->writeMigration(
            '20260501000000_migrator_add_labels.php',
            'MigratorAddLabels',
            '$db->statement(\'CREATE TABLE \' . $db->quoteTable(\'labels\') . \' (id INTEGER PRIMARY KEY)\');',
        );

        $this->migrator()->run();

        $this->assertSame(['app_labels', 'app_schema_history'], $this->tables());
        $this->assertSame([['name' => '20260501000000_migrator_add_labels', 'batch' => 1]], $this->history('app_schema_history'));
    }

    public function testRunRefusesToStartInsideATransaction(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_inside_transaction.php',
            'MigratorInsideTransaction',
            '$db->statement(\'CREATE TABLE stray (id INTEGER PRIMARY KEY)\');',
        );
        $this->connection->begin();

        $thrown = $this->assertThrows(LogicException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Cannot run migrations inside a transaction: the first schema change would commit it.',
            $thrown->getMessage(),
        );
        $this->assertSame([], $this->tables());
    }

    public function testRunRollsBackAndRefusesATransactionAMigrationLeftOpen(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_add_notes.php',
            'MigratorAddNotes',
            '$db->statement(\'CREATE TABLE notes (body TEXT)\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_leave_open.php',
            'MigratorLeaveOpen',
            '$db->begin(); $db->statement(\'INSERT INTO notes (body) VALUES (\\\'draft\\\')\');',
        );
        $this->writeMigration(
            '20260503000000_migrator_after_open.php',
            'MigratorAfterOpen',
            '$db->statement(\'CREATE TABLE after_open (id INTEGER)\');',
        );

        $thrown = $this->assertThrows(LogicException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Migration 20260502000000_migrator_leave_open left a transaction open: it was rolled back and the '
            . 'migration was not recorded. Commit or roll back inside up().',
            $thrown->getMessage(),
        );
        $this->assertFalse($this->connection->inTransaction());
        $this->assertSame([], $this->connection->query('SELECT body FROM notes')->asArray());
        $this->assertSame(['migrations', 'notes'], $this->tables());
        $this->assertSame([['name' => '20260501000000_migrator_add_notes', 'batch' => 1]], $this->history());
    }

    public function testRunRollsBackATransactionAFailingMigrationLeftOpenSoTheNextRunCanStart(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_add_drafts.php',
            'MigratorAddDrafts',
            '$db->statement(\'CREATE TABLE drafts (body TEXT)\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_throw_while_open.php',
            'MigratorThrowWhileOpen',
            '$db->begin(); $db->statement(\'INSERT INTO drafts (body) VALUES (\\\'draft\\\')\'); '
            . 'if (!$db->query(\'SELECT name FROM sqlite_master WHERE name = \\\'ready\\\'\')->asArray()) { '
            . 'throw new \\DomainException(\'Not ready.\'); } $db->commit();',
        );
        $migrator = $this->migrator();

        $thrown = $this->assertThrows(DomainException::class, fn () => $migrator->run());

        $this->assertSame('Not ready.', $thrown->getMessage());
        $this->assertFalse($this->connection->inTransaction());
        $this->assertSame([], $this->connection->query('SELECT body FROM drafts')->asArray());

        $this->connection->statement('CREATE TABLE ready (id INTEGER)');

        $this->assertSame(1, $migrator->run());
        $this->assertSame([['body' => 'draft']], $this->connection->query('SELECT body FROM drafts')->asArray());
    }

    public function testRunReadsTheWholeDirectoryBeforeTouchingTheDatabase(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_before_bad_name.php',
            'MigratorBeforeBadName',
            '$db->statement(\'CREATE TABLE early (id INTEGER PRIMARY KEY)\');',
        );
        $this->writeFile('create_things.php', '<?php');

        $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());

        $this->assertSame([], $this->tables());
    }

    public function testRunRefusesAFileThatDoesNotDeclareItsClass(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_before_missing_class.php',
            'MigratorBeforeMissingClass',
            '$db->statement(\'CREATE TABLE before_missing (id INTEGER PRIMARY KEY)\');',
        );
        $path = $this->directory . '/20260502000000_migrator_missing_class.php';
        $this->writeMigration('20260502000000_migrator_missing_class.php', 'MigratorMisnamedClass', '');

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Migration file ' . $path . ' must declare class MigratorMissingClass, without a namespace.',
            $thrown->getMessage(),
        );
        $this->assertSame([['name' => '20260501000000_migrator_before_missing_class', 'batch' => 1]], $this->history());
    }

    public function testRunRefusesAClassAlreadyDeclaredElsewhere(): void
    {
        $elsewhere = sys_get_temp_dir() . '/sloop_test_migrator_elsewhere_' . uniqid() . '.php';
        file_put_contents($elsewhere, '<?php final class MigratorDeclaredElsewhere {}');
        require_once $elsewhere;
        unlink($elsewhere);
        $path = $this->directory . '/20260501000000_migrator_declared_elsewhere.php';
        $this->writeMigration('20260501000000_migrator_declared_elsewhere.php', 'MigratorDeclaredElsewhere', '');

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Class MigratorDeclaredElsewhere of migration file ' . $path . ' is already declared in ' . $elsewhere . '.',
            $thrown->getMessage(),
        );
        $this->assertSame([], $this->history());
    }

    public function testRunLoadsTheFileRatherThanAutoloadingItsClass(): void
    {
        $elsewhere = $this->directory . '/elsewhere.inc';
        file_put_contents($elsewhere, '<?php final class MigratorAutoloadable {}');
        $autoload = static function (string $class) use ($elsewhere): void {
            if ($class === 'MigratorAutoloadable') {
                require_once $elsewhere;
            }
        };
        $this->writeMigration(
            '20260501000000_migrator_autoloadable.php',
            'MigratorAutoloadable',
            '$db->statement(\'CREATE TABLE autoloadable (id INTEGER PRIMARY KEY)\');',
        );
        spl_autoload_register($autoload);

        try {
            $this->assertSame(1, $this->migrator()->run());
        } finally {
            spl_autoload_unregister($autoload);
        }

        $this->assertSame(['autoloadable', 'migrations'], $this->tables());
    }

    public function testRunDoesNotAutoloadAClassItsFileFailedToDeclare(): void
    {
        $elsewhere = $this->directory . '/elsewhere.inc';
        file_put_contents($elsewhere, '<?php final class MigratorOnlyAutoloadable {}');
        $autoload = static function (string $class) use ($elsewhere): void {
            if ($class === 'MigratorOnlyAutoloadable') {
                require_once $elsewhere;
            }
        };
        $path     = $this->directory . '/20260501000000_migrator_only_autoloadable.php';
        $this->writeFile('20260501000000_migrator_only_autoloadable.php', '<?php');
        spl_autoload_register($autoload);

        try {
            $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());
        } finally {
            spl_autoload_unregister($autoload);
        }

        $this->assertSame(
            'Migration file ' . $path . ' must declare class MigratorOnlyAutoloadable, without a namespace.',
            $thrown->getMessage(),
        );
    }

    public function testRunRefusesAClassNameTakenByABuiltInClass(): void
    {
        $path = $this->directory . '/20260501000000_exception.php';
        $this->writeMigration('20260501000000_exception.php', 'Exception', '');

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Class Exception of migration file ' . $path . ' is already declared in a built-in extension.',
            $thrown->getMessage(),
        );
    }

    public function testRunRefusesAClassThatDoesNotExtendMigration(): void
    {
        $path = $this->directory . '/20260501000000_migrator_not_a_migration.php';
        $this->writeFile(
            '20260501000000_migrator_not_a_migration.php',
            '<?php final class MigratorNotAMigration { public function up(): void {} }',
        );

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Class MigratorNotAMigration in migration file ' . $path . ' must extend Sloop\Database\Migration\Migration.',
            $thrown->getMessage(),
        );
    }

    public function testRunRefusesAnAbstractMigration(): void
    {
        $path = $this->directory . '/20260501000000_migrator_abstract.php';
        $this->writeFile(
            '20260501000000_migrator_abstract.php',
            '<?php abstract class MigratorAbstract extends Sloop\Database\Migration\Migration {}',
        );

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Class MigratorAbstract in migration file ' . $path
            . ' must be a concrete class whose constructor takes no required arguments.',
            $thrown->getMessage(),
        );
    }

    public function testRunRefusesAMigrationWhoseConstructorRequiresArguments(): void
    {
        $path = $this->directory . '/20260501000000_migrator_needs_argument.php';
        $this->writeFile(
            '20260501000000_migrator_needs_argument.php',
            '<?php use Sloop\Database\Connection; final class MigratorNeedsArgument extends Sloop\Database\Migration\Migration {'
            . ' public function __construct(string $table) {}'
            . ' public function up(Connection $db): void {} public function down(Connection $db): void {} }',
        );

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Class MigratorNeedsArgument in migration file ' . $path
            . ' must be a concrete class whose constructor takes no required arguments.',
            $thrown->getMessage(),
        );
    }

    public function testRunAcceptsAMigrationWhoseConstructorArgumentsAreOptional(): void
    {
        $this->writeFile(
            '20260501000000_migrator_optional_argument.php',
            '<?php use Sloop\Database\Connection; final class MigratorOptionalArgument extends Sloop\Database\Migration\Migration {'
            . ' public function __construct(private string $table = \'optional\') {}'
            . ' public function up(Connection $db): void { $db->statement(\'CREATE TABLE \' . $this->table . \' (id INTEGER)\'); }'
            . ' public function down(Connection $db): void {} }',
        );

        $this->assertSame(1, $this->migrator()->run());

        $this->assertSame(['migrations', 'optional'], $this->tables());
    }
}
