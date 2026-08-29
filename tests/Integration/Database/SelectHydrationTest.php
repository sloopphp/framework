<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use DateTimeImmutable;
use RuntimeException;
use Sloop\Tests\Integration\Database\Stub\SelectedUser;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// What the unit tests cannot answer: the values a real driver returns are the
// ones the constructor has to accept. A DTO declaring int for an INT column
// only works if the driver hands back an int, and that is a property of the
// connection rather than of Result.
final class SelectHydrationTest extends TransactionalIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection->statement(
            'INSERT INTO users (id, name, email, status, score, created_at) VALUES'
                . ' (1, ?, ?, ?, ?, ?),'
                . ' (2, ?, ?, ?, ?, ?)',
            [
                'alice', 'alice@example.com', 'active', 10, '2026-01-02 03:04:05',
                'bob', 'bob@example.com', 'blocked', 20, '2026-02-03 04:05:06',
            ],
        );
    }

    public function testHydratesTheColumnsTheDriverReturnsIntoDeclaredTypes(): void
    {
        $users = $this->connection->select('id', 'name', 'score', 'status', 'created_at')
            ->from('users')
            ->orderBy('id')
            ->execute()
            ->asObject(SelectedUser::class);

        $this->assertCount(2, $users);
        $this->assertSame(1, $users[0]->id);
        $this->assertSame('alice', $users[0]->name);
        $this->assertSame(10, $users[0]->score);
        $this->assertSame('active', $users[0]->status);
        $this->assertEquals(new DateTimeImmutable('2026-01-02 03:04:05'), $users[0]->createdAt);
        $this->assertSame(2, $users[1]->id);
    }

    public function testAColumnLeftOutOfTheSelectFallsBackToTheParameterDefault(): void
    {
        $users = $this->connection->select('id', 'name', 'score', 'created_at')
            ->from('users')
            ->where('id', 1)
            ->execute()
            ->asObject(SelectedUser::class);

        $this->assertSame('unknown', $users[0]->status);
    }

    public function testAColumnLeftOutOfTheSelectIsReportedWhenTheParameterIsRequired(): void
    {
        $rows = $this->connection->select('id', 'name', 'created_at')
            ->from('users')
            ->where('id', 1)
            ->execute();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Row 0 has no column "score"/');

        $rows->asObject(SelectedUser::class);
    }
}
