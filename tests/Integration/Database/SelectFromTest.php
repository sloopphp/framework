<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Exception\QueryException;
use Sloop\Database\Query\Grammar;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests run against SQLite, which writes and reads a derived table
// happily. What only a real server answers is whether it accepts the clause as
// this grammar writes it, whether it refuses one without an alias — the reason
// the builder requires one — and whether the values land in the placeholders
// the FROM clause opened rather than the ones the WHERE clause did.
final class SelectFromTest extends TransactionalIntegrationTestCase
{
    use ThrowsAssertions;

    private const string PREFIXED_TABLE = 'sloop_from_widgets';

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
                . ' (1, 1, ?, 1, NOW()), (2, 1, ?, 1, NOW()), (3, 2, ?, 0, NOW())',
            ['a1', 'a2', 'b1'],
        );
    }

    public function testReadsTheRowsOfAStatementStandingInForATable(): void
    {
        $ids = $this->connection->select('id')
            ->from($this->connection->select('id')->from('users')->where('status', 'active'), 'sub')
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2], $ids);
    }

    public function testTheValuesOfBothClausesLandInTheirOwnPlaceholders(): void
    {
        // FROM is written before WHERE, so its value is bound first. Swapping
        // the two would compare score against 'active' and status against 15,
        // which the server would answer without complaining.
        $ids = $this->connection->select('id')
            ->from($this->connection->select('id', 'score')->from('users')->where('status', 'active'), 'sub')
            ->where('score', '>', 15)
            ->pluck('id');

        $this->assertSame([2], $ids);
    }

    public function testGroupsCanBeCountedByReadingTheGroupedStatementAsATable(): void
    {
        // Counting groups needs the grouped statement read as a table, because
        // COUNT(*) written next to GROUP BY counts the rows of one group. Both
        // posts of user 1 are published, so counting rows would answer 2.
        $authors = $this->connection->select('user_id')
            ->from('posts')
            ->where('published', 1)
            ->groupBy('user_id');

        $this->assertSame(1, $this->connection->select()->from($authors, 'sub')->count());
    }

    public function testTheServerRefusesAStatementReadAsATableWithoutAnAlias(): void
    {
        // Why the builder requires an alias: neither engine accepts the clause
        // without one, and they answer with different codes, so the message a
        // caller would see depends on which server the code runs against.
        $thrown = $this->assertThrows(
            QueryException::class,
            fn () => $this->connection->query('SELECT id FROM (SELECT id FROM users) WHERE id = ?', [1]),
        );

        $this->assertStringContainsString('42000', $thrown->getMessage());
    }

    public function testWrappingTheReadLetsAWriteReadTheTableItWritesOnBothServers(): void
    {
        // MySQL refuses a write whose subquery reads the table being written
        // (1093) where MariaDB runs it. Reading the subquery as a table makes
        // it a separate result set, which both servers accept — the workaround
        // the database guide gives for that split.
        $active = $this->connection->select('id')->from('users')->where('status', 'active');

        $changed = $this->connection->update('users')
            ->set(['status' => 'archived'])
            ->whereIn('id', $this->connection->select('x.id')->from($active, 'x'))
            ->execute();

        $this->assertSame(2, $changed);
    }

    public function testATableNameReadUnderAnAliasIsTheSameTable(): void
    {
        $names = $this->connection->select('u.name')
            ->from('users', 'u')
            ->where('u.status', 'active')
            ->orderBy('u.name')
            ->pluck('name');

        $this->assertSame(['alice', 'bob'], $names);
    }

    public function testAnAliasCarriesThePrefixSoQualifiedColumnsReachTheSameName(): void
    {
        $this->connection->setGrammar(new Grammar('sloop_from_'));
        $this->connection->statement('INSERT INTO ' . self::PREFIXED_TABLE . ' (label) VALUES (?)', ['first']);

        $labels = $this->connection->select('w.label')->from('widgets', 'w')->pluck('label');

        $this->assertSame(['first'], $labels);
    }
}
