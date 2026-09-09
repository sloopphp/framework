<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Dialect;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests run against SQLite, which answers a presence test the same way
// for every shape. What only a real server can settle is which shapes it takes
// where a membership test refuses them, since that difference is the reason to
// reach for this test rather than whereIn().
final class SelectWhereExistsTest extends TransactionalIntegrationTestCase
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
                . ' (1, 1, ?, 1, NOW()), (2, 2, ?, 0, NOW())',
            ['a1', 'b1'],
        );
    }

    private function postsOfTheRowBeingRead(): Select
    {
        return $this->connection->select('id')
            ->from('posts')
            ->where('posts.user_id', '=', Expression::of('users.id'));
    }

    public function testAStatementReadingTheRowBeingTestedNarrowsTheOuterRows(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereExists($this->postsOfTheRowBeingRead())
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2], $ids);
    }

    public function testWhereNotExistsReadsTheRowsTheStatementLeavesOut(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereNotExists($this->postsOfTheRowBeingRead())
            ->pluck('id');

        $this->assertSame([3], $ids);
    }

    public function testTheServerTakesAStatementReturningMoreThanOneColumn(): void
    {
        // A membership test is refused here with 1241, because it compares
        // against what the rows hold. This one does not, and both servers take
        // it — which is why the builder does not count the columns either.
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereExists($this->connection->select('id', 'published')->from('posts'))
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2, 3], $ids);
    }

    public function testTheServerTakesAStatementCarryingARowWindow(): void
    {
        // The other shape a membership test is refused for, with 1235. Asking
        // whether a row came back is answered the same whether or not the
        // statement was cut short, so neither server objects.
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereExists($this->connection->select('id')->from('posts')->orderBy('id', 'DESC')->limit(1))
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2, 3], $ids);
    }

    public function testAStatementReturningNullStillCountsAsARow(): void
    {
        // Where NOT IN is undone by a null among the rows it reads, this test
        // is not: deleted_at is null for every user, and the rows still count.
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereExists($this->connection->select('deleted_at')->from('users'))
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2, 3], $ids);
    }

    public function testAStatementWithNoMatchingRowsLeavesTheOuterStatementEmpty(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereExists($this->connection->select('id')->from('posts')->where('published', 99))
            ->pluck('id');

        $this->assertSame([], $ids);
    }

    public function testUpdateReachesTheSameMachineryThroughTheSharedBase(): void
    {
        $changed = $this->connection->update('users')
            ->set(['status' => 'archived'])
            ->whereExists($this->postsOfTheRowBeingRead())
            ->execute();

        $this->assertSame(2, $changed);
        $this->assertSame(
            ['archived'],
            $this->connection->select('status')->from('users')->where('id', 1)->pluck('status'),
        );
    }

    public function testDeleteReachesTheSameMachineryThroughTheSharedBase(): void
    {
        $deleted = $this->connection->delete('posts')
            ->whereNotExists($this->connection->select('id')->from('users')->where('status', 'nobody'))
            ->execute();

        $this->assertSame(2, $deleted);
    }

    public function testTheServersDisagreeOnAWriteThatReadsTheTableItWrites(): void
    {
        // The same split a membership test has: MySQL refuses it with 1093 and
        // MariaDB runs it. The shape is standard and one server implements it,
        // so this is left as each answers it rather than refused here.
        $update = $this->connection->update('users')
            ->set(['status' => 'archived'])
            ->whereExists($this->connection->select('id')->from('users')->where('status', 'active'));

        if ($this->connection->dialect() === Dialect::MySQL) {
            $thrown = $this->assertThrows(QueryException::class, static fn () => $update->execute());

            $this->assertStringContainsString('1093', $thrown->getMessage());

            return;
        }

        $this->assertSame(3, $update->execute());
    }
}
