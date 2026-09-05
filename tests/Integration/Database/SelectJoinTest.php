<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use DateTimeImmutable;
use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class SelectJoinTest extends TransactionalIntegrationTestCase
{
    private const string COMMENTS_TABLE = 'sloop_join_comments';

    protected static function setUpSharedFixtures(): void
    {
        // The shared schema holds users and posts but nothing hanging off a
        // post, and a second join needs a third table: the same table cannot
        // be joined twice without an alias, which this builder does not write.
        $connection = static::openConnection();
        $connection->statement('DROP TABLE IF EXISTS ' . self::COMMENTS_TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::COMMENTS_TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'post_id INT UNSIGNED NOT NULL, '
                . 'body VARCHAR(50) NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection->statement(
            'INSERT INTO users (id, name, email, status, score, created_at) VALUES'
                . ' (1, ?, ?, ?, ?, NOW()),'
                . ' (2, ?, ?, ?, ?, NOW()),'
                . ' (3, ?, ?, ?, ?, NOW())',
            [
                'alice', 'alice@example.com', 'active', 10,
                'bob', 'bob@example.com', 'blocked', 20,
                'carol', 'carol@example.com', 'active', 30,
            ],
        );
        $this->connection->statement(
            'INSERT INTO posts (id, user_id, title, published, created_at) VALUES'
                . ' (1, 1, ?, 1, NOW()),'
                . ' (2, 1, ?, 0, NOW()),'
                . ' (3, 2, ?, 1, NOW())',
            ['alice out', 'alice draft', 'bob out'],
        );
        $this->connection->statement(
            'INSERT INTO ' . self::COMMENTS_TABLE . ' (id, post_id, body) VALUES (1, 1, ?)',
            ['first'],
        );
    }

    /**
     * Read one column of every row the statement returns, as a comma separated
     * string so that the order and the nulls land in one assertion.
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
                self::fail('The columns read here are text, got a date for ' . $column . '.');
            }

            $values[] = $value === null ? 'null' : (string) $value;
        }

        return implode(',', $values);
    }

    public function testAnInnerJoinKeepsOnlyThePairedRows(): void
    {
        $rows = $this->connection->select('users.name', 'posts.title')
            ->from('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->orderBy('posts.id', 'ASC')
            ->get();

        $this->assertSame('alice,alice,bob', self::column($rows, 'name'));
        $this->assertSame('alice out,alice draft,bob out', self::column($rows, 'title'));
    }

    public function testALeftJoinKeepsTheRowsThatFoundNoMatch(): void
    {
        $rows = $this->connection->select('users.name', 'posts.title')
            ->from('users')
            ->leftJoin('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->where('users.name', 'carol')
            ->get();

        $this->assertSame('carol', self::column($rows, 'name'));
        $this->assertSame('null', self::column($rows, 'title'));
    }

    public function testARightJoinKeepsEveryRowOfTheJoinedTable(): void
    {
        $rows = $this->connection->select('users.name', 'posts.title')
            ->from('posts')
            ->rightJoin('users')
            ->on('posts.user_id', '=', 'users.id')
            ->orderBy('users.id', 'ASC')
            ->orderBy('posts.id', 'ASC')
            ->get();

        $this->assertSame('alice,alice,bob,carol', self::column($rows, 'name'));
        $this->assertSame('alice out,alice draft,bob out,null', self::column($rows, 'title'));
    }

    public function testSeveralJoinsChainThroughTheTablesTheyName(): void
    {
        $rows = $this->connection->select('users.name', 'sloop_join_comments.body')
            ->from('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->join(self::COMMENTS_TABLE)
            ->on('sloop_join_comments.post_id', '=', 'posts.id')
            ->get();

        $this->assertSame('alice', self::column($rows, 'name'));
        $this->assertSame('first', self::column($rows, 'body'));
    }

    public function testAConditionAddedWithAndOnNarrowsThePairing(): void
    {
        $rows = $this->connection->select('users.name', 'posts.title')
            ->from('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->andOn('posts.published', '=', Expression::of('?', [1]))
            ->orderBy('posts.id', 'ASC')
            ->get();

        $this->assertSame('alice out,bob out', self::column($rows, 'title'));
    }

    public function testAValueInTheOnClauseKeepsTheUnmatchedRowsOfALeftJoin(): void
    {
        // The same test written as a WHERE would drop carol and alice's draft:
        // filtering after the pairing throws away the rows a left join keeps.
        $rows = $this->connection->select('users.name', 'posts.title')
            ->from('users')
            ->leftJoin('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->andOn('posts.published', '=', Expression::of('?', [1]))
            ->orderBy('users.id', 'ASC')
            ->get();

        $this->assertSame('alice,bob,carol', self::column($rows, 'name'));
        $this->assertSame('alice out,bob out,null', self::column($rows, 'title'));
    }

    public function testAGroupOfOnConditionsIsReadAsOneAlternative(): void
    {
        $rows = $this->connection->select('users.name', 'posts.title')
            ->from('users')
            ->join('posts')
            ->onOpen()
            ->on('posts.user_id', '=', 'users.id')
            ->andOn('posts.published', '=', Expression::of('?', [1]))
            ->onClose()
            ->orOn('posts.title', '=', Expression::of('?', ['alice draft']))
            ->orderBy('users.id', 'ASC')
            ->orderBy('posts.id', 'ASC')
            ->get();

        $this->assertSame('alice,alice,bob,bob,carol', self::column($rows, 'name'));
        $this->assertSame(
            'alice out,alice draft,alice draft,bob out,alice draft',
            self::column($rows, 'title'),
        );
    }

    public function testTheBindingsOfAJoinAndOfTheConditionsReachTheServerInOrder(): void
    {
        $rows = $this->connection->select('users.name', 'posts.title')
            ->from('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->andOn('posts.published', '=', Expression::of('?', [1]))
            ->where('users.status', 'active')
            ->get();

        $this->assertSame('alice', self::column($rows, 'name'));
        $this->assertSame('alice out', self::column($rows, 'title'));
    }
}
