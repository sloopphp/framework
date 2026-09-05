<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use DateTimeImmutable;
use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class UpdateJoinTest extends TransactionalIntegrationTestCase
{
    private const string SETTINGS_TABLE = 'sloop_update_settings';

    protected static function setUpSharedFixtures(): void
    {
        // A second join needs a third table: the shared schema holds users and
        // posts only, and the same table cannot be joined twice without an
        // alias, which this builder does not write.
        $connection = static::openConnection();
        $connection->statement('DROP TABLE IF EXISTS ' . self::SETTINGS_TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::SETTINGS_TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'user_id INT UNSIGNED NOT NULL, '
                . 'theme VARCHAR(20) NOT NULL'
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
                'bob', 'bob@example.com', 'active', 20,
                'carol', 'carol@example.com', 'active', 30,
            ],
        );
        // carol has no post, so a left join keeps her and an inner join drops her.
        $this->connection->statement(
            'INSERT INTO posts (id, user_id, title, published, created_at) VALUES'
                . ' (1, 1, ?, 1, NOW()),'
                . ' (2, 1, ?, 0, NOW()),'
                . ' (3, 2, ?, 1, NOW())',
            ['alice out', 'alice draft', 'bob out'],
        );
        // Only alice has a settings row, so a second join narrows further.
        $this->connection->statement(
            'INSERT INTO ' . self::SETTINGS_TABLE . ' (id, user_id, theme) VALUES (1, 1, ?)',
            ['dark'],
        );
    }

    /**
     * One column of a table in id order, comma separated so one assertion covers every row.
     *
     * @param  string $table  Table to read
     * @param  string $column Column to read from each row
     * @return string Values in id order
     */
    private function column(string $table, string $column): string
    {
        $values = [];

        foreach ($this->connection->select($column)->from($table)->orderBy('id')->get() as $row) {
            $value = $row[$column] ?? null;

            if ($value instanceof DateTimeImmutable) {
                self::fail('The columns read here are text or numbers, got a date for ' . $column . '.');
            }

            $values[] = $value === null ? 'null' : (string) $value;
        }

        return implode(',', $values);
    }

    public function testAJoinNarrowsAnUpdateToTheRowsThatPair(): void
    {
        $changed = $this->connection->update('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'author'])
            ->where('posts.published', '=', 1)
            ->execute();

        $this->assertSame(2, $changed, 'alice and bob each have a published post');
        $this->assertSame(
            'author,author,active',
            $this->column('users', 'status'),
            'carol has no post and is left alone',
        );
    }

    public function testAPairedRowIsWrittenOnceHoweverManyTimesItPairs(): void
    {
        $changed = $this->connection->update('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'author'])
            ->where('users.id', '=', 1)
            ->execute();

        $this->assertSame(1, $changed, 'alice pairs with two posts and is counted once');
        $this->assertSame('author,active,active', $this->column('users', 'status'));
    }

    public function testALeftJoinAlsoWritesToTheRowsThatFoundNoMatch(): void
    {
        $changed = $this->connection->update('users')
            ->leftJoin('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'seen'])
            ->allowWithoutWhere()
            ->execute();

        $this->assertSame(3, $changed, 'carol pairs with nothing and is written to anyway');
        $this->assertSame('seen,seen,seen', $this->column('users', 'status'));
    }

    public function testAnUpdateCanWriteToTheJoinedTable(): void
    {
        $changed = $this->connection->update('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['posts.published' => 1])
            ->where('users.id', '=', 1)
            ->execute();

        $this->assertSame(1, $changed, 'only the unpublished post of alice changes');

        $this->assertSame('1,1,1', $this->column('posts', 'published'));
    }

    public function testTheJoinedTableCanDecideTheValueThatIsWritten(): void
    {
        $this->connection->update('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => Expression::of('posts.title')])
            ->where('posts.published', '=', 1)
            ->execute();

        $this->assertSame('alice out,bob out,active', $this->column('users', 'status'));
    }

    public function testAnOnConditionCanCarryAValueAsAnExpression(): void
    {
        $changed = $this->connection->update('users')
            ->leftJoin('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->andOn('posts.published', '=', Expression::of('?', [1]))
            ->set(['users.status' => 'seen'])
            ->where('posts.id', 'IS', null)
            ->execute();

        $this->assertSame(1, $changed, 'only carol pairs with no published post');
        $this->assertSame('active,active,seen', $this->column('users', 'status'));
    }

    public function testSeveralJoinsNarrowOneAfterTheOther(): void
    {
        $changed = $this->connection->update('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->join(self::SETTINGS_TABLE)
            ->on(self::SETTINGS_TABLE . '.user_id', '=', 'users.id')
            ->set(['users.status' => 'settled'])
            ->allowWithoutWhere()
            ->execute();

        $this->assertSame(1, $changed, 'only alice has both a post and a settings row');
        $this->assertSame('settled,active,active', $this->column('users', 'status'));
    }
}
