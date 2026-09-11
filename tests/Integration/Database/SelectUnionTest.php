<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use ArrayObject;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Select;
use Sloop\Database\Result;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests read the SQL a union compiles to, since SQLite refuses the
// parenthesised shape. These run it: which rows come back, how the sort and
// row window inside and outside the parentheses divide the work, and what the
// shortcuts answer when they read the combined rows as a table of their own.
final class SelectUnionTest extends TransactionalIntegrationTestCase
{
    use ThrowsAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection->statement(
            'INSERT INTO users (id, name, email, status, score, created_at) VALUES'
                . ' (1, ?, ?, ?, 10, NOW()), (2, ?, ?, ?, 20, NOW()), (3, ?, ?, ?, 30, NOW())',
            [
                'alice', 'alice@example.com', 'active',
                'bob', 'bob@example.com', 'active',
                'carol', 'carol@example.com', 'blocked',
            ],
        );
        $this->connection->statement(
            'INSERT INTO posts (id, user_id, title, published, created_at) VALUES'
                . ' (1, 1, ?, 1, NOW()), (2, 2, ?, 0, NOW()), (3, 2, ?, 1, NOW())',
            ['a1', 'b1', 'b2'],
        );
    }

    private function usersWithAPost(): Select
    {
        return $this->connection->select('user_id')->from('posts');
    }

    public function testUnionDropsRowsReadTwice(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->union($this->usersWithAPost())
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2], $ids);
    }

    public function testUnionAllKeepsRowsReadTwice(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->unionAll($this->usersWithAPost())
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 1, 2, 2, 2], $ids);
    }

    public function testEachStatementKeepsTheRowsItsOwnSortAndLimitChoose(): void
    {
        // The highest scorer of each status: the sort and limit inside each
        // pair of parentheses pick one row from that statement alone.
        $ids = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->orderBy('score', 'DESC')
            ->limit(1)
            ->unionAll(
                $this->connection->select('id')
                    ->from('users')
                    ->where('status', 'blocked')
                    ->orderBy('score', 'DESC')
                    ->limit(1),
            )
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([2, 3], $ids);
    }

    public function testASortAndLimitAfterUnionCutTheCombinedRows(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->union($this->connection->select('id')->from('users')->where('status', 'blocked'))
            ->orderBy('id', 'DESC')
            ->limit(2)
            ->pluck('id');

        $this->assertSame([3, 2], $ids);
    }

    public function testEachStatementReceivesItsOwnValues(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->where('score', '>', 15)
            ->union($this->usersWithAPost()->where('published', 1))
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2, 3], $ids);
    }

    public function testCountIsOfTheRowsTheUnionReturns(): void
    {
        $users = $this->connection->select('id')->from('users');

        $this->assertSame(3, (clone $users)->union($this->usersWithAPost())->count());
        $this->assertSame(6, (clone $users)->unionAll($this->usersWithAPost())->count());
    }

    public function testCountTakesAGroupedStatementAsOneSourceOfRows(): void
    {
        $count = $this->connection->select('status')
            ->from('users')
            ->groupBy('status')
            ->unionAll($this->connection->select('status')->from('users'))
            ->count();

        $this->assertSame(5, $count);
    }

    public function testExistsLooksAtTheCombinedRows(): void
    {
        $nobody = $this->connection->select('id')->from('users')->where('id', 99);

        $this->assertFalse(
            (clone $nobody)->union($this->usersWithAPost()->where('id', 99))->exists(),
        );
        $this->assertTrue(
            (clone $nobody)->union($this->usersWithAPost()->where('id', 3))->exists(),
        );
    }

    public function testAnAggregateIsTakenOverTheCombinedRows(): void
    {
        $max = $this->connection->select('user_id')
            ->from('posts')
            ->union($this->connection->select('id')->from('users'))
            ->max('user_id');

        $this->assertSame(3, $max);
    }

    public function testValueReadsOneColumnOfTheCombinedRowsInTheirSort(): void
    {
        $name = $this->connection->select('id', 'name')
            ->from('users')
            ->union($this->connection->select('id', 'title')->from('posts'))
            ->orderBy('name', 'DESC')
            ->value('name');

        $this->assertSame('carol', $name);
    }

    public function testPluckReadsOneColumnOfTheCombinedRows(): void
    {
        $names = $this->connection->select('id', 'name')
            ->from('users')
            ->where('status', 'active')
            ->union($this->connection->select('id', 'name')->from('users')->where('status', 'blocked'))
            ->orderBy('id')
            ->pluck('name');

        $this->assertSame(['alice', 'bob', 'carol'], $names);
    }

    public function testPaginateCountsEveryCombinedRowAndCutsThePageFromThem(): void
    {
        $page = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->union($this->connection->select('id')->from('users')->where('status', 'blocked'))
            ->orderBy('id')
            ->paginate(2, 2);

        $this->assertSame(3, $page->total);
        $this->assertSame([['id' => 3]], $page->items->asArray());
    }

    public function testChunkByIdWalksTheCombinedRows(): void
    {
        /** @var ArrayObject<int, mixed> $seen */
        $seen = new ArrayObject();

        $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->union($this->connection->select('id')->from('users')->where('status', 'blocked'))
            ->chunkById(2, static function (Result $batch) use ($seen): bool {
                foreach ($batch->asArray() as $row) {
                    $seen->append($row['id']);
                }

                return true;
            });

        $this->assertSame([1, 2, 3], $seen->getArrayCopy());
    }

    public function testChunkByIdWalksAStatementSelectingQualifiedAndWrittenColumns(): void
    {
        /** @var ArrayObject<int, mixed> $seen */
        $seen = new ArrayObject();

        $this->connection->select('users.id')
            ->selectRaw('UPPER(name) AS n')
            ->from('users')
            ->where('status', 'active')
            ->union($this->connection->select('id', 'name')->from('users')->where('status', 'blocked'))
            ->chunkById(2, static function (Result $batch) use ($seen): bool {
                foreach ($batch->asArray() as $row) {
                    $seen->append($row['id']);
                }

                return true;
            });

        $this->assertSame([1, 2, 3], $seen->getArrayCopy());
    }

    public function testAShortcutReadingTheCombinedRowsNeedsEachColumnNamedOnce(): void
    {
        // Running the union itself takes two columns named id; reading it from
        // outside does not, on either server.
        $select = $this->connection->select('users.id', 'posts.id')
            ->from('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->union($this->connection->select('id', 'id')->from('users'));

        $this->assertCount(4, $select->get());

        $thrown = $this->assertThrows(QueryException::class, static fn () => $select->count());

        $this->assertSame(1060, $thrown->driverCode);
    }

    public function testAWindowCallNamedInEachStatementSortsTheCombinedRows(): void
    {
        // The way round the window call the combined sort refuses: each
        // statement selects it under the same name, and the sort reads the name.
        $numbered = [Expression::over('ROW_NUMBER', orders: ['id' => 'DESC']), 'rn'];

        $rows = $this->connection->select('id', $numbered)
            ->from('users')
            ->where('status', 'active')
            ->unionAll($this->connection->select('id', $numbered)->from('users')->where('status', 'blocked'))
            ->orderBy('rn')
            ->orderBy('id')
            ->get();

        $this->assertSame(
            [['id' => 2, 'rn' => 1], ['id' => 3, 'rn' => 1], ['id' => 1, 'rn' => 2]],
            $rows,
        );
    }

    public function testStatementsReadingDifferentNumbersOfColumnsAreRefusedByTheServer(): void
    {
        $select = $this->connection->select('id', 'name')->from('users')->union($this->usersWithAPost());

        $thrown = $this->assertThrows(QueryException::class, static fn () => $select->get());

        $this->assertSame(1222, $thrown->driverCode);
    }
}
