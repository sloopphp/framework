<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\ConnectionRoute;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Insert;
use Sloop\Tests\Support\ThrowsAssertions;

final class InsertTest extends TestCase
{
    use ThrowsAssertions;

    private Connection $connection;

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, score INTEGER)');

        $this->connection = new Connection($sqlite, 'insert_test');
    }

    private function insert(string $table = 'users'): Insert
    {
        return $this->connection->insert($table);
    }

    /**
     * @return list<array{name: string, score: int|null}>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->connection->query('SELECT name, score FROM users ORDER BY id') as $row) {
            self::assertIsString($row['name']);
            self::assertTrue($row['score'] === null || \is_int($row['score']));

            $rows[] = ['name' => $row['name'], 'score' => $row['score']];
        }

        return $rows;
    }

    private function failingRoute(): ConnectionRoute
    {
        return new class () implements ConnectionRoute {
            public function connection(): Connection
            {
                throw new DatabaseConnectionException('no connection for this test');
            }
        };
    }

    private function connectionReporting(string $version): Connection
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->createFunction('VERSION', static fn (): string => $version);

        return new Connection($sqlite, 'insert_test');
    }

    public function testOneRowBecomesAColumnListAndOneTuple(): void
    {
        $insert = $this->insert()->set(['name' => 'alice', 'score' => 10]);

        $this->assertSame('INSERT INTO `users` (`name`, `score`) VALUES (?, ?)', $insert->toSql());
        $this->assertSame(['alice', 10], $insert->toBindings());
    }

    public function testEachCallToSetAddsARowRatherThanMergingIntoTheLast(): void
    {
        $insert = $this->insert()->set(['name' => 'alice', 'score' => 10])->set(['name' => 'bob', 'score' => 20]);

        $this->assertSame('INSERT INTO `users` (`name`, `score`) VALUES (?, ?), (?, ?)', $insert->toSql());
        $this->assertSame(['alice', 10, 'bob', 20], $insert->toBindings());
    }

    public function testValuesAddsEveryRowItIsGiven(): void
    {
        $insert = $this->insert()->values([
            ['name' => 'alice', 'score' => 10],
            ['name' => 'bob', 'score' => 20],
        ]);

        $this->assertSame('INSERT INTO `users` (`name`, `score`) VALUES (?, ?), (?, ?)', $insert->toSql());
        $this->assertSame(['alice', 10, 'bob', 20], $insert->toBindings());
    }

    public function testValuesAndSetAddToTheSameStatement(): void
    {
        $insert = $this->insert()->values([['name' => 'alice', 'score' => 10]])->set(['name' => 'bob', 'score' => 20]);

        $this->assertSame(['alice', 10, 'bob', 20], $insert->toBindings());
    }

    public function testANullValueIsWrittenAsABoundValue(): void
    {
        $insert = $this->insert()->set(['name' => 'alice', 'score' => null]);

        $this->assertSame('INSERT INTO `users` (`name`, `score`) VALUES (?, ?)', $insert->toSql());
        $this->assertSame(['alice', null], $insert->toBindings());
    }

    public function testAnExpressionKeepsItsBindingsInPlaceholderOrder(): void
    {
        $insert = $this->insert()->set(['name' => 'alice', 'score' => Expression::of('ABS(?)', [-5])]);

        $this->assertSame('INSERT INTO `users` (`name`, `score`) VALUES (?, ABS(?))', $insert->toSql());
        $this->assertSame(['alice', -5], $insert->toBindings());
    }

    public function testTheTablePrefixOfTheGrammarIsApplied(): void
    {
        $this->connection->setGrammar(new Grammar('wp_'));

        $this->assertSame(
            'INSERT INTO `wp_users` (`name`) VALUES (?)',
            $this->connection->insert('users')->set(['name' => 'alice'])->toSql(),
        );
    }

    public function testAStatementWithNoRowIsRefused(): void
    {
        $insert = $this->insert();

        $thrown = $this->assertThrows(LogicException::class, static fn (): string => $insert->toSql());

        $this->assertSame(
            'An INSERT writes at least one row, and this one carries none;'
                . ' call set() or values() before running it.',
            $thrown->getMessage(),
        );
    }

    public function testAnEmptyListOfRowsLeavesTheStatementWithNoneToWrite(): void
    {
        $insert = $this->insert()->values([]);

        $this->assertThrows(LogicException::class, static fn (): string => $insert->toSql());
    }

    public function testARowNamingNoColumnIsRefused(): void
    {
        // MySQL reads `INSERT INTO t () VALUES ()` as a row of defaults, so an
        // empty row would be written rather than reported. A row built up
        // dynamically that ends up empty is a caller's mistake, not a request
        // for defaults.
        $insert = $this->insert();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Insert => $insert->set([]),
        );

        $this->assertSame(
            'A row names the columns it writes, and the row at index 0 names none.'
                . ' MySQL reads an INSERT with no columns as a row of defaults, which is not what an empty'
                . ' row was likely meant to ask for.',
            $thrown->getMessage(),
        );
    }

    public function testAnEmptyRowInsideValuesIsRefused(): void
    {
        $insert = $this->insert();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Insert => $insert->values([['name' => 'alice'], []]),
        );

        $this->assertStringContainsString('the row at index 1 names none', $thrown->getMessage());
    }

    public function testTheIndexValuesNamesCountsThroughTheWholeStatement(): void
    {
        // set() and values() add to the same statement, so both messages have
        // to number the rows the same way.
        $insert = $this->insert()->set(['name' => 'alice']);

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Insert => $insert->values([['name' => 'bob'], 'oops']),
        );

        $this->assertSame(
            'values() takes a list of rows, so each element must be an array, got string at index 2.'
                . ' Pass one row to set() instead.',
            $thrown->getMessage(),
        );
    }

    public function testARowNamingOtherColumnsThanTheFirstIsRefused(): void
    {
        $insert = $this->insert()->set(['name' => 'alice', 'score' => 10]);

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Insert => $insert->set(['name' => 'bob']),
        );

        $this->assertSame(
            'Every row of an INSERT writes the same columns in the same order, because they are named once for'
                . ' the whole statement. The first row names "name", "score"'
                . ' and the row at index 1 names "name".',
            $thrown->getMessage(),
        );
    }

    public function testARowNamingTheSameColumnsInAnotherOrderIsRefused(): void
    {
        // The columns are written once, so the values of every row have to line
        // up with them. Reordering the keys would put a value under the wrong
        // column rather than being read back by name.
        $insert = $this->insert()->set(['name' => 'alice', 'score' => 10]);

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Insert => $insert->set(['score' => 20, 'name' => 'bob']),
        );

        $this->assertStringContainsString('The first row names "name", "score"', $thrown->getMessage());
    }

    public function testAKeyThatDoesNotNameAColumnIsRefused(): void
    {
        $insert = $this->insert();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Insert => $insert->set(['alice']),
        );

        $this->assertSame(
            'A value names the column it goes into, so its key must be a string, got int in the row at index 0.',
            $thrown->getMessage(),
        );
    }

    public function testAValueThatCannotBeWrittenIsRefused(): void
    {
        $insert = $this->insert();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Insert => $insert->set(['name' => ['alice']]),
        );

        $this->assertSame(
            'The value of a column must be a scalar, null or an Expression, got array for column "name"'
                . ' in the row at index 0.',
            $thrown->getMessage(),
        );
    }

    public function testValuesRefusesOneRowPassedWithoutAList(): void
    {
        $insert = $this->insert();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Insert => $insert->values(['name' => 'alice']),
        );

        $this->assertSame(
            'values() takes a list of rows, so each element must be an array, got string at index 0.'
                . ' Pass one row to set() instead.',
            $thrown->getMessage(),
        );
    }

    public function testExecuteWritesTheRowsAndReportsAnInsertedId(): void
    {
        $id = $this->insert()->set(['name' => 'alice', 'score' => 10])->execute();

        $this->assertGreaterThan(0, $id);
        $this->assertSame([['name' => 'alice', 'score' => 10]], $this->rows());
    }

    public function testExecuteWritesEveryRowOfAMultiRowStatement(): void
    {
        $this->insert()->values([
            ['name' => 'alice', 'score' => 10],
            ['name' => 'bob', 'score' => 20],
        ])->execute();

        $this->assertSame(
            [['name' => 'alice', 'score' => 10], ['name' => 'bob', 'score' => 20]],
            $this->rows(),
        );
    }

    public function testExecuteIgnoreSendsTheIgnoreFormOfTheStatement(): void
    {
        // SQLite spells this `INSERT OR IGNORE` and rejects MySQL's form, so
        // what the statement carries is read off the exception rather than from
        // rows. What the server does with a row it refuses is engine behaviour
        // and is pinned in the integration test instead.
        $insert = $this->insert()->set(['name' => 'alice']);

        $thrown = $this->assertThrows(QueryException::class, static fn (): int|string => $insert->executeIgnore());

        $this->assertSame('INSERT IGNORE INTO `users` (`name`) VALUES (?)', $thrown->sql);
    }

    public function testCompilingLeavesTheIgnoreOutOfTheStatement(): void
    {
        $this->assertSame(
            'INSERT INTO `users` (`name`) VALUES (?)',
            $this->insert()->set(['name' => 'alice'])->toSql(),
        );
    }

    public function testUpsertAddsAnUpdateClauseNamingTheColumnsToOverwrite(): void
    {
        $insert = $this->insert()->set(['name' => 'alice', 'score' => 10])->upsert(['score']);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `score`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `score` = VALUES(`score`)',
            $insert->toSql(),
        );
        $this->assertSame(['alice', 10], $insert->toBindings());
    }

    public function testCallingUpsertAgainReplacesTheColumnsRatherThanAddingToThem(): void
    {
        $insert = $this->insert()
            ->set(['name' => 'alice', 'score' => 10])
            ->upsert(['name', 'score'])
            ->upsert(['score']);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `score`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `score` = VALUES(`score`)',
            $insert->toSql(),
        );
    }

    public function testAStatementWithNoRowIsRefusedBeforeAConnectionIsAskedFor(): void
    {
        // A statement that cannot be written should say so, rather than
        // reporting whatever obtaining a connection ran into. Update and Delete
        // compile before they resolve their route, which puts them here too.
        $insert = new Insert($this->failingRoute(), new Grammar(), 'users');

        $this->assertThrows(LogicException::class, static fn (): int|string => $insert->execute());
        $this->assertThrows(LogicException::class, static fn (): int|string => $insert->executeIgnore());
    }

    public function testUpsertCanBeNamedBeforeTheRowsThatSettleTheColumns(): void
    {
        // Which columns the statement writes is not known until a row arrives,
        // which is why the check that an overwritten column is one of them sits
        // in the spec rather than here. Calling upsert() first has to reach the
        // same statement for that to hold.
        $insert = $this->insert()->upsert(['score'])->set(['name' => 'alice', 'score' => 10]);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `score`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `score` = VALUES(`score`)',
            $insert->toSql(),
        );
    }

    public function testUpsertNamingNoColumnIsRefused(): void
    {
        $insert = $this->insert();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Insert => $insert->upsert([]),
        );

        $this->assertSame(
            'An upsert names the columns to overwrite on a collision, and this one names none.'
                . ' To let the server pass over a colliding row instead, run the statement with executeIgnore().',
            $thrown->getMessage(),
        );
    }

    public function testUpsertNamingAColumnTheStatementDoesNotWriteIsRefused(): void
    {
        $insert = $this->insert()->set(['name' => 'alice'])->upsert(['score']);

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): string => $insert->toSql(),
        );

        $this->assertSame(
            'A column overwritten on a collision takes the value this statement was writing for it, so it has'
                . ' to be one of the columns being written; "score" is not among "name".',
            $thrown->getMessage(),
        );
    }

    public function testUpsertCannotBeRunAsIgnoreBecauseTheServersDisagreeOnWhichWins(): void
    {
        $insert = $this->insert()->set(['name' => 'alice', 'score' => 10])->upsert(['score']);

        $thrown = $this->assertThrows(
            LogicException::class,
            static fn (): int|string => $insert->executeIgnore(),
        );

        $this->assertSame(
            'IGNORE and ON DUPLICATE KEY UPDATE ask for opposite things on a collision, and the servers do not'
                . ' agree on which wins: MySQL 8.0 passes the row over and MariaDB 10.11 applies the update.'
                . ' Run this with execute() to update on a collision, or drop the upsert() call to skip the row.',
            $thrown->getMessage(),
        );
    }

    /**
     * @param string $version Value the connection's VERSION() answers with
     * @param string $clause  Update clause the statement is expected to carry
     */
    #[DataProvider('serverVersions')]
    public function testTheUpdateClauseTakesTheFormTheConnectedServerReads(string $version, string $clause): void
    {
        // The statement never runs: SQLite rejects ON DUPLICATE KEY UPDATE
        // whichever form it is in, so what was sent is read off the exception.
        // VERSION() is registered on the handle to stand in for the server the
        // route would have answered with.
        $insert = $this->connectionReporting($version)
            ->insert('users')
            ->set(['name' => 'alice', 'score' => 10])
            ->upsert(['score']);

        $thrown = $this->assertThrows(QueryException::class, static fn (): int|string => $insert->execute());

        $this->assertSame(
            'INSERT INTO `users` (`name`, `score`) VALUES (?, ?)' . $clause,
            $thrown->sql,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function serverVersions(): array
    {
        $alias  = ' AS `sloop_upsert` ON DUPLICATE KEY UPDATE `score` = `sloop_upsert`.`score`';
        $values = ' ON DUPLICATE KEY UPDATE `score` = VALUES(`score`)';

        return [
            'MySQL where the alias arrived'      => ['8.0.19', $alias],
            'a later MySQL'                      => ['8.0.46', $alias],
            'a MySQL before the alias'           => ['8.0.18', $values],
            'MySQL 5.7'                          => ['5.7.44', $values],
            'MariaDB, which rejects the alias'   => ['10.11.18-MariaDB-ubu2204', $values],
            'MariaDB behind the version prefix'  => ['5.5.5-10.11.18-MariaDB', $values],
            'a version string that cannot be read' => ['who knows', $values],
        ];
    }
}
