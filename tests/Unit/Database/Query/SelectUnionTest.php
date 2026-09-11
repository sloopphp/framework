<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;

// SQLite refuses a statement written inside parentheses before UNION, which is
// the shape both MySQL and MariaDB need for a sort of their own. These tests
// therefore read what reaches the driver rather than running it; what the
// servers return for these shapes is covered by the integration test.
final class SelectUnionTest extends TestCase
{
    use ThrowsAssertions;

    private Connection $connection;

    /** @var list<string> */
    private array $prepared = [];

    /** @var list<list<array<string, int|string>>> */
    private array $results = [];

    protected function setUp(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $this->prepared[] = $sql;

            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchAll')->willReturn(array_shift($this->results) ?? []);

            return $statement;
        });

        $this->connection = new Connection($pdo, 'union_test');
    }

    private function users(): Select
    {
        return $this->connection->select('id')->from('users');
    }

    private function admins(): Select
    {
        return $this->connection->select('id')->from('admins');
    }

    private function combined(): string
    {
        return '(SELECT `id` FROM `users`) UNION (SELECT `id` FROM `admins`)';
    }

    public function testUnionWritesEachStatementInsideParentheses(): void
    {
        $this->assertSame($this->combined(), $this->users()->union($this->admins())->toSql());
    }

    public function testUnionAllKeepsRowsReadTwice(): void
    {
        $this->assertSame(
            '(SELECT `id` FROM `users`) UNION ALL (SELECT `id` FROM `admins`)',
            $this->users()->unionAll($this->admins())->toSql(),
        );
    }

    public function testStatementsAreWrittenInTheOrderTheyWereAdded(): void
    {
        $select = $this->users()
            ->unionAll($this->admins())
            ->union($this->connection->select('id')->from('guests'));

        $this->assertSame(
            '(SELECT `id` FROM `users`) UNION ALL (SELECT `id` FROM `admins`) UNION (SELECT `id` FROM `guests`)',
            $select->toSql(),
        );
    }

    public function testBindingsFollowTheStatementsFromLeftToRightThenTheSort(): void
    {
        $select = $this->users()
            ->where('status', 'active')
            ->union($this->admins()->where('level', 3))
            ->orderByRaw('FIELD(id, ?)', [7]);

        $this->assertSame(
            '(SELECT `id` FROM `users` WHERE `status` = ?) UNION (SELECT `id` FROM `admins` WHERE `level` = ?)'
                . ' ORDER BY FIELD(id, ?)',
            $select->toSql(),
        );
        $this->assertSame(['active', 3, 7], $select->toBindings());
    }

    public function testASortAndWindowSetBeforeUnionStayInsideTheFirstStatement(): void
    {
        $select = $this->users()->orderBy('id', 'DESC')->limit(2)->offset(1)->union($this->admins());

        $this->assertSame(
            '(SELECT `id` FROM `users` ORDER BY `id` DESC LIMIT 2 OFFSET 1) UNION (SELECT `id` FROM `admins`)',
            $select->toSql(),
        );
    }

    public function testASortAndWindowSetAfterUnionApplyToTheCombinedRows(): void
    {
        $select = $this->users()->union($this->admins())->orderBy('id', 'DESC')->limit(5)->offset(10);

        $this->assertSame($this->combined() . ' ORDER BY `id` DESC LIMIT 5 OFFSET 10', $select->toSql());
    }

    public function testAWindowSetBetweenTwoUnionsAppliesToTheCombinedRows(): void
    {
        // Only the first union() moves the window aside. What comes after it
        // belongs to the combined rows, however many statements follow.
        $select = $this->users()
            ->union($this->admins())
            ->limit(3)
            ->union($this->connection->select('id')->from('guests'));

        $this->assertSame(
            $this->combined() . ' UNION (SELECT `id` FROM `guests`) LIMIT 3',
            $select->toSql(),
        );
    }

    public function testAnAddedStatementKeepsItsSortAndLimitInsideItsParentheses(): void
    {
        $select = $this->users()->union($this->admins()->orderBy('id', 'DESC')->limit(1));

        $this->assertSame(
            '(SELECT `id` FROM `users`) UNION (SELECT `id` FROM `admins` ORDER BY `id` DESC LIMIT 1)',
            $select->toSql(),
        );
    }

    public function testASortWithoutALimitBeforeUnionIsRefused(): void
    {
        $select = $this->users()->orderBy('id')->union($this->admins());

        $e = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertSame(
            'A statement combined with others sorts its own rows only to choose which of them a limit keeps;'
                . ' without one, both servers drop the sort. Add a limit() before union(), or sort the'
                . ' combined rows by calling orderBy() after it.',
            $e->getMessage(),
        );
    }

    public function testAnAddedStatementSortingWithoutALimitIsRefused(): void
    {
        $select = $this->users()->union($this->admins()->orderBy('id'));

        $e = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertStringContainsString('without one, both servers drop the sort', $e->getMessage());
    }

    public function testAnAddedStatementIsReadWhenTheCombinedOneIsCompiled(): void
    {
        $admins = $this->admins();
        $select = $this->users()->union($admins);

        $admins->where('level', 3);

        $this->assertSame(
            '(SELECT `id` FROM `users`) UNION (SELECT `id` FROM `admins` WHERE `level` = ?)',
            $select->toSql(),
        );
    }

    public function testAnAddedStatementThatIsItselfCombinedStaysInsideOneParenthesis(): void
    {
        $select = $this->users()->unionAll($this->admins()->union($this->connection->select('id')->from('guests')));

        $this->assertSame(
            '(SELECT `id` FROM `users`) UNION ALL ((SELECT `id` FROM `admins`) UNION (SELECT `id` FROM `guests`))',
            $select->toSql(),
        );
    }

    public function testALockOnTheCombinedStatementIsRefused(): void
    {
        $select = $this->users()->union($this->admins())->forUpdate();

        $e = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertSame(
            'A combined statement has no place for a lock that both servers read the same way:'
                . ' written after the last parenthesis, MariaDB refuses it and MySQL takes it.'
                . ' Drop the forUpdate()/sharedLock() call.',
            $e->getMessage(),
        );
    }

    public function testALockOnAnAddedStatementIsWrittenInsideItsParentheses(): void
    {
        $select = $this->users()->union($this->admins()->forUpdate());

        $this->assertSame(
            '(SELECT `id` FROM `users`) UNION (SELECT `id` FROM `admins` FOR UPDATE)',
            $select->toSql(),
        );
    }

    public function testExecuteRunsTheCombinedStatement(): void
    {
        $this->results = [[['id' => 1], ['id' => 2]]];

        $rows = $this->users()->union($this->admins())->get();

        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
        $this->assertSame([$this->combined()], $this->prepared);
    }

    public function testFirstCutsTheCombinedRows(): void
    {
        $this->results = [[['id' => 4]]];

        $row = $this->users()->union($this->admins())->orderBy('id', 'DESC')->first();

        $this->assertSame(['id' => 4], $row);
        $this->assertSame([$this->combined() . ' ORDER BY `id` DESC LIMIT 1'], $this->prepared);
    }

    public function testCountReadsTheCombinedRowsAsATableOfTheirOwn(): void
    {
        $this->results = [[['COUNT(*)' => 5]]];

        $count = $this->users()->union($this->admins())->count();

        $this->assertSame(5, $count);
        $this->assertSame(
            ['SELECT COUNT(*) FROM (' . $this->combined() . ') AS `sloop_union`'],
            $this->prepared,
        );
    }

    public function testCountLeavesTheCombinedWindowOut(): void
    {
        $this->results = [[['COUNT(*)' => 5]]];

        $this->users()->union($this->admins())->limit(2)->offset(2)->count();

        $this->assertSame(
            ['SELECT COUNT(*) FROM (' . $this->combined() . ') AS `sloop_union`'],
            $this->prepared,
        );
    }

    public function testCountKeepsTheWindowOfEachStatement(): void
    {
        $this->results = [[['COUNT(*)' => 2]]];

        $this->users()->orderBy('id')->limit(1)->union($this->admins())->count();

        $this->assertSame(
            [
                'SELECT COUNT(*) FROM ((SELECT `id` FROM `users` ORDER BY `id` ASC LIMIT 1)'
                    . ' UNION (SELECT `id` FROM `admins`)) AS `sloop_union`',
            ],
            $this->prepared,
        );
    }

    public function testCountOfACombinedStatementIsNotRefusedForGroupingItsFirstStatement(): void
    {
        // The count is of the rows the union returns, so a grouped statement
        // among them is only a source of rows here.
        $this->results = [[['COUNT(*)' => 3]]];

        $count = $this->connection->select('status')
            ->from('users')
            ->groupBy('status')
            ->union($this->connection->select('status')->from('admins'))
            ->count();

        $this->assertSame(3, $count);
        $this->assertSame(
            [
                'SELECT COUNT(*) FROM ((SELECT `status` FROM `users` GROUP BY `status`)'
                    . ' UNION (SELECT `status` FROM `admins`)) AS `sloop_union`',
            ],
            $this->prepared,
        );
    }

    public function testExistsReadsTheCombinedRows(): void
    {
        $this->results = [[['one' => 1]]];

        $this->assertTrue($this->users()->union($this->admins())->exists());
        $this->assertSame(
            ['SELECT 1 FROM (' . $this->combined() . ') AS `sloop_union` LIMIT 1'],
            $this->prepared,
        );
    }

    public function testAnAggregateReadsTheCombinedRows(): void
    {
        $this->results = [[['SUM(`id`)' => '9']]];

        $sum = $this->users()->union($this->admins())->sum('id');

        $this->assertSame('9', $sum);
        $this->assertSame(
            ['SELECT SUM(`id`) FROM (' . $this->combined() . ') AS `sloop_union`'],
            $this->prepared,
        );
    }

    public function testAnAggregateOfACombinedStatementIsNotRefusedForGroupingItsFirstStatement(): void
    {
        $this->results = [[['MAX(`status`)' => 'blocked']]];

        $max = $this->connection->select('status')
            ->from('users')
            ->groupBy('status')
            ->union($this->connection->select('status')->from('admins'))
            ->max('status');

        $this->assertSame('blocked', $max);
    }

    public function testValueReadsOneColumnOfTheCombinedRowsInTheirSort(): void
    {
        $this->results = [[['name' => 'carol']]];

        $name = $this->connection->select('id', 'name')
            ->from('users')
            ->union($this->connection->select('id', 'name')->from('admins'))
            ->orderBy('name', 'DESC')
            ->value('name');

        $this->assertSame('carol', $name);
        $this->assertSame(
            [
                'SELECT `name` FROM ((SELECT `id`, `name` FROM `users`) UNION (SELECT `id`, `name` FROM `admins`))'
                    . ' AS `sloop_union` ORDER BY `name` DESC LIMIT 1',
            ],
            $this->prepared,
        );
    }

    public function testPluckReadsTheCombinedRows(): void
    {
        $this->results = [[['name' => 'alice'], ['name' => 'bob']]];

        $names = $this->connection->select('id', 'name')
            ->from('users')
            ->union($this->connection->select('id', 'name')->from('admins'))
            ->pluck('name');

        $this->assertSame(['alice', 'bob'], $names);
        $this->assertSame(
            [
                'SELECT `name` FROM ((SELECT `id`, `name` FROM `users`)'
                    . ' UNION (SELECT `id`, `name` FROM `admins`)) AS `sloop_union`',
            ],
            $this->prepared,
        );
    }

    public function testPluckWithAKeyReadsBothColumnsFromTheCombinedRows(): void
    {
        $this->results = [[['id' => 1, 'name' => 'alice']]];

        $names = $this->connection->select('id', 'name')
            ->from('users')
            ->union($this->connection->select('id', 'name')->from('admins'))
            ->pluck('name', 'id');

        $this->assertSame([1 => 'alice'], $names);
        $this->assertSame(
            [
                'SELECT `id`, `name` FROM ((SELECT `id`, `name` FROM `users`)'
                    . ' UNION (SELECT `id`, `name` FROM `admins`)) AS `sloop_union`',
            ],
            $this->prepared,
        );
    }

    public function testPaginateCutsThePageFromTheCombinedRowsAndCountsThemAll(): void
    {
        $this->results = [[['id' => 3], ['id' => 4]], [['COUNT(*)' => 5]]];

        $page = $this->users()->union($this->admins())->orderBy('id')->paginate(2, 2);

        $this->assertSame(5, $page->total);
        $this->assertSame(
            [
                $this->combined() . ' ORDER BY `id` ASC LIMIT 2 OFFSET 2',
                'SELECT COUNT(*) FROM (' . $this->combined() . ') AS `sloop_union` ORDER BY `id` ASC',
            ],
            $this->prepared,
        );
    }

    public function testChunkCutsTheCombinedRows(): void
    {
        $this->results = [[['id' => 1]]];

        $this->users()->union($this->admins())->chunk(10, static fn (): bool => true);

        $this->assertSame([$this->combined() . ' LIMIT 10 OFFSET 0'], $this->prepared);
    }

    public function testChunkByIdWalksTheCombinedRows(): void
    {
        $this->results = [[['id' => 1], ['id' => 2]], [['id' => 3]]];

        $walked = $this->users()->union($this->admins())->chunkById(2, static fn (): bool => true);

        $this->assertTrue($walked);
        $this->assertSame(
            [
                'SELECT * FROM (' . $this->combined() . ') AS `sloop_union` ORDER BY `id` ASC LIMIT 2',
                'SELECT * FROM (' . $this->combined() . ') AS `sloop_union` WHERE `id` > ? ORDER BY `id` ASC LIMIT 2',
            ],
            $this->prepared,
        );
    }

    public function testChunkByIdDoesNotWriteTheFirstStatementsColumnsOutsideTheParentheses(): void
    {
        // Written outside, `users`.`id` and the expression would name columns
        // the combined rows do not have.
        $this->results = [[['id' => 1, 'n' => 'ALICE']]];

        $this->connection->select('users.id')
            ->selectRaw('UPPER(name) AS n')
            ->from('users')
            ->union($this->connection->select('id', 'name')->from('admins'))
            ->chunkById(10, static fn (): bool => true);

        $this->assertSame(
            [
                'SELECT * FROM ((SELECT `users`.`id`, UPPER(name) AS n FROM `users`)'
                    . ' UNION (SELECT `id`, `name` FROM `admins`)) AS `sloop_union` ORDER BY `id` ASC LIMIT 10',
            ],
            $this->prepared,
        );
    }
}
