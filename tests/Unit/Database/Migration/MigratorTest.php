<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Migration;

use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Migration\MigrationStatus;
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

    private MigrationSqlite $pdo;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sloop_test_migrator_' . uniqid();
        mkdir($this->directory);

        $this->pdo = new MigrationSqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->connection = new Connection($this->pdo, 'migrator_test');
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

    private function writeMigration(string $fileName, string $className, string $up, string $down = ''): void
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
            . '        ' . $down . "\n"
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

    public function testRunPassesOnTheMigrationsExceptionWhenTheRollbackFails(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_throw_rollback_fails.php',
            'MigratorThrowRollbackFails',
            '$db->begin(); throw new \\DomainException(\'Migration failed first.\');',
        );
        $this->pdo->failRollBack = true;

        $thrown = $this->assertThrows(DomainException::class, fn () => $this->migrator()->run());

        $this->assertSame('Migration failed first.', $thrown->getMessage());
        $this->assertSame([], $this->history());
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
        $path = $this->directory . \DIRECTORY_SEPARATOR . '20260502000000_migrator_missing_class.php';
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
        $declaredIn = realpath($elsewhere);
        unlink($elsewhere);
        $this->assertIsString($declaredIn);
        $path = $this->directory . \DIRECTORY_SEPARATOR . '20260501000000_migrator_declared_elsewhere.php';
        $this->writeMigration('20260501000000_migrator_declared_elsewhere.php', 'MigratorDeclaredElsewhere', '');

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Class MigratorDeclaredElsewhere of migration file ' . $path . ' is already declared in ' . $declaredIn . '.',
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
        $path     = $this->directory . \DIRECTORY_SEPARATOR . '20260501000000_migrator_only_autoloadable.php';
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
        $path = $this->directory . \DIRECTORY_SEPARATOR . '20260501000000_exception.php';
        $this->writeMigration('20260501000000_exception.php', 'Exception', '');

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->run());

        $this->assertSame(
            'Class Exception of migration file ' . $path . ' is already declared in a built-in extension.',
            $thrown->getMessage(),
        );
    }

    public function testRunRefusesAClassThatDoesNotExtendMigration(): void
    {
        $path = $this->directory . \DIRECTORY_SEPARATOR . '20260501000000_migrator_not_a_migration.php';
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
        $path = $this->directory . \DIRECTORY_SEPARATOR . '20260501000000_migrator_abstract.php';
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
        $path = $this->directory . \DIRECTORY_SEPARATOR . '20260501000000_migrator_needs_argument.php';
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

    public function testRollbackUndoesTheLastBatchInReverseOrder(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_owners.php',
            'MigratorRbAddOwners',
            '$db->statement(\'CREATE TABLE owners (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE owners\');',
        );
        $this->migrator()->run();
        $this->writeMigration(
            '20260601000000_migrator_rb_add_pets.php',
            'MigratorRbAddPets',
            '$db->statement(\'CREATE TABLE pets (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'INSERT INTO undone (name) VALUES (\\\'pets\\\')\'); $db->statement(\'DROP TABLE pets\');',
        );
        $this->writeMigration(
            '20260602000000_migrator_rb_add_toys.php',
            'MigratorRbAddToys',
            '$db->statement(\'CREATE TABLE toys (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'INSERT INTO undone (name) VALUES (\\\'toys\\\')\'); $db->statement(\'DROP TABLE toys\');',
        );
        $this->migrator()->run();
        $this->connection->statement('CREATE TABLE undone (name TEXT)');

        $this->assertSame(2, $this->migrator()->rollback());

        $this->assertSame(
            [['name' => 'toys'], ['name' => 'pets']],
            $this->connection->query('SELECT name FROM undone ORDER BY rowid')->asArray(),
        );
        $this->assertSame(['migrations', 'owners', 'undone'], $this->tables());
        $this->assertSame([['name' => '20260501000000_migrator_rb_add_owners', 'batch' => 1]], $this->history());
    }

    public function testRollbackWithStepsUndoesThatManyMigrationsCountingBackAcrossBatches(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_farms.php',
            'MigratorRbAddFarms',
            '$db->statement(\'CREATE TABLE farms (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE farms\');',
        );
        $this->migrator()->run();
        $this->writeMigration(
            '20260601000000_migrator_rb_add_barns.php',
            'MigratorRbAddBarns',
            '$db->statement(\'CREATE TABLE barns (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE barns\');',
        );
        $this->writeMigration(
            '20260602000000_migrator_rb_add_silos.php',
            'MigratorRbAddSilos',
            '$db->statement(\'CREATE TABLE silos (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE silos\');',
        );
        $this->migrator()->run();

        $this->assertSame(1, $this->migrator()->rollback(1));

        $this->assertSame(['barns', 'farms', 'migrations'], $this->tables());
        $this->assertSame(
            [
                ['name' => '20260501000000_migrator_rb_add_farms', 'batch' => 1],
                ['name' => '20260601000000_migrator_rb_add_barns', 'batch' => 2],
            ],
            $this->history(),
        );

        $this->assertSame(2, $this->migrator()->rollback(2));

        $this->assertSame(['migrations'], $this->tables());
        $this->assertSame([], $this->history());
    }

    public function testRollbackWithMoreStepsThanRecordedUndoesEveryMigration(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_ponds.php',
            'MigratorRbAddPonds',
            '$db->statement(\'CREATE TABLE ponds (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE ponds\');',
        );
        $this->migrator()->run();

        $this->assertSame(1, $this->migrator()->rollback(5));

        $this->assertSame(['migrations'], $this->tables());
        $this->assertSame([], $this->history());
    }

    public function testRollbackWithNothingAppliedUndoesNothing(): void
    {
        $this->assertSame(0, $this->migrator()->rollback());
        $this->assertSame(0, $this->migrator()->rollback(3));

        $this->assertSame(['migrations'], $this->tables());
    }

    public function testRunAfterRollbackAppliesTheUndoneMigrationsAgainAsANewBatch(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_hives.php',
            'MigratorRbAddHives',
            '$db->statement(\'CREATE TABLE hives (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE hives\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_rb_add_combs.php',
            'MigratorRbAddCombs',
            '$db->statement(\'CREATE TABLE combs (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE combs\');',
        );
        $this->migrator()->run();
        $this->migrator()->rollback();

        $this->assertSame(2, $this->migrator()->run());

        $this->assertSame(['combs', 'hives', 'migrations'], $this->tables());
        $this->assertSame(
            [
                ['name' => '20260501000000_migrator_rb_add_hives', 'batch' => 1],
                ['name' => '20260502000000_migrator_rb_add_combs', 'batch' => 1],
            ],
            $this->history(),
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function stepsBelowOne(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-1],
        ];
    }

    #[DataProvider('stepsBelowOne')]
    public function testRollbackRefusesStepsBelowOne(int $steps): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, fn () => $this->migrator()->rollback($steps));

        $this->assertSame('Rollback steps must be 1 or greater, got ' . $steps . '.', $thrown->getMessage());
        $this->assertSame([], $this->tables());
    }

    public function testRollbackRefusesToStartInsideATransaction(): void
    {
        $this->connection->begin();

        $thrown = $this->assertThrows(LogicException::class, fn () => $this->migrator()->rollback());

        $this->assertSame(
            'Cannot roll back migrations inside a transaction: the first schema change would commit it.',
            $thrown->getMessage(),
        );
        $this->assertSame([], $this->tables());
    }

    public function testRollbackReadsTheWholeDirectoryBeforeUndoingAny(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_crates.php',
            'MigratorRbAddCrates',
            '$db->statement(\'CREATE TABLE crates (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE crates\');',
        );
        $this->migrator()->run();
        $this->writeFile('create_things.php', '<?php');

        $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->rollback());

        $this->assertSame(['crates', 'migrations'], $this->tables());
        $this->assertCount(1, $this->history());
    }

    public function testRollbackRefusesARecordedMigrationWhoseFileIsGoneBeforeUndoingAny(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_decks.php',
            'MigratorRbAddDecks',
            '$db->statement(\'CREATE TABLE decks (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE decks\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_rb_add_cards.php',
            'MigratorRbAddCards',
            '$db->statement(\'CREATE TABLE cards (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE cards\');',
        );
        $this->writeMigration(
            '20260503000000_migrator_rb_add_suits.php',
            'MigratorRbAddSuits',
            '$db->statement(\'CREATE TABLE suits (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE suits\');',
        );
        $this->migrator()->run();
        unlink($this->directory . '/20260501000000_migrator_rb_add_decks.php');
        unlink($this->directory . '/20260503000000_migrator_rb_add_suits.php');

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->rollback());

        $this->assertSame(
            'Migrations recorded as applied have no file in the migration directory: '
            . '20260503000000_migrator_rb_add_suits, 20260501000000_migrator_rb_add_decks.',
            $thrown->getMessage(),
        );
        $this->assertSame(['cards', 'decks', 'migrations', 'suits'], $this->tables());
        $this->assertCount(3, $this->history());
    }

    public function testRollbackRefusesAFileThatNoLongerDeclaresItsClassBeforeUndoingAny(): void
    {
        $this->writeMigration(
            '20260502000000_migrator_rb_add_pins.php',
            'MigratorRbAddPins',
            '$db->statement(\'CREATE TABLE pins (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE pins\');',
        );
        $this->connection->statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name TEXT NOT NULL UNIQUE, batch INTEGER NOT NULL, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->connection->statement('CREATE TABLE boards (id INTEGER PRIMARY KEY)');
        $this->connection->statement('CREATE TABLE pins (id INTEGER PRIMARY KEY)');
        $this->connection->statement(
            'INSERT INTO migrations (name, batch) VALUES '
            . '(\'20260501000000_migrator_rb_add_boards\', 1), (\'20260502000000_migrator_rb_add_pins\', 1)',
        );
        $path = $this->directory . \DIRECTORY_SEPARATOR . '20260501000000_migrator_rb_add_boards.php';
        $this->writeMigration('20260501000000_migrator_rb_add_boards.php', 'MigratorRbMisnamedBoards', '');

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->rollback());

        $this->assertSame(
            'Migration file ' . $path . ' must declare class MigratorRbAddBoards, without a namespace.',
            $thrown->getMessage(),
        );
        $this->assertSame(['boards', 'migrations', 'pins'], $this->tables());
        $this->assertCount(2, $this->history());
    }

    public function testRollbackStopsAtAFailingMigrationAndResumesFromItOnTheNextRollback(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_shelves.php',
            'MigratorRbAddShelves',
            '$db->statement(\'CREATE TABLE shelves (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE shelves\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_rb_seed_books.php',
            'MigratorRbSeedBooks',
            '',
            '$db->statement(\'DELETE FROM books\');',
        );
        $this->writeMigration(
            '20260503000000_migrator_rb_add_racks.php',
            'MigratorRbAddRacks',
            '$db->statement(\'CREATE TABLE racks (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE racks\');',
        );
        $this->migrator()->run();

        $this->assertThrows(DatabaseException::class, fn () => $this->migrator()->rollback());

        $this->assertSame(['migrations', 'shelves'], $this->tables());
        $this->assertSame(
            [
                ['name' => '20260501000000_migrator_rb_add_shelves', 'batch' => 1],
                ['name' => '20260502000000_migrator_rb_seed_books', 'batch' => 1],
            ],
            $this->history(),
        );

        $this->connection->statement('CREATE TABLE books (id INTEGER)');

        $this->assertSame(2, $this->migrator()->rollback());

        $this->assertSame(['books', 'migrations'], $this->tables());
        $this->assertSame([], $this->history());
    }

    public function testRollbackWithStepsCountsFromWhatIsStillRecordedWhenCalledAgainAfterAFailure(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_vaults.php',
            'MigratorRbAddVaults',
            '$db->statement(\'CREATE TABLE vaults (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE vaults\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_rb_add_coins.php',
            'MigratorRbAddCoins',
            '$db->statement(\'CREATE TABLE coins (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE coins\');',
        );
        $this->writeMigration(
            '20260503000000_migrator_rb_seed_ledgers.php',
            'MigratorRbSeedLedgers',
            '',
            '$db->statement(\'DELETE FROM ledgers\');',
        );
        $this->writeMigration(
            '20260504000000_migrator_rb_add_keys.php',
            'MigratorRbAddKeys',
            '$db->statement(\'CREATE TABLE keys (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE keys\');',
        );
        $this->migrator()->run();

        $this->assertThrows(DatabaseException::class, fn () => $this->migrator()->rollback(3));
        $this->assertSame(['coins', 'migrations', 'vaults'], $this->tables());

        $this->connection->statement('CREATE TABLE ledgers (id INTEGER)');

        $this->assertSame(2, $this->migrator()->rollback(2));

        $this->assertSame(['ledgers', 'migrations', 'vaults'], $this->tables());
        $this->assertSame([['name' => '20260501000000_migrator_rb_add_vaults', 'batch' => 1]], $this->history());
    }

    public function testRollbackRollsBackAndRefusesATransactionAMigrationLeftOpen(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_logs.php',
            'MigratorRbAddLogs',
            '$db->statement(\'CREATE TABLE logs (body TEXT)\');',
            '$db->statement(\'DROP TABLE logs\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_rb_leave_open.php',
            'MigratorRbLeaveOpen',
            '',
            '$db->begin(); $db->statement(\'INSERT INTO logs (body) VALUES (\\\'undone\\\')\');',
        );
        $this->migrator()->run();

        $thrown = $this->assertThrows(LogicException::class, fn () => $this->migrator()->rollback());

        $this->assertSame(
            'Migration 20260502000000_migrator_rb_leave_open left a transaction open: it was rolled back and the '
            . 'migration was left in the history. Commit or roll back inside down().',
            $thrown->getMessage(),
        );
        $this->assertFalse($this->connection->inTransaction());
        $this->assertSame([], $this->connection->query('SELECT body FROM logs')->asArray());
        $this->assertCount(2, $this->history());
    }

    public function testRollbackRollsBackATransactionAFailingMigrationLeftOpenSoTheNextRollbackCanStart(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_add_notices.php',
            'MigratorRbAddNotices',
            '$db->statement(\'CREATE TABLE notices (body TEXT)\');',
            '$db->statement(\'DROP TABLE notices\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_rb_throw_while_open.php',
            'MigratorRbThrowWhileOpen',
            '',
            '$db->begin(); $db->statement(\'INSERT INTO notices (body) VALUES (\\\'undone\\\')\'); '
            . 'if (!$db->query(\'SELECT name FROM sqlite_master WHERE name = \\\'ready\\\'\')->asArray()) { '
            . 'throw new \\DomainException(\'Not ready.\'); } $db->commit();',
        );
        $migrator = $this->migrator();
        $migrator->run();

        $thrown = $this->assertThrows(DomainException::class, fn () => $migrator->rollback());

        $this->assertSame('Not ready.', $thrown->getMessage());
        $this->assertFalse($this->connection->inTransaction());
        $this->assertSame([], $this->connection->query('SELECT body FROM notices')->asArray());

        $this->connection->statement('CREATE TABLE ready (id INTEGER)');

        $this->assertSame(2, $migrator->rollback());
        $this->assertSame([], $this->history());
    }

    public function testRollbackPassesOnTheMigrationsExceptionWhenTheRollbackFails(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rb_throw_rollback_fails.php',
            'MigratorRbThrowRollbackFails',
            '',
            '$db->begin(); throw new \\DomainException(\'Down failed first.\');',
        );
        $this->migrator()->run();
        $this->pdo->failRollBack = true;

        $thrown = $this->assertThrows(DomainException::class, fn () => $this->migrator()->rollback());

        $this->assertSame('Down failed first.', $thrown->getMessage());
        $this->assertCount(1, $this->history());
    }

    public function testRollbackHandsTheMigrationTheMigratorsConnection(): void
    {
        $this->connection->setGrammar(new Grammar('app_'));
        $this->connection->setMigrationsTable('schema_history');
        $this->writeMigration(
            '20260501000000_migrator_rb_add_badges.php',
            'MigratorRbAddBadges',
            '$db->statement(\'CREATE TABLE \' . $db->quoteTable(\'badges\') . \' (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE \' . $db->quoteTable(\'badges\'));',
        );
        $this->migrator()->run();

        $this->assertSame(1, $this->migrator()->rollback());

        $this->assertSame(['app_schema_history'], $this->tables());
        $this->assertSame([], $this->history('app_schema_history'));
    }

    public function testStatusListsAppliedAndPendingMigrationsInNameOrder(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_st_add_zones.php',
            'MigratorStAddZones',
            '$db->statement(\'CREATE TABLE zones (id INTEGER PRIMARY KEY)\');',
        );
        $this->migrator()->run();
        $this->writeMigration(
            '20260601000000_migrator_st_add_rooms.php',
            'MigratorStAddRooms',
            '$db->statement(\'CREATE TABLE rooms (id INTEGER PRIMARY KEY)\');',
        );
        $this->migrator()->run();
        $this->writeMigration(
            '20260701000000_migrator_st_add_seats.php',
            'MigratorStAddSeats',
            '$db->statement(\'CREATE TABLE seats (id INTEGER PRIMARY KEY)\');',
        );
        $this->writeMigration(
            '20260401000000_migrator_st_add_halls.php',
            'MigratorStAddHalls',
            '$db->statement(\'CREATE TABLE halls (id INTEGER PRIMARY KEY)\');',
        );

        $statuses = $this->migrator()->status();

        $this->assertSame(
            [
                ['20260401000000_migrator_st_add_halls', null, false, true],
                ['20260501000000_migrator_st_add_zones', 1, true, true],
                ['20260601000000_migrator_st_add_rooms', 2, true, true],
                ['20260701000000_migrator_st_add_seats', null, false, true],
            ],
            array_map(
                static fn (MigrationStatus $status): array => [
                    $status->name,
                    $status->batch,
                    $status->appliedAt !== null,
                    $status->hasFile,
                ],
                $statuses,
            ),
        );
        $this->assertSame(['migrations', 'rooms', 'zones'], $this->tables());
    }

    public function testStatusReadsTheTimeEachMigrationWasRecorded(): void
    {
        $this->writeMigration('20260501000000_migrator_st_timed.php', 'MigratorStTimed', '');
        $this->migrator()->run();
        $this->connection->statement('UPDATE migrations SET applied_at = \'2026-09-14 10:30:05\'');

        $statuses = $this->migrator()->status();

        $this->assertCount(1, $statuses);
        $this->assertNotNull($statuses[0]->appliedAt);
        $this->assertSame('2026-09-14 10:30:05', $statuses[0]->appliedAt->format('Y-m-d H:i:s'));
    }

    public function testStatusListsARecordedMigrationWhoseFileIsGone(): void
    {
        $this->writeMigration('20260501000000_migrator_st_gone.php', 'MigratorStGone', '');
        $this->writeMigration('20260502000000_migrator_st_kept.php', 'MigratorStKept', '');
        $this->migrator()->run();
        unlink($this->directory . '/20260501000000_migrator_st_gone.php');

        $statuses = $this->migrator()->status();

        $this->assertSame(
            [
                ['20260501000000_migrator_st_gone', 1, false],
                ['20260502000000_migrator_st_kept', 1, true],
            ],
            array_map(
                static fn (MigrationStatus $status): array => [$status->name, $status->batch, $status->hasFile],
                $statuses,
            ),
        );
    }

    public function testStatusOnAnEmptyDirectoryCreatesTheHistoryTableAndListsNothing(): void
    {
        $this->assertSame([], $this->migrator()->status());

        $this->assertSame(['migrations'], $this->tables());
    }

    public function testStatusRefusesToRunInsideATransaction(): void
    {
        $this->connection->begin();

        $thrown = $this->assertThrows(LogicException::class, fn () => $this->migrator()->status());

        $this->assertSame(
            'Cannot read migration status inside a transaction: creating the history table would commit it.',
            $thrown->getMessage(),
        );
        $this->assertSame([], $this->tables());
    }

    public function testStatusReadsTheWholeDirectoryBeforeTouchingTheDatabase(): void
    {
        $this->writeFile('create_things.php', '<?php');

        $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->status());

        $this->assertSame([], $this->tables());
    }

    public function testStatusReadsTheConfiguredHistoryTable(): void
    {
        $this->connection->setGrammar(new Grammar('app_'));
        $this->connection->setMigrationsTable('schema_history');
        $this->writeMigration('20260501000000_migrator_st_prefixed.php', 'MigratorStPrefixed', '');
        $this->migrator()->run();

        $statuses = $this->migrator()->status();

        $this->assertCount(1, $statuses);
        $this->assertSame(1, $statuses[0]->batch);
        $this->assertSame(['app_schema_history'], $this->tables());
    }

    public function testResetUndoesEveryMigrationNewestFirst(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rs_add_lakes.php',
            'MigratorRsAddLakes',
            '$db->statement(\'CREATE TABLE lakes (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'INSERT INTO undone (name) VALUES (\\\'lakes\\\')\'); $db->statement(\'DROP TABLE lakes\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_rs_add_boats.php',
            'MigratorRsAddBoats',
            '$db->statement(\'CREATE TABLE boats (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'INSERT INTO undone (name) VALUES (\\\'boats\\\')\'); $db->statement(\'DROP TABLE boats\');',
        );
        $this->migrator()->run();
        $this->writeMigration(
            '20260401000000_migrator_rs_add_docks.php',
            'MigratorRsAddDocks',
            '$db->statement(\'CREATE TABLE docks (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'INSERT INTO undone (name) VALUES (\\\'docks\\\')\'); $db->statement(\'DROP TABLE docks\');',
        );
        $this->migrator()->run();
        $this->connection->statement('CREATE TABLE undone (name TEXT)');

        $this->assertSame(3, $this->migrator()->reset());

        $this->assertSame(
            [['name' => 'docks'], ['name' => 'boats'], ['name' => 'lakes']],
            $this->connection->query('SELECT name FROM undone ORDER BY rowid')->asArray(),
        );
        $this->assertSame(['migrations', 'undone'], $this->tables());
        $this->assertSame([], $this->history());
    }

    public function testResetWithNothingAppliedUndoesNothing(): void
    {
        $this->assertSame(0, $this->migrator()->reset());

        $this->assertSame(['migrations'], $this->tables());
    }

    public function testResetRefusesToStartInsideATransaction(): void
    {
        $this->connection->begin();

        $thrown = $this->assertThrows(LogicException::class, fn () => $this->migrator()->reset());

        $this->assertSame(
            'Cannot roll back migrations inside a transaction: the first schema change would commit it.',
            $thrown->getMessage(),
        );
        $this->assertSame([], $this->tables());
    }

    public function testResetRefusesARecordedMigrationWhoseFileIsGoneBeforeUndoingAny(): void
    {
        $this->writeMigration(
            '20260501000000_migrator_rs_add_ports.php',
            'MigratorRsAddPorts',
            '$db->statement(\'CREATE TABLE ports (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE ports\');',
        );
        $this->writeMigration(
            '20260502000000_migrator_rs_add_piers.php',
            'MigratorRsAddPiers',
            '$db->statement(\'CREATE TABLE piers (id INTEGER PRIMARY KEY)\');',
            '$db->statement(\'DROP TABLE piers\');',
        );
        $this->migrator()->run();
        unlink($this->directory . '/20260501000000_migrator_rs_add_ports.php');

        $thrown = $this->assertThrows(UnexpectedValueException::class, fn () => $this->migrator()->reset());

        $this->assertSame(
            'Migrations recorded as applied have no file in the migration directory: 20260501000000_migrator_rs_add_ports.',
            $thrown->getMessage(),
        );
        $this->assertSame(['migrations', 'piers', 'ports'], $this->tables());
        $this->assertCount(2, $this->history());
    }
}
