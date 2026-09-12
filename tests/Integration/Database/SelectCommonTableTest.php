<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// SQLite answers a plain WITH clause the same way the servers do, so what is
// left for a real server is the shape the builder writes around it: the clause
// leading a combined statement, and a recursive body written as the union of
// two parenthesised statements, which SQLite does not read at all.
final class SelectCommonTableTest extends TransactionalIntegrationTestCase
{
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
                . ' (1, 1, ?, 1, NOW()), (2, 3, ?, 0, NOW())',
            ['a1', 'c1'],
        );
    }

    public function testANamedStatementIsReadWhereATableNameStands(): void
    {
        $names = $this->connection->select('users.name')
            ->from('users')
            ->join('posted')
            ->on('posted.user_id', '=', 'users.id')
            ->with('posted', $this->connection->select('user_id')->from('posts')->where('published', 1))
            ->orderBy('users.id')
            ->pluck('name');

        $this->assertSame(['alice'], $names);
    }

    public function testTheColumnsNamedOnTheClauseAreTheOnesTheRowsComeBackUnder(): void
    {
        $rows = $this->connection->select('total')
            ->from('counted')
            ->with('counted', $this->connection->select(Expression::of('COUNT(*)'))->from('posts'), ['total'])
            ->get();

        $this->assertSame([['total' => 2]], $rows);
    }

    public function testARecursiveStatementWalksFromItsFirstRowsOnward(): void
    {
        // The step reads the name being defined, which is what the keyword
        // allows. Each round takes the row whose id follows the one already
        // reached, so the rows come back in the order they were walked.
        $seed = $this->connection->select('id')->from('users')->where('id', 1);
        $step = $this->connection->select('users.id')
            ->from('users')
            ->join('walk')
            ->on('users.id', '=', Expression::of('`walk`.`id` + 1'));

        $ids = $this->connection->select('id')
            ->from('walk')
            ->withRecursive('walk', $seed->unionAll($step), ['id'])
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2, 3], $ids);
    }

    public function testTheClauseLeadsACombinedStatement(): void
    {
        // Written inside the parentheses of a combined statement instead,
        // MariaDB answers 1064; the builder keeps it in front of them.
        $ids = $this->connection->select('user_id')
            ->from('posted')
            ->with('posted', $this->connection->select('user_id')->from('posts')->where('published', 1))
            ->union($this->connection->select('id')->from('users')->where('status', 'blocked'))
            ->orderBy('user_id')
            ->pluck('user_id');

        $this->assertSame([1, 3], $ids);
    }

    public function testTheNameIsInReachOfEveryStatementOfACombination(): void
    {
        // The clause leads the whole combination rather than the statement it
        // was declared on, so a statement added to it reads the name too.
        $ids = $this->connection->select('id')
            ->from('users')
            ->where('status', 'blocked')
            ->with('posted', $this->connection->select('user_id')->from('posts')->where('published', 1))
            ->union($this->connection->select('user_id')->from('posted'))
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 3], $ids);
    }

    public function testTheClauseIsStillReadWhenTheCombinedRowsAreCounted(): void
    {
        $count = $this->connection->select('user_id')
            ->from('posted')
            ->with('posted', $this->connection->select('user_id')->from('posts'))
            ->union($this->connection->select('id')->from('users')->where('status', 'blocked'))
            ->count();

        $this->assertSame(2, $count);
    }

    public function testANameIntroducedByTheClauseHidesATableOfTheSameName(): void
    {
        // Both servers read the name the clause introduces rather than the
        // table, which is what lets a statement stand in for one while it is
        // being written.
        $ids = $this->connection->select('id')
            ->from('posts')
            ->with('posts', $this->connection->select('id')->from('users')->where('score', '>', 15))
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([2, 3], $ids);
    }
}
