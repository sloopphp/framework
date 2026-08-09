<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\Depends;
use Sloop\Tests\Support\SharedSchema;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class SharedSchemaLoadTest extends TransactionalIntegrationTestCase
{
    /**
     * @param  array<array-key, mixed> $row
     * @return string
     */
    private function stringColumn(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (!\is_string($value)) {
            $this->fail('Expected column ' . $column . ' to come back as a string.');
        }

        return $value;
    }

    public function testUsersTableAcceptsWritesAndReadsThemBack(): void
    {
        $this->connection->statement(
            'INSERT INTO users (name, email, status, score, created_at) VALUES (?, ?, ?, ?, ?)',
            ['alice', 'alice@example.com', 'active', 10, '2026-01-01 00:00:00'],
        );

        $rows = $this->connection->query('SELECT name, score, deleted_at FROM users')->toArray();

        $this->assertSame(
            [['name' => 'alice', 'score' => 10, 'deleted_at' => null]],
            $rows,
        );
    }

    #[Depends('testUsersTableAcceptsWritesAndReadsThemBack')]
    public function testUsersWrittenByThePreviousTestDoNotSurviveIntoThisOne(): void
    {
        // The shared tables outlive the class, so a leak here would poison every
        // later test that counts rows in users.
        $this->assertCount(0, $this->connection->query('SELECT id FROM users')->toArray());
    }

    public function testPostsTableAcceptsWritesAndReadsThemBack(): void
    {
        $this->connection->statement(
            'INSERT INTO posts (user_id, title, published, created_at) VALUES (?, ?, ?, ?)',
            [1, 'first post', 1, '2026-01-01 00:00:00'],
        );

        $rows = $this->connection->query('SELECT user_id, title, published FROM posts')->toArray();

        $this->assertSame(
            [['user_id' => 1, 'title' => 'first post', 'published' => 1]],
            $rows,
        );
    }

    #[Depends('testPostsTableAcceptsWritesAndReadsThemBack')]
    public function testPostsWrittenByThePreviousTestDoNotSurviveIntoThisOne(): void
    {
        $this->assertCount(0, $this->connection->query('SELECT id FROM posts')->toArray());
    }

    public function testEveryFixtureTableIsTransactionalAndUsesThePinnedCollation(): void
    {
        // A non-transactional engine makes the rollback in tearDown a no-op, and
        // a server-default collation makes string comparison differ between
        // MySQL and MariaDB. Both only surface far from their cause.
        //
        // The table list comes from the fixture rather than a literal here, so a
        // table added to schema.sql cannot slip past this check.
        $names        = SharedSchema::tableNames(SharedSchema::statements(SharedSchema::path()));
        $placeholders = implode(', ', array_fill(0, \count($names), '?'));

        $rows = $this->connection->query(
            'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_COLLATION AS collation'
                . ' FROM information_schema.tables'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
            $names,
        )->toArray();

        $actual = [];

        foreach ($rows as $row) {
            $actual[$this->stringColumn($row, 'name')] = [
                'engine'    => $this->stringColumn($row, 'engine'),
                'collation' => $this->stringColumn($row, 'collation'),
            ];
        }

        $expected = [];

        foreach ($names as $name) {
            $expected[$name] = ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];
        }

        ksort($actual);
        ksort($expected);

        $this->assertSame($expected, $actual);
    }
}
