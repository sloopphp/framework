<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class UpdateTest extends TransactionalIntegrationTestCase
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
     * @return list<array{id: int, status: string, score: int, deleted_at: string|null}>
     */
    private function users(): array
    {
        $rows = [];

        foreach ($this->connection->query('SELECT id, status, score, deleted_at FROM users ORDER BY id') as $row) {
            self::assertIsInt($row['id']);
            self::assertIsString($row['status']);
            self::assertIsInt($row['score']);
            self::assertTrue($row['deleted_at'] === null || \is_string($row['deleted_at']));

            $rows[] = [
                'id'         => $row['id'],
                'status'     => $row['status'],
                'score'      => $row['score'],
                'deleted_at' => $row['deleted_at'],
            ];
        }

        return $rows;
    }

    public function testWritesTheMatchingRowsAndReportsHowMany(): void
    {
        $changed = $this->connection->update('users')
            ->set(['status' => 'active'])
            ->where('status', 'blocked')
            ->execute();

        $this->assertSame(2, $changed);
        $this->assertSame(['active', 'active', 'active'], array_column($this->users(), 'status'));
    }

    public function testTheCountIsOfTheRowsChangedRatherThanTheRowsMatched(): void
    {
        $changed = $this->connection->update('users')->set(['status' => 'active'])->execute();

        $this->assertSame(2, $changed, 'the row already holding "active" is matched but not written');
    }

    public function testAnExpressionIsWrittenAgainstTheStoredValue(): void
    {
        $changed = $this->connection->update('users')
            ->set(['score' => Expression::of('score + ?', [5])])
            ->where('status', 'blocked')
            ->execute();

        $this->assertSame(2, $changed);
        $this->assertSame([10, 25, 35], array_column($this->users(), 'score'));
    }

    public function testNullIsWrittenOverAStoredValue(): void
    {
        $this->connection->statement('UPDATE users SET deleted_at = NOW() WHERE id = 2');

        $changed = $this->connection->update('users')->set(['deleted_at' => null])->where('id', 2)->execute();

        $this->assertSame(1, $changed);
        $this->assertNull($this->users()[1]['deleted_at']);
    }

    public function testTheServerTakesTheOrderAndLimitAsTheRowsToChangeFirst(): void
    {
        $changed = $this->connection->update('users')
            ->set(['status' => 'archived'])
            ->where('status', 'blocked')
            ->orderBy('score', 'DESC')
            ->limit(1)
            ->execute();

        $this->assertSame(1, $changed);
        $this->assertSame(['active', 'blocked', 'archived'], array_column($this->users(), 'status'));
    }

    public function testAQualifiedColumnNamesTheTableBeingUpdated(): void
    {
        $changed = $this->connection->update('users')
            ->set(['users.status' => 'archived'])
            ->where('id', 1)
            ->execute();

        $this->assertSame(1, $changed);
        $this->assertSame('archived', $this->users()[0]['status']);
    }

    public function testAGroupOfConditionsReachesTheServerWithItsParentheses(): void
    {
        $changed = $this->connection->update('users')
            ->set(['status' => 'archived'])
            ->where('status', 'blocked')
            ->andWhereOpen()
                ->where('score', '<', 15)
                ->orWhere('score', '>', 25)
            ->whereClose()
            ->execute();

        $this->assertSame(1, $changed);
        $this->assertSame(['active', 'blocked', 'archived'], array_column($this->users(), 'status'));
    }

    public function testReportsZeroWhenNothingMatched(): void
    {
        $changed = $this->connection->update('users')->set(['status' => 'active'])->where('status', 'gone')->execute();

        $this->assertSame(0, $changed);
        $this->assertSame(['active', 'blocked', 'blocked'], array_column($this->users(), 'status'));
    }

    public function testWithoutConditionsEveryRowIsWritten(): void
    {
        $changed = $this->connection->update('users')->set(['score' => 0])->execute();

        $this->assertSame(3, $changed);
        $this->assertSame([0, 0, 0], array_column($this->users(), 'score'));
    }
}
