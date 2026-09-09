<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Exception\QueryException;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests run against SQLite, which answers a statement in the select
// list the same way. What only a real server settles is what a caller reads the
// column by when no name was given — the reason the builder requires one — and
// which of the shapes it refuses are worth refusing here rather than there.
final class SelectColumnAliasTest extends TransactionalIntegrationTestCase
{
    use ThrowsAssertions;

    private const string PREFIXED_TABLE = 'sloop_alias_widgets';

    protected static function setUpSharedFixtures(): void
    {
        $connection = static::openConnection();
        $connection->statement('DROP TABLE IF EXISTS ' . self::PREFIXED_TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::PREFIXED_TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'label VARCHAR(50) NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

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
                . ' (1, 1, ?, 1, NOW()), (2, 1, ?, 1, NOW()), (3, 2, ?, 0, NOW()), (4, 2, ?, 1, NOW())',
            ['a1', 'a2', 'b1', 'b2'],
        );
    }

    public function testAStatementInTheSelectListCountsPerRowOfTheOneAroundIt(): void
    {
        $postCount = $this->connection->select(Expression::of('COUNT(*)'))
            ->from('posts')
            ->where('posts.user_id', Expression::of('users.id'));

        $rows = $this->connection->select('id', [$postCount, 'post_count'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame(
            [
                ['id' => 1, 'post_count' => 2],
                ['id' => 2, 'post_count' => 2],
                ['id' => 3, 'post_count' => 0],
            ],
            $rows,
        );
    }

    public function testTheValuesOfTheSelectListAreBoundBeforeThoseOfEveryOtherClause(): void
    {
        // The select list is written first, so its value is bound first.
        // Swapping the two would count the posts published above 15 for every
        // user scoring exactly one, which the server answers without
        // complaining — with different rows and a different count.
        $publishedCount = $this->connection->select(Expression::of('COUNT(*)'))
            ->from('posts')
            ->where('posts.user_id', Expression::of('users.id'))
            ->where('published', 1);

        $rows = $this->connection->select('id', [$publishedCount, 'post_count'])
            ->from('users')
            ->where('score', '>', 15)
            ->orderBy('id')
            ->get();

        $this->assertSame(
            [
                ['id' => 2, 'post_count' => 1],
                ['id' => 3, 'post_count' => 0],
            ],
            $rows,
        );
    }

    public function testWithoutANameTheServerReturnsTheColumnUnderTheTextOfTheStatement(): void
    {
        // Why the builder requires a name: both engines accept the statement,
        // so nothing fails — the key the rows come back under is the statement
        // itself, and it changes with every edit to it.
        $row = $this->connection->query(
            'SELECT id, (SELECT COUNT(*) FROM posts WHERE posts.user_id = users.id) FROM users WHERE id = ?',
            [1],
        )->first();

        $this->assertSame(
            ['id' => 1, '(SELECT COUNT(*) FROM posts WHERE posts.user_id = users.id)' => 2],
            $row,
        );
    }

    public function testTheServerRefusesAStatementInTheSelectListReturningMoreThanOneRow(): void
    {
        // Left to the server rather than checked here: what a statement returns
        // is only known once it runs. Both engines answer with the same code,
        // and it is not the one they give for too many columns, so the two
        // shapes stay apart in what a caller sees.
        $titles = $this->connection->select('id', [$this->connection->select('title')->from('posts'), 'title']);

        $thrown = $this->assertThrows(
            QueryException::class,
            static fn () => $titles->from('users')->get(),
        );

        $this->assertSame(1242, $thrown->driverCode);
    }

    public function testTheServerRefusesAStatementInTheSelectListReturningMoreThanOneColumn(): void
    {
        $both = $this->connection->select('id', 'title')->from('posts')->where('id', 1);

        $thrown = $this->assertThrows(
            QueryException::class,
            fn () => $this->connection->select('id', [$both, 'post'])->from('users')->get(),
        );

        $this->assertSame(1241, $thrown->driverCode);
    }

    public function testAStatementInTheSelectListMayNarrowItselfToOneRow(): void
    {
        // Unlike the set of an IN condition, where both engines refuse a LIMIT
        // (1235), one standing as a column takes it.
        $firstTitle = $this->connection->select('title')
            ->from('posts')
            ->where('posts.user_id', Expression::of('users.id'))
            ->orderBy('id')
            ->limit(1);

        $rows = $this->connection->select('id', [$firstTitle, 'first_title'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame(
            [
                ['id' => 1, 'first_title' => 'a1'],
                ['id' => 2, 'first_title' => 'b1'],
                ['id' => 3, 'first_title' => null],
            ],
            $rows,
        );
    }

    public function testANameGetsNoPrefixSoTheRowsComeBackUnderWhatWasWritten(): void
    {
        // A prefix belongs on a name the server resolves against the schema.
        // This one is only a key in the rows, so prefixing it would hand the
        // caller a key it never wrote.
        $this->connection->setGrammar(new Grammar('sloop_alias_'));
        $this->connection->statement('INSERT INTO ' . self::PREFIXED_TABLE . ' (label) VALUES (?)', ['first']);

        $row = $this->connection->select(['label', 'name'])->from('widgets')->first();

        $this->assertSame(['name' => 'first'], $row);
    }

    public function testANameStandsWhereTheStatementGroupsAndSorts(): void
    {
        $rows = $this->connection->select(['status', 'state'], [Expression::of('COUNT(*)'), 'total'])
            ->from('users')
            ->groupBy('state')
            ->orderBy('state')
            ->get();

        $this->assertSame(
            [
                ['state' => 'active', 'total' => 2],
                ['state' => 'blocked', 'total' => 1],
            ],
            $rows,
        );
    }
}
