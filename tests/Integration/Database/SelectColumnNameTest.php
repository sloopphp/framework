<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests settle what SQL a named column is written as. What a server
// settles is that the name reaches the row it is meant to: a correlated
// reference has to resolve against the outer statement, which SQLite would
// answer the same way whether or not the name was quoted as an identifier.
final class SelectColumnNameTest extends TransactionalIntegrationTestCase
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
                . ' (1, 1, ?, 1, NOW()), (2, 2, ?, 0, NOW())',
            ['a1', 'b1'],
        );
    }

    public function testANamedColumnResolvesAgainstTheOuterRowOfACorrelatedSubquery(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereExists(
                $this->connection->select('id')
                    ->from('posts')
                    ->where('posts.user_id', '=', Expression::column('users.id')),
            )
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2], $ids);
    }

    public function testAComparisonBetweenTwoColumnsOfTheSameRowPicksTheRowsItHoldsFor(): void
    {
        $this->connection->update('users')->set(['score' => 1])->where('id', 2)->execute();

        $ids = $this->connection->select('id')
            ->from('users')
            ->where('score', '>', Expression::column('id'))
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 3], $ids);
    }

    public function testAnAssignmentReadsTheColumnItNamesOnTheRowBeingWritten(): void
    {
        $written = $this->connection->update('users')
            ->set(['status' => Expression::column('name')])
            ->where('id', 1)
            ->execute();

        $this->assertSame(1, $written);
        $this->assertSame(
            'alice',
            $this->connection->select('status')->from('users')->where('id', 1)->value('status'),
        );
    }
}
