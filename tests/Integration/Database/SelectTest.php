<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class SelectTest extends TransactionalIntegrationTestCase
{
    /**
     * Table the prefix test reads through the unprefixed name `widgets`.
     *
     * @var string
     */
    private const string PREFIXED_TABLE = 'sloop_prefix_widgets';

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
                'carol', 'carol@example.com', 'active', 30,
            ],
        );
    }

    public function testSelectReadsTheRowsItAsksFor(): void
    {
        $rows = $this->connection->select('name', 'score')
            ->from('users')
            ->where('status', 'active')
            ->orderBy('score', 'DESC')
            ->execute();

        $this->assertSame(
            [
                ['name' => 'carol', 'score' => 30],
                ['name' => 'alice', 'score' => 10],
            ],
            $rows->asArray(),
        );
    }

    public function testEveryColumnComesBackWhenNoneWereNamed(): void
    {
        $row = $this->connection->select()
            ->from('users')
            ->where('email', 'alice@example.com')
            ->execute()
            ->first();

        $this->assertIsArray($row);
        $this->assertSame('alice', $row['name']);
        $this->assertSame(10, $row['score']);
        $this->assertNull($row['deleted_at']);
    }

    public function testAQualifiedColumnIsAcceptedByTheServer(): void
    {
        $rows = $this->connection->select('users.name')
            ->from('users')
            ->where('users.status', 'blocked')
            ->execute();

        $this->assertSame([['name' => 'bob']], $rows->asArray());
    }

    public function testTheRowWindowNarrowsTheResult(): void
    {
        $rows = $this->connection->select('name')
            ->from('users')
            ->orderBy('id')
            ->limit(1)
            ->offset(1)
            ->execute();

        $this->assertSame([['name' => 'bob']], $rows->asArray());
    }

    public function testConditionsAreJoinedAsWritten(): void
    {
        $rows = $this->connection->select('name')
            ->from('users')
            ->where('status', 'blocked')
            ->orWhere('score', '>=', 30)
            ->orderBy('name')
            ->execute();

        $this->assertSame([['name' => 'bob'], ['name' => 'carol']], $rows->asArray());
    }

    public function testAnExpressionSortsByAHandWrittenSequence(): void
    {
        $rows = $this->connection->select('status')
            ->from('users')
            ->orderBy(Expression::field('status', ['blocked', 'active']))
            ->orderBy('id')
            ->execute();

        $this->assertSame(
            [['status' => 'blocked'], ['status' => 'active'], ['status' => 'active']],
            $rows->asArray(),
        );
    }

    public function testAValueIsBoundRatherThanWrittenIntoTheStatement(): void
    {
        // A value carrying a quote and a comment marker would end the statement
        // early if it were written into the SQL instead of bound.
        $this->connection->statement(
            'INSERT INTO users (name, email, status, score, created_at) VALUES (?, ?, ?, ?, NOW())',
            ["' OR 1=1 -- ", 'injection@example.com', 'active', 0],
        );

        $rows = $this->connection->select('email')
            ->from('users')
            ->where('name', "' OR 1=1 -- ")
            ->execute();

        $this->assertSame([['email' => 'injection@example.com']], $rows->asArray());
    }

    public function testThePrefixSendsTheStatementToThePrefixedTable(): void
    {
        $this->connection->setGrammar(new Grammar('sloop_prefix_'));
        $this->connection->statement(
            'INSERT INTO ' . self::PREFIXED_TABLE . ' (label) VALUES (?)',
            ['first'],
        );

        $rows = $this->connection->select('label')->from('widgets')->execute();

        $this->assertSame([['label' => 'first']], $rows->asArray());
    }

    public function testRawSqlShowsTheValuesTheServerWouldReceive(): void
    {
        $select = $this->connection->select('name')
            ->from('users')
            ->where('name', "O'Brien");

        // Which of the two escapings comes back is the driver's decision and
        // follows the session's sql_mode, so both are accepted. What matters is
        // that the quote inside the value was escaped either way: the naive
        // rendering 'O'Brien' would end the literal in the middle of the name.
        $this->assertContains($select->toRawSql(), [
            'SELECT `name` FROM `users` WHERE `name` = \'O\\\'Brien\'',
            'SELECT `name` FROM `users` WHERE `name` = \'O\'\'Brien\'',
        ]);
    }
}
