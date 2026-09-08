<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Dialect;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests run against SQLite, which is lenient about what a subquery in
// an IN test may look like. What only a real server can answer is which shapes
// it refuses and how, since that is what decides whether the builder needs a
// guard of its own.
final class SelectSubQueryTest extends TransactionalIntegrationTestCase
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

    private function publishedAuthors(): Select
    {
        return $this->connection->select('user_id')->from('posts')->where('published', 1);
    }

    public function testWhereInReadsTheRowsTheSubqueryMatches(): void
    {
        $ids = $this->connection->select('id')->from('users')->whereIn('id', $this->publishedAuthors())->pluck('id');

        $this->assertSame([1], $ids);
    }

    public function testWhereNotInReadsTheRowsTheSubqueryLeavesOut(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereNotIn('id', $this->publishedAuthors())
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([2, 3], $ids);
    }

    public function testTheServerRefusesASubqueryThatReturnsMoreThanOneColumn(): void
    {
        // Why the builder does not check the column count itself: the server
        // names the problem, on both engines, with a code of its own.
        $select = $this->connection->select('id')
            ->from('users')
            ->whereIn('id', $this->connection->select('user_id', 'published')->from('posts'));

        $thrown = $this->assertThrows(QueryException::class, static fn () => $select->get());

        $this->assertStringContainsString('1241', $thrown->getMessage());
    }

    public function testTheServerRefusesASubqueryThatCarriesARowWindow(): void
    {
        // Neither engine supports LIMIT inside an IN subquery, and both say so
        // rather than answering with a subset, so this is left to them as well.
        $select = $this->connection->select('id')
            ->from('users')
            ->whereIn('id', $this->publishedAuthors()->limit(1));

        $thrown = $this->assertThrows(QueryException::class, static fn () => $select->get());

        $this->assertStringContainsString('1235', $thrown->getMessage());
    }

    public function testANullAmongTheSubqueryRowsMakesNotInMatchNothing(): void
    {
        // The trap the written-out set is guarded against and a subquery cannot
        // be. deleted_at is null for every user here, so the set holds nulls
        // and NOT IN answers with no row at all — not an error, and not the
        // rows a reader would expect.
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereNotIn('id', $this->connection->select('deleted_at')->from('users'))
            ->pluck('id');

        $this->assertSame([], $ids);
    }

    public function testASubqueryWithNoMatchingRowsLeavesTheOuterStatementEmpty(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereIn('id', $this->connection->select('user_id')->from('posts')->where('published', 99))
            ->pluck('id');

        $this->assertSame([], $ids);
    }

    public function testUpdateReachesTheSameMachineryThroughTheSharedBase(): void
    {
        $changed = $this->connection->update('users')
            ->set(['status' => 'archived'])
            ->whereIn('id', $this->publishedAuthors())
            ->execute();

        $this->assertSame(1, $changed);
        $this->assertSame(
            ['archived'],
            $this->connection->select('status')->from('users')->where('id', 1)->pluck('status'),
        );
    }

    public function testTheServersDisagreeOnAWriteThatReadsTheTableItWrites(): void
    {
        // The one shape where leaving the check to the server does not mean the
        // same thing on both. MySQL refuses it with 1093; MariaDB runs it and
        // updates the rows. SQLAlchemy's test requirements name this an
        // ANSI-standard syntax MySQL cannot handle, resolved in MariaDB 10.3,
        // so the builder does not refuse what one server supports.
        $update = $this->connection->update('users')
            ->set(['status' => 'archived'])
            ->whereIn('id', $this->connection->select('id')->from('users')->where('status', 'active'));

        if ($this->connection->dialect() === Dialect::MySQL) {
            $thrown = $this->assertThrows(QueryException::class, static fn () => $update->execute());

            $this->assertStringContainsString('1093', $thrown->getMessage());

            return;
        }

        $this->assertSame(2, $update->execute());
    }

    public function testDeleteReachesTheSameMachineryThroughTheSharedBase(): void
    {
        $deleted = $this->connection->delete('posts')
            ->whereNotIn('user_id', $this->connection->select('id')->from('users')->where('status', 'active'))
            ->execute();

        $this->assertSame(0, $deleted);
    }
}
