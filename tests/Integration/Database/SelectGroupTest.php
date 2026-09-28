<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use DateTimeImmutable;
use Sloop\Database\CastMode;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Query\Expression;
use Sloop\Database\Result;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class SelectGroupTest extends TransactionalIntegrationTestCase
{
    use ThrowsAssertions;

    /** @var list<array<array-key, int|float|string|bool|DateTimeImmutable|null>> */
    private array $walked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection->statement(
            'INSERT INTO users (id, name, email, status, score, created_at) VALUES'
                . ' (1, ?, ?, ?, ?, NOW()),'
                . ' (2, ?, ?, ?, ?, NOW()),'
                . ' (3, ?, ?, ?, ?, NOW()),'
                . ' (4, ?, ?, ?, ?, NOW())',
            [
                'alice', 'alice@example.com', 'active', 10,
                'bob', 'bob@example.com', 'active', 20,
                'carol', 'carol@example.com', 'blocked', 30,
                'dave', 'dave@example.com', 'active', 40,
            ],
        );
        $this->connection->statement(
            'INSERT INTO posts (id, user_id, title, published, created_at) VALUES'
                . ' (1, 1, ?, 1, NOW()),'
                . ' (2, 1, ?, 1, NOW()),'
                . ' (3, 1, ?, 0, NOW()),'
                . ' (4, 2, ?, 1, NOW()),'
                . ' (5, 3, ?, 1, NOW())',
            ['a1', 'a2', 'a3', 'b1', 'c1'],
        );
    }

    /**
     * Read one column of every row the statement returns, as a comma separated
     * string so that the order and the values land in one assertion.
     *
     * @param  list<array<array-key, int|float|string|bool|DateTimeImmutable|null>> $rows   Rows the statement read
     * @param  string                                                               $column Column to read from each row
     * @return string                                                               Values in the order they came back
     */
    private static function column(array $rows, string $column): string
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $row[$column] ?? null;

            if ($value instanceof DateTimeImmutable) {
                self::fail('The columns read here are numbers or text, got a date for ' . $column . '.');
            }

            $values[] = $value === null ? 'null' : (string) $value;
        }

        return implode(',', $values);
    }

    public function testBothServersReadAnAggregateCallPerGroup(): void
    {
        // COUNT comes back as an int on both servers; MAX of an INT column too.
        // GROUP_CONCAT is ordered here so that the string does not depend on
        // which row each server happens to read first.
        $rows = $this->connection
            ->select(
                'status',
                [Expression::count(), 'members'],
                [Expression::max('score'), 'best'],
                [Expression::groupConcat(Expression::of('`name` ORDER BY `name`')), 'names'],
            )
            ->from('users')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $this->assertSame([
            ['status' => 'active', 'members' => 3, 'best' => 40, 'names' => 'alice,bob,dave'],
            ['status' => 'blocked', 'members' => 1, 'best' => 30, 'names' => 'carol'],
        ], $rows);
    }

    public function testGroupByFoldsTheRowsIntoOneRowPerValue(): void
    {
        $rows = $this->connection->select('user_id', Expression::of('COUNT(*) AS posts_written'))
            ->from('posts')
            ->groupBy('user_id')
            ->orderBy('user_id', 'ASC')
            ->get();

        $this->assertSame('1,2,3', self::column($rows, 'user_id'));
        $this->assertSame('3,1,1', self::column($rows, 'posts_written'));
    }

    public function testGroupByOverSeveralColumnsSplitsOnEachCombination(): void
    {
        $rows = $this->connection->select('user_id', 'published', Expression::of('COUNT(*) AS n'))
            ->from('posts')
            ->groupBy('user_id', 'published')
            ->orderBy('user_id', 'ASC')
            ->orderBy('published', 'ASC')
            ->get();

        $this->assertSame('1,1,2,3', self::column($rows, 'user_id'));
        $this->assertSame('1,2,1,1', self::column($rows, 'n'));
    }

    public function testHavingDropsTheGroupsItDoesNotHold(): void
    {
        $rows = $this->connection->select('user_id', Expression::of('COUNT(*) AS n'))
            ->from('posts')
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 1)
            ->get();

        $this->assertSame('1', self::column($rows, 'user_id'));
        $this->assertSame('3', self::column($rows, 'n'));
    }

    public function testHavingReadsTheGroupsWhereAWhereClauseReadsTheRows(): void
    {
        $rows = $this->connection->select('user_id', Expression::of('COUNT(*) AS n'))
            ->from('posts')
            ->where('published', 1)
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 1)
            ->get();

        // user 1 wrote three posts but only two of them are published, so the
        // WHERE clause has already taken the third out of the group.
        $this->assertSame('1', self::column($rows, 'user_id'));
        $this->assertSame('2', self::column($rows, 'n'));
    }

    public function testHavingGroupsAreReadAsWritten(): void
    {
        $rows = $this->connection->select('user_id', Expression::of('COUNT(*) AS n'))
            ->from('posts')
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 2)
            ->orHavingOpen()
            ->having(Expression::of('COUNT(*)'), '=', 1)
            ->andHaving('user_id', '>', 2)
            ->havingClose()
            ->orderBy('user_id', 'ASC')
            ->get();

        $this->assertSame('1,3', self::column($rows, 'user_id'));
    }

    public function testHavingRawReachesTheServerAsWritten(): void
    {
        $rows = $this->connection->select('user_id', Expression::of('COUNT(*) AS n'))
            ->from('posts')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) BETWEEN ? AND ?', [1, 1])
            ->orderBy('user_id', 'ASC')
            ->get();

        $this->assertSame('2,3', self::column($rows, 'user_id'));
    }

    public function testHavingWithoutAGroupByReadsTheWholeTableAsOneGroup(): void
    {
        $held = $this->connection->select(Expression::of('COUNT(*) AS n'))
            ->from('posts')
            ->having(Expression::of('COUNT(*)'), '>', 4)
            ->get();

        $dropped = $this->connection->select(Expression::of('COUNT(*) AS n'))
            ->from('posts')
            ->having(Expression::of('COUNT(*)'), '>', 5)
            ->get();

        $this->assertSame('5', self::column($held, 'n'));
        $this->assertSame([], $dropped);
    }

    public function testGroupByReadsTheGroupsOfAJoinedStatement(): void
    {
        $rows = $this->connection->select('users.status', Expression::of('COUNT(posts.id) AS n'))
            ->from('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->groupBy('users.status')
            ->orderBy('users.status', 'ASC')
            ->get();

        $this->assertSame('active,blocked', self::column($rows, 'status'));
        $this->assertSame('4,1', self::column($rows, 'n'));
    }

    public function testAGroupingExpressionIsWrittenAsGiven(): void
    {
        $rows = $this->connection->select(Expression::of('score DIV 20 AS band'), Expression::of('COUNT(*) AS n'))
            ->from('users')
            ->groupBy(Expression::of('score DIV 20'))
            ->orderBy(Expression::of('score DIV 20'))
            ->get();

        $this->assertSame('0,1,2', self::column($rows, 'band'));
        $this->assertSame('1,2,1', self::column($rows, 'n'));
    }

    public function testTheServerReportsAHavingColumnThatWasNotGroupedBy(): void
    {
        $select = $this->connection->select('user_id', Expression::of('COUNT(*) AS n'))
            ->from('posts')
            ->groupBy('user_id')
            ->having('title', '=', 'a1');

        $thrown = $this->assertThrows(\Sloop\Database\Exception\DatabaseException::class, static fn () => $select->get());

        $this->assertStringContainsString('title', $thrown->getMessage());
    }

    public function testCountCountsTheGroupsRatherThanAnsweringWithTheFirstOne(): void
    {
        // Written beside the GROUP BY, COUNT(*) would answer 3 — the size of
        // the first group — where the statement returns 3 groups over 5 rows.
        // The second statement tells the two apart: 1 group, whose size is 3.
        $grouped = $this->connection->select('user_id')->from('posts')->groupBy('user_id');

        $this->assertSame(3, $grouped->count());
        $this->assertSame(1, (clone $grouped)->having(Expression::of('COUNT(*)'), '>', 1)->count());
    }

    public function testCountOfAStatementWithOnlyAHavingClauseCountsTheRowsItReturns(): void
    {
        // Over plain columns both servers read a HAVING with no GROUP BY row
        // by row; over an aggregate select list, as one group. count() answers
        // with what get() returns in either case.
        $rows = $this->connection->select('user_id')->from('posts')->having('user_id', '>', 1);
        $one  = $this->connection->select(Expression::of('COUNT(*) AS n'))->from('posts')->having(Expression::of('COUNT(*)'), '>', 4);

        $this->assertSame(2, \count($rows->get()));
        $this->assertSame(2, $rows->count());
        $this->assertSame(1, $one->count());
    }

    public function testPaginateCountsTheGroupsForItsTotal(): void
    {
        $page = $this->connection->select('user_id', Expression::of('COUNT(*) AS n'))
            ->from('posts')
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->paginate(2, 2);

        $this->assertSame(3, $page->total);
        $this->assertSame(2, $page->lastPage);
        $this->assertSame('3', self::column($page->items->asArray(), 'user_id'));
    }

    public function testAnAggregateFoldsTheValuesOfTheGroupsRatherThanReadingTheFirstOne(): void
    {
        $grouped = $this->connection->select('user_id', [Expression::count(), 'written'])
            ->from('posts')
            ->groupBy('user_id');

        $this->assertSame('5', $grouped->sum('written'));
        $this->assertSame(1, $grouped->min('written'));
        $this->assertSame(3, $grouped->max('written'));
        $this->assertSame(3, $grouped->max('user_id'));
    }

    public function testChunkByIdWalksTheGroupsByAColumnTheyReturn(): void
    {
        $this->connection->select('user_id', [Expression::count(), 'written'])
            ->from('posts')
            ->groupBy('user_id')
            ->chunkById(2, function (Result $batch): bool {
                $this->walked = [...$this->walked, ...$batch->asArray()];

                // The walk ends on its own after two batches; the bound keeps a
                // cursor that stops narrowing from reading the same batch forever.
                return \count($this->walked) < 6;
            }, 'user_id');

        $this->assertSame('1,2,3', self::column($this->walked, 'user_id'));
        $this->assertSame('3,1,1', self::column($this->walked, 'written'));
    }

    public function testAGroupedStatementReturningOneNameTwiceIsRefusedByTheServerWhenCounted(): void
    {
        // The grouped rows are read as a table, and a table cannot have two
        // columns of one name. get() is not read that way, so it goes through.
        $select = $this->connection->select('users.id', 'posts.id')
            ->from('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->groupBy('users.id', 'posts.id');

        $this->assertCount(5, $select->get());

        $thrown = $this->assertThrows(DatabaseException::class, static fn () => $select->count());

        $this->assertStringContainsString('1060', $thrown->getMessage());
    }

    public function testAGroupedStatementReadingANameFromAWithClauseIsCounted(): void
    {
        // The WITH clause is written on the outer statement, and the grouped
        // statement inside the parentheses reads the name it introduces.
        $count = $this->connection->select('user_id')
            ->with('published', $this->connection->select('user_id')->from('posts')->where('published', 1))
            ->from('published')
            ->groupBy('user_id')
            ->count();

        $this->assertSame(3, $count);
    }

    public function testATimeoutReachesTheStatementAGroupedCountRuns(): void
    {
        $count = $this->connection->select('user_id')->from('posts')->groupBy('user_id')->timeout(5000)->count();

        $this->assertSame(3, $count);
    }

    public function testTheCastModeConvertsTheValueAnAggregateReadsFromTheGroups(): void
    {
        $latest = $this->connection->select('user_id', [Expression::max('created_at'), 'latest'])
            ->from('posts')
            ->groupBy('user_id')
            ->castMode(CastMode::Datetime)
            ->max('latest');

        $this->assertInstanceOf(DateTimeImmutable::class, $latest);
    }
}
