<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
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

        $thrown = $this->assertThrows(QueryException::class, static fn (): int => $insert->executeIgnore());

        $this->assertSame('INSERT IGNORE INTO `users` (`name`) VALUES (?)', $thrown->sql);
    }

    public function testCompilingLeavesTheIgnoreOutOfTheStatement(): void
    {
        $this->assertSame(
            'INSERT INTO `users` (`name`) VALUES (?)',
            $this->insert()->set(['name' => 'alice'])->toSql(),
        );
    }
}
