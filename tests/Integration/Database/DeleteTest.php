<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class DeleteTest extends TransactionalIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers();
    }

    private function seedUsers(): void
    {
        $this->connection->statement(
            'INSERT INTO users (id, name, email, status, score, created_at) VALUES'
                . ' (1, ?, ?, ?, ?, NOW()),'
                . ' (2, ?, ?, ?, ?, NOW()),'
                . ' (3, ?, ?, ?, ?, NOW())',
            [
                'alice', 'alice@example.com', 'active', 10,
                'bob', 'bob@example.com', 'blocked', 20,
                'carol', 'carol@example.com', 'blocked', 30,
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function remainingIds(): array
    {
        $ids = [];

        foreach ($this->connection->query('SELECT id FROM users ORDER BY id') as $row) {
            $id = $row['id'];
            self::assertIsInt($id);
            $ids[] = $id;
        }

        return $ids;
    }

    public function testRemovesTheMatchingRowsAndReportsHowMany(): void
    {
        $removed = $this->connection->delete('users')->where('status', 'blocked')->execute();

        $this->assertSame(2, $removed);
        $this->assertSame([1], $this->remainingIds());
    }

    public function testTheServerTakesTheOrderAndLimitAsTheRowsToRemoveFirst(): void
    {
        $removed = $this->connection->delete('users')
            ->where('status', 'blocked')
            ->orderBy('score', 'DESC')
            ->limit(1)
            ->execute();

        $this->assertSame(1, $removed);
        $this->assertSame([1, 2], $this->remainingIds());
    }

    public function testAGroupOfConditionsReachesTheServerWithItsParentheses(): void
    {
        $removed = $this->connection->delete('users')
            ->where('status', 'blocked')
            ->andWhereOpen()
                ->where('score', '<', 15)
                ->orWhere('score', '>', 25)
            ->whereClose()
            ->execute();

        $this->assertSame(1, $removed);
        $this->assertSame([1, 2], $this->remainingIds());
    }

    public function testAnExpressionIsBoundInPlaceholderOrder(): void
    {
        $removed = $this->connection->delete('users')
            ->where('score', '>', Expression::of('? + ?', [5, 15]))
            ->execute();

        $this->assertSame(1, $removed);
        $this->assertSame([1, 2], $this->remainingIds());
    }

    public function testReportsZeroWhenNothingMatched(): void
    {
        $removed = $this->connection->delete('users')->where('status', 'gone')->execute();

        $this->assertSame(0, $removed);
        $this->assertSame([1, 2, 3], $this->remainingIds());
    }

    public function testWithoutConditionsEveryRowGoes(): void
    {
        $removed = $this->connection->delete('users')->execute();

        $this->assertSame(3, $removed);
        $this->assertSame([], $this->remainingIds());
    }
}
