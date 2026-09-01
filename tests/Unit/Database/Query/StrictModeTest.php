<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\BuilderWhere;
use Sloop\Database\Query\Delete;
use Sloop\Database\Query\Update;
use Sloop\Tests\Support\ThrowsAssertions;

final class StrictModeTest extends TestCase
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
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $sqlite->exec("INSERT INTO users (id, status) VALUES (1, 'active'), (2, 'blocked')");

        $this->connection = new Connection($sqlite, 'strict_mode_test');
    }

    /**
     * @return array<string, array{callable(Connection): (Update|Delete), string}>
     */
    public static function provideUnconditionedStatements(): array
    {
        return [
            'update' => [
                static fn (Connection $c): Update => $c->update('users')->set(['status' => 'active']),
                'UPDATE',
            ],
            'delete' => [
                static fn (Connection $c): Delete => $c->delete('users'),
                'DELETE',
            ],
        ];
    }

    /**
     * @return array<string, array{callable(Connection): (Update|Delete), string, callable(Update|Delete): (Update|Delete)}>
     */
    public static function provideConditionsThatNarrowNothing(): array
    {
        $shapes = [
            // A group that was opened and closed with nothing inside it.
            'an empty group'      => static fn (Update|Delete $q): Update|Delete => $q->whereOpen()->whereClose(),
            // A when() whose condition was false, so its callback never ran.
            'a when that did not fire' => static fn (Update|Delete $q): Update|Delete => $q->when(
                false,
                static fn (BuilderWhere $inner): BuilderWhere => $inner->where('id', 1),
            ),
            // The array form handed an empty list: addConditions() adds nothing.
            'an empty list'       => static fn (Update|Delete $q): Update|Delete => $q->where([]),
        ];

        $cases = [];

        foreach (self::provideUnconditionedStatements() as $name => [$build, $statement]) {
            foreach ($shapes as $shape => $narrow) {
                $cases[$name . ' with ' . $shape] = [$build, $statement, $narrow];
            }
        }

        return $cases;
    }

    /**
     * @return list<array{id: int, status: string}>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->connection->query('SELECT id, status FROM users ORDER BY id') as $row) {
            self::assertIsInt($row['id']);
            self::assertIsString($row['status']);

            $rows[] = ['id' => $row['id'], 'status' => $row['status']];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, status: string}>
     */
    private function seededRows(): array
    {
        return [
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'blocked'],
        ];
    }

    public function testAConnectionDoesNotRunInStrictModeUntilItIsToldTo(): void
    {
        $this->assertFalse($this->connection->isStrictMode());
    }

    /**
     * @param callable(Connection): (Update|Delete) $build
     */
    #[DataProvider('provideUnconditionedStatements')]
    public function testWithoutStrictModeAnUnconditionedStatementRuns(callable $build, string $statement): void
    {
        $this->assertSame(2, $build($this->connection)->execute(), $statement . ' addressed both rows');
    }

    /**
     * @param callable(Connection): (Update|Delete) $build
     */
    #[DataProvider('provideUnconditionedStatements')]
    public function testStrictModeRefusesAnUnconditionedStatement(callable $build, string $statement): void
    {
        $this->connection->setStrictMode(true);
        $query = $build($this->connection);

        $thrown = $this->assertThrows(LogicException::class, static fn (): int => $query->execute());

        $this->assertSame(
            'This connection runs in strict mode, and this ' . $statement . ' carries no WHERE clause,'
                . ' so it would address every row. Narrow it with where(), or call allowWithoutWhere()'
                . ' to say that addressing the whole table is what was meant.',
            $thrown->getMessage(),
        );
        $this->assertSame($this->seededRows(), $this->rows(), 'nothing reached the server');
    }

    /**
     * @param callable(Connection): (Update|Delete) $build
     */
    #[DataProvider('provideUnconditionedStatements')]
    public function testStrictModeLetsANarrowedStatementRun(callable $build, string $statement): void
    {
        $this->connection->setStrictMode(true);

        $this->assertSame(1, $build($this->connection)->where('id', 1)->execute(), $statement . ' ran');
    }

    /**
     * @param callable(Connection): (Update|Delete) $build
     */
    #[DataProvider('provideUnconditionedStatements')]
    public function testAllowWithoutWhereLetsAnUnconditionedStatementRun(callable $build, string $statement): void
    {
        $this->connection->setStrictMode(true);

        $this->assertSame(2, $build($this->connection)->allowWithoutWhere()->execute(), $statement . ' ran');
    }

    /**
     * @param callable(Connection): (Update|Delete)    $build
     * @param callable(Update|Delete): (Update|Delete) $narrow
     */
    #[DataProvider('provideConditionsThatNarrowNothing')]
    public function testAConditionThatWritesNoClauseIsRefused(callable $build, string $statement, callable $narrow): void
    {
        // An empty group leaves boundaries behind that the grammar drops
        // again; the other two collect nothing at all. Either way the SQL
        // reaching the server carries no WHERE clause, and counting the
        // collected parts rather than reading them would let the first of
        // them through.
        $this->connection->setStrictMode(true);
        $query = $narrow($build($this->connection));

        $this->assertThrows(LogicException::class, static fn (): int => $query->execute());
        $this->assertSame($this->seededRows(), $this->rows(), $statement . ' left the rows alone');
    }

    /**
     * @param callable(Connection): (Update|Delete)    $build
     * @param callable(Update|Delete): (Update|Delete) $narrow
     */
    #[DataProvider('provideConditionsThatNarrowNothing')]
    public function testAConditionThatWritesNoClauseCompilesWithoutOne(callable $build, string $statement, callable $narrow): void
    {
        $sql = $narrow($build($this->connection))->toSql();

        $this->assertStringNotContainsString('WHERE', $sql, $statement . ' compiles without a clause');
    }

    /**
     * @param callable(Connection): (Update|Delete) $build
     */
    #[DataProvider('provideUnconditionedStatements')]
    public function testCompilingAnUnconditionedStatementIsNotRefused(callable $build, string $statement): void
    {
        // The guard is on running, not on writing the SQL: compiling asks for no
        // connection, so what it produces cannot depend on one.
        $this->connection->setStrictMode(true);

        $this->assertStringNotContainsString('WHERE', $build($this->connection)->toSql(), $statement . ' compiled');
    }
}
