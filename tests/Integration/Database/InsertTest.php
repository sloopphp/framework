<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use Sloop\Database\Exception\ConstraintViolationException;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Exception\SyntaxErrorException;
use Sloop\Database\Exception\UniqueConstraintViolationException;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class InsertTest extends TransactionalIntegrationTestCase
{
    use ThrowsAssertions;

    private const string BIG_ID_TABLE = 'sloop_big_id_rows';

    private const string TWO_KEY_TABLE = 'sloop_two_unique_rows';

    /**
     * A table named after what the row alias would shadow if it were named badly.
     *
     * The alias and the table share a namespace, so an INSERT into a table of
     * the alias's name is refused (MySQL 1066) while MariaDB, which is never
     * sent the alias form, goes on working. `new` reads as the obvious name for
     * the alias and was the first one written here, so a table of that name is
     * what catches a change back towards it.
     */
    private const string ALIAS_BAIT_TABLE = 'new';

    /**
     * A table named exactly what the row alias is called.
     *
     * Reaching this one does not depend on guessing the alias: `sloop_upsert`
     * is also what a pool with `prefix => 'sloop_'` makes of a table named
     * `upsert`. The alias steps aside rather than the statement failing.
     */
    private const string ALIAS_TAKEN_TABLE = 'sloop_upsert';

    protected static function setUpSharedFixtures(): void
    {
        $connection = static::openConnection();

        foreach ([self::ALIAS_BAIT_TABLE, self::ALIAS_TAKEN_TABLE] as $index => $table) {
            $connection->statement('DROP TABLE IF EXISTS `' . $table . '`');
            $connection->statement(
                'CREATE TABLE `' . $table . '` ('
                    . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                    . 'email VARCHAR(100) NOT NULL, '
                    . 'score INT NOT NULL, '
                    . 'UNIQUE KEY alias_table_email_' . $index . ' (email)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            );
        }

        $connection->statement('DROP TABLE IF EXISTS ' . self::TWO_KEY_TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::TWO_KEY_TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'email VARCHAR(100) NOT NULL, '
                . 'nick VARCHAR(100) NOT NULL, '
                . 'score INT NOT NULL, '
                . 'UNIQUE KEY two_unique_email (email), '
                . 'UNIQUE KEY two_unique_nick (nick)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->statement('DROP TABLE IF EXISTS ' . self::BIG_ID_TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::BIG_ID_TABLE . ' ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'label VARCHAR(50) NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->statement(
            'ALTER TABLE ' . self::BIG_ID_TABLE . ' AUTO_INCREMENT = 9223372036854775808',
        );
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    private function users(): array
    {
        $rows = [];

        foreach ($this->connection->query('SELECT id, name, email FROM users ORDER BY id') as $row) {
            self::assertIsInt($row['id']);
            self::assertIsString($row['name']);
            self::assertIsString($row['email']);

            $rows[] = ['id' => $row['id'], 'name' => $row['name'], 'email' => $row['email']];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, email: string, score: int}>
     */
    private function scoredUsers(): array
    {
        $rows = [];

        foreach ($this->connection->query('SELECT name, email, score FROM users ORDER BY id') as $row) {
            self::assertIsString($row['name']);
            self::assertIsString($row['email']);
            self::assertIsInt($row['score']);

            $rows[] = ['name' => $row['name'], 'email' => $row['email'], 'score' => $row['score']];
        }

        return $rows;
    }

    /**
     * @return list<array{email: string, nick: string, score: int}>
     */
    private function twoKeyRows(): array
    {
        $rows = [];

        foreach ($this->connection->query('SELECT email, nick, score FROM ' . self::TWO_KEY_TABLE . ' ORDER BY id') as $row) {
            self::assertIsString($row['email']);
            self::assertIsString($row['nick']);
            self::assertIsInt($row['score']);

            $rows[] = ['email' => $row['email'], 'nick' => $row['nick'], 'score' => $row['score']];
        }

        return $rows;
    }

    public function testWritesTheRowAndReportsTheIdItWasGiven(): void
    {
        $id = $this->connection->insert('users')
            ->set(['name' => 'alice', 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')])
            ->execute();

        $this->assertSame([['id' => $id, 'name' => 'alice', 'email' => 'alice@example.com']], $this->users());
    }

    public function testTheIdReportedForSeveralRowsNamesTheFirstOfThem(): void
    {
        // MySQL's LAST_INSERT_ID() answers with the id of the first row of the
        // batch, not the last. SQLite answers with the last, which is why this
        // is pinned here rather than in the unit test.
        $id = $this->connection->insert('users')
            ->values([
                ['name' => 'alice', 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')],
                ['name' => 'bob', 'email' => 'bob@example.com', 'created_at' => Expression::of('NOW()')],
            ])
            ->execute();

        $rows = $this->users();

        $this->assertCount(2, $rows);
        $this->assertSame($id, $rows[0]['id'], 'the id names the first row');
        $this->assertSame('alice', $rows[0]['name']);
        $this->assertSame('bob', $rows[1]['name']);
    }

    public function testASecondRowWithADuplicateKeyEndsTheStatement(): void
    {
        $this->connection->insert('users')
            ->set(['name' => 'alice', 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')])
            ->execute();

        $insert = $this->connection->insert('users')
            ->set(['name' => 'bob', 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')]);

        $this->assertThrows(UniqueConstraintViolationException::class, static fn (): int|string => $insert->execute());
        $this->assertCount(1, $this->users(), 'the refused row was not written');
    }

    public function testExecuteIgnoreSkipsTheRowTheServerRefusesAndWritesTheRest(): void
    {
        $this->connection->insert('users')
            ->set(['name' => 'alice', 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')])
            ->execute();

        $this->connection->insert('users')
            ->values([
                ['name' => 'bob', 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')],
                ['name' => 'carol', 'email' => 'carol@example.com', 'created_at' => Expression::of('NOW()')],
            ])
            ->executeIgnore();

        $this->assertSame(['alice', 'carol'], array_column($this->users(), 'name'));
    }

    public function testExecuteIgnoreWritesAValueTheColumnCannotHoldRatherThanSkippingTheRow(): void
    {
        // IGNORE does more than skip: a value the column cannot hold is coerced
        // to fit and the row is written. execute() ends the statement on the
        // same input, which is the difference the docblock has to state.
        $tooLong = str_repeat('a', 101);

        $refused = $this->connection->insert('users')
            ->set(['name' => $tooLong, 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')]);

        $this->assertThrows(QueryException::class, static fn (): int|string => $refused->execute());

        $this->connection->insert('users')
            ->set(['name' => $tooLong, 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')])
            ->executeIgnore();

        $rows = $this->users();

        $this->assertCount(1, $rows, 'the row was written rather than skipped');
        $this->assertSame(str_repeat('a', 100), $rows[0]['name'], 'the value was cut to fit the column');
    }

    public function testExecuteIgnoreWritesNullIntoANotNullColumnAsItsEmptyValue(): void
    {
        // The other half of what IGNORE coerces. Measured on MySQL 8.0 and
        // MariaDB 10.11: both store the column's empty value rather than
        // skipping the row.
        $refused = $this->connection->insert('users')
            ->set(['name' => null, 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')]);

        $this->assertThrows(ConstraintViolationException::class, static fn (): int|string => $refused->execute());

        $this->connection->insert('users')
            ->set(['name' => null, 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')])
            ->executeIgnore();

        $rows = $this->users();

        $this->assertCount(1, $rows, 'the row was written rather than skipped');
        $this->assertSame('', $rows[0]['name'], 'null became the column empty value');
    }

    public function testExecuteIgnoreReportsNoIdWhenEveryRowWasSkipped(): void
    {
        $this->connection->insert('users')
            ->set(['name' => 'alice', 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')])
            ->execute();

        $id = $this->connection->insert('users')
            ->set(['name' => 'bob', 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')])
            ->executeIgnore();

        $this->assertSame(0, $id);
        $this->assertCount(1, $this->users());
    }

    public function testAnExpressionIsWrittenAsSqlRatherThanBound(): void
    {
        $id = $this->connection->insert('users')
            ->set([
                'name'       => 'alice',
                'email'      => Expression::of('CONCAT(?, ?)', ['alice', '@example.com']),
                'created_at' => Expression::of('NOW()'),
            ])
            ->execute();

        $this->assertSame([['id' => $id, 'name' => 'alice', 'email' => 'alice@example.com']], $this->users());
    }

    public function testAnIdBeyondWhatAnIntHoldsIsReportedAsItsDigits(): void
    {
        $id = $this->connection->insert(self::BIG_ID_TABLE)
            ->set(['label' => 'alice'])
            ->execute();

        $this->assertSame('9223372036854775808', $id);
    }

    public function testAnIdThatFitsAnIntIsReportedAsOne(): void
    {
        $id = $this->connection->insert('users')
            ->set(['name' => 'alice', 'email' => 'alice@example.com', 'created_at' => Expression::of('NOW()')])
            ->execute();

        $this->assertIsInt($id);
    }

    public function testUpsertOverwritesTheNamedColumnsOfTheRowItCollidesWith(): void
    {
        $this->connection->insert('users')
            ->set([
                'name'       => 'alice',
                'email'      => 'alice@example.com',
                'score'      => 10,
                'created_at' => '2026-01-01 00:00:00',
            ])
            ->execute();

        $this->connection->insert('users')
            ->set([
                'name'       => 'alice the second',
                'email'      => 'alice@example.com',
                'score'      => 99,
                'created_at' => '2026-02-02 00:00:00',
            ])
            ->upsert(['name', 'score'])
            ->execute();

        $this->assertSame(
            [['name' => 'alice the second', 'email' => 'alice@example.com', 'score' => 99]],
            $this->scoredUsers(),
        );
    }

    public function testUpsertLeavesTheColumnsItDoesNotNameAsTheyWere(): void
    {
        $this->connection->insert('users')
            ->set([
                'name'       => 'alice',
                'email'      => 'alice@example.com',
                'score'      => 10,
                'created_at' => '2026-01-01 00:00:00',
            ])
            ->execute();

        $this->connection->insert('users')
            ->set([
                'name'       => 'alice the second',
                'email'      => 'alice@example.com',
                'score'      => 99,
                'created_at' => '2026-02-02 00:00:00',
            ])
            ->upsert(['score'])
            ->execute();

        $this->assertSame(
            [['name' => 'alice', 'email' => 'alice@example.com', 'score' => 99]],
            $this->scoredUsers(),
        );
    }

    public function testUpsertWritesTheRowWhenNothingCollidesWithIt(): void
    {
        $this->connection->insert('users')
            ->set([
                'name'       => 'alice',
                'email'      => 'alice@example.com',
                'score'      => 10,
                'created_at' => '2026-01-01 00:00:00',
            ])
            ->upsert(['score'])
            ->execute();

        $this->assertSame(
            [['name' => 'alice', 'email' => 'alice@example.com', 'score' => 10]],
            $this->scoredUsers(),
        );
    }

    public function testUpsertWritesTheRowsThatDoNotCollideAlongsideTheOneThatDoes(): void
    {
        $this->connection->insert('users')
            ->set([
                'name'       => 'alice',
                'email'      => 'alice@example.com',
                'score'      => 10,
                'created_at' => '2026-01-01 00:00:00',
            ])
            ->execute();

        $this->connection->insert('users')
            ->values([
                [
                    'name'       => 'alice again',
                    'email'      => 'alice@example.com',
                    'score'      => 99,
                    'created_at' => '2026-02-02 00:00:00',
                ],
                [
                    'name'       => 'bob',
                    'email'      => 'bob@example.com',
                    'score'      => 20,
                    'created_at' => '2026-02-02 00:00:00',
                ],
            ])
            ->upsert(['score'])
            ->execute();

        $this->assertSame(
            [
                ['name' => 'alice', 'email' => 'alice@example.com', 'score' => 99],
                ['name' => 'bob', 'email' => 'bob@example.com', 'score' => 20],
            ],
            $this->scoredUsers(),
        );
    }

    public function testUpsertFiresForWhicheverUniqueKeyTheRowRanInto(): void
    {
        // Neither server takes a list of keys to watch, which is why upsert()
        // has no place to name one: a row colliding on any unique index is
        // updated. Here the emails differ and only the nick collides.
        $this->connection->insert(self::TWO_KEY_TABLE)
            ->set(['email' => 'alice@example.com', 'nick' => 'alice', 'score' => 10])
            ->execute();

        $this->connection->insert(self::TWO_KEY_TABLE)
            ->set(['email' => 'someone.else@example.com', 'nick' => 'alice', 'score' => 99])
            ->upsert(['score'])
            ->execute();

        $this->assertSame(
            [['email' => 'alice@example.com', 'nick' => 'alice', 'score' => 99]],
            $this->twoKeyRows(),
        );
    }

    public function testUpsertReportsTheIdOfTheRowItOverwrote(): void
    {
        $existing = $this->connection->insert('users')
            ->set([
                'name'       => 'alice',
                'email'      => 'alice@example.com',
                'score'      => 10,
                'created_at' => '2026-01-01 00:00:00',
            ])
            ->execute();

        $reported = $this->connection->insert('users')
            ->set([
                'name'       => 'alice',
                'email'      => 'alice@example.com',
                'score'      => 99,
                'created_at' => '2026-01-01 00:00:00',
            ])
            ->upsert(['score'])
            ->execute();

        $this->assertSame($existing, $reported);
    }

    public function testUpsertReportsNoIdWhenTheCollisionChangedNothing(): void
    {
        $this->connection->insert('users')
            ->set([
                'name'       => 'alice',
                'email'      => 'alice@example.com',
                'score'      => 10,
                'created_at' => '2026-01-01 00:00:00',
            ])
            ->execute();

        $reported = $this->connection->insert('users')
            ->set([
                'name'       => 'alice',
                'email'      => 'alice@example.com',
                'score'      => 10,
                'created_at' => '2026-01-01 00:00:00',
            ])
            ->upsert(['score'])
            ->execute();

        $this->assertSame(0, $reported);
    }

    /**
     * @param string $table Table to upsert into, named so it could shadow the alias
     */
    #[DataProvider('tablesThatCouldShadowTheRowAlias')]
    public function testUpsertIntoATableThatCouldShadowTheRowAliasIsStillWritten(string $table): void
    {
        // The alias is the framework's to pick, so a caller whose table lands on
        // it would have no way out but to rename the table. What makes this
        // worth pinning is that it fails on one server only: MariaDB is never
        // sent the alias form and would go on working.
        $this->connection->insert($table)
            ->set(['email' => 'alice@example.com', 'score' => 10])
            ->execute();

        $this->connection->insert($table)
            ->set(['email' => 'alice@example.com', 'score' => 99])
            ->upsert(['score'])
            ->execute();

        $rows = [];

        foreach ($this->connection->query('SELECT email, score FROM `' . $table . '` ORDER BY id') as $row) {
            self::assertIsString($row['email']);
            self::assertIsInt($row['score']);

            $rows[] = ['email' => $row['email'], 'score' => $row['score']];
        }

        $this->assertSame([['email' => 'alice@example.com', 'score' => 99]], $rows);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tablesThatCouldShadowTheRowAlias(): array
    {
        return [
            'the name the alias was first given' => [self::ALIAS_BAIT_TABLE],
            'the name the alias now carries'     => [self::ALIAS_TAKEN_TABLE],
        ];
    }

    public function testTheIdReportedWhenBothUpsertedRowsCollideNamesTheSecond(): void
    {
        // With one row the id names the row that was written or overwritten.
        // With several it is the server's to decide and need not be the first —
        // pinned here so the documented advice to read the rows back keeps its
        // reason. Both rows below collide, and the id names the second.
        $first = $this->connection->insert('users')
            ->set(['name' => 'alice', 'email' => 'alice@example.com', 'score' => 1, 'created_at' => '2026-01-01 00:00:00'])
            ->execute();

        $second = $this->connection->insert('users')
            ->set(['name' => 'bob', 'email' => 'bob@example.com', 'score' => 2, 'created_at' => '2026-01-01 00:00:00'])
            ->execute();

        $reported = $this->connection->insert('users')
            ->values([
                ['name' => 'alice', 'email' => 'alice@example.com', 'score' => 91, 'created_at' => '2026-01-01 00:00:00'],
                ['name' => 'bob', 'email' => 'bob@example.com', 'score' => 92, 'created_at' => '2026-01-01 00:00:00'],
            ])
            ->upsert(['score'])
            ->execute();

        $this->assertSame($second, $reported);
        $this->assertNotSame($first, $reported);
    }

    public function testWhatTheGrammarBelievesAboutTheRowAliasIsWhatThisServerDoes(): void
    {
        // The form an upsert is sent in is picked from the server version, and
        // getting that wrong is a syntax error rather than a wrong answer:
        // MariaDB 10.11 refuses the alias outright. This holds the prediction
        // against the server rather than against another reading of the docs.
        $believed = new Grammar()->supportsRowAlias(
            $this->connection->dialect(),
            $this->connection->serverVersion(),
        );

        $accepted = true;

        try {
            $this->connection->statement(
                'INSERT INTO `users` (`name`, `email`, `score`, `created_at`) VALUES (?, ?, ?, ?)'
                    . ' AS `sloop_upsert` ON DUPLICATE KEY UPDATE `score` = `sloop_upsert`.`score`',
                ['alice', 'alice@example.com', 10, '2026-01-01 00:00:00'],
            );
        } catch (SyntaxErrorException) {
            $accepted = false;
        }

        $this->assertSame($believed, $accepted);
    }
}
