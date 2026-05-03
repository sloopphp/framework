<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use Sloop\Database\Connection;
use Sloop\Database\Dialect;
use Sloop\Database\Exception\ForeignKeyViolationException;
use Sloop\Database\Exception\SyntaxErrorException;
use Sloop\Database\Exception\UniqueConstraintViolationException;
use Sloop\Database\IsolationLevel;
use Sloop\Tests\Support\IntegrationTestCase;

final class ConnectionTest extends IntegrationTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->openConnection();
        $this->connection->statement('DROP TABLE IF EXISTS sloop_connection_test');
        $this->connection->statement(
            'CREATE TABLE sloop_connection_test ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'name VARCHAR(64) NOT NULL UNIQUE, '
                . 'balance INT NOT NULL DEFAULT 0'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    protected function tearDown(): void
    {
        // Order matters: drop the FK child before the parent.
        $this->connection->statement('DROP TABLE IF EXISTS sloop_fk_child');
        $this->connection->statement('DROP TABLE IF EXISTS sloop_fk_parent');
        $this->connection->statement('DROP TABLE IF EXISTS sloop_connection_test');
    }

    public function testOpenProducesConnectionWithSloopDefaults(): void
    {
        // sloop PDO defaults are verified through their observable effects:
        // - ATTR_ERRMODE=EXCEPTION → syntax errors throw QueryException
        //   (covered by testUniqueConstraintViolationIsMapped and DDL failures)
        // - ATTR_DEFAULT_FETCH_MODE=FETCH_ASSOC → every query() returns
        //   associative arrays (covered by testStatementAndQueryRoundTrip)
        // - ATTR_EMULATE_PREPARES=false + ATTR_STRINGIFY_FETCHES=false →
        //   integers come back as native ints (covered by
        //   testQueryReturnsNativeIntegerTypes)
        //
        // Here we just assert the Connection is usable end-to-end, which
        // confirms open() produced a working, configured handle.
        $rows = $this->connection->query('SELECT 1 AS v')->toArray();
        $this->assertSame(1, $rows[0]['v']);
    }

    public function testQueryReturnsNativeIntegerTypes(): void
    {
        $this->connection->statement(
            'INSERT INTO sloop_connection_test (name, balance) VALUES (?, ?)',
            ['alice', 100],
        );

        $rows = $this->connection->query('SELECT id, balance FROM sloop_connection_test')->toArray();

        $this->assertCount(1, $rows);
        $this->assertIsInt($rows[0]['id']);
        $this->assertIsInt($rows[0]['balance']);
        $this->assertSame(100, $rows[0]['balance']);
    }

    public function testStatementAndQueryRoundTrip(): void
    {
        $affected = $this->connection->statement(
            'INSERT INTO sloop_connection_test (name, balance) VALUES (?, ?), (?, ?)',
            ['alice', 100, 'bob', 50],
        );

        $this->assertSame(2, $affected);

        $rows = $this->connection->query(
            'SELECT name FROM sloop_connection_test ORDER BY id',
        )->toArray();

        $this->assertSame([['name' => 'alice'], ['name' => 'bob']], $rows);
    }

    public function testTransactionCommitPersistsAndRollbackDiscards(): void
    {
        $this->connection->begin();
        $this->connection->statement(
            'INSERT INTO sloop_connection_test (name) VALUES (?)',
            ['alice'],
        );
        $this->connection->commit();

        $this->connection->begin();
        $this->connection->statement(
            'INSERT INTO sloop_connection_test (name) VALUES (?)',
            ['bob'],
        );
        $this->connection->rollback();

        $rows = $this->connection->query(
            'SELECT name FROM sloop_connection_test ORDER BY id',
        )->toArray();
        $this->assertSame([['name' => 'alice']], $rows);
    }

    /**
     * @return array<string, array{IsolationLevel}>
     */
    public static function nonDefaultIsolationLevelProvider(): array
    {
        return [
            'read uncommitted' => [IsolationLevel::ReadUncommitted],
            'read committed'   => [IsolationLevel::ReadCommitted],
            'repeatable read'  => [IsolationLevel::RepeatableRead],
            'serializable'     => [IsolationLevel::Serializable],
        ];
    }

    // MySQL/MariaDB's @@transaction_isolation reflects the session default,
    // not the per-transaction SET TRANSACTION override, so we can't assert
    // the exact level from inside the transaction. These cases verify that
    // SET TRANSACTION is accepted and the transaction commits cleanly.
    #[DataProvider('nonDefaultIsolationLevelProvider')]
    public function testBeginAcceptsExplicitIsolationLevel(IsolationLevel $level): void
    {
        $this->connection->begin($level);
        $rows = $this->connection->query('SELECT 1 AS v')->toArray();
        $this->connection->commit();

        $this->assertSame(1, $rows[0]['v']);
    }

    public function testTransactionWrapperCommitsOnSuccess(): void
    {
        $this->connection->transaction(function (Connection $db): void {
            $db->statement(
                'INSERT INTO sloop_connection_test (name) VALUES (?)',
                ['alice'],
            );
        });

        $rows = $this->connection->query(
            'SELECT name FROM sloop_connection_test',
        )->toArray();
        $this->assertSame([['name' => 'alice']], $rows);
    }

    public function testTransactionWrapperRollsBackOnUniqueViolation(): void
    {
        $this->connection->statement(
            'INSERT INTO sloop_connection_test (name) VALUES (?)',
            ['alice'],
        );

        try {
            $this->connection->transaction(function (Connection $db): void {
                $db->statement(
                    'INSERT INTO sloop_connection_test (name) VALUES (?)',
                    ['bob'],
                );
                $db->statement(
                    'INSERT INTO sloop_connection_test (name) VALUES (?)',
                    ['alice'],
                );
            });
            $this->fail('Expected UniqueConstraintViolationException');
        } catch (UniqueConstraintViolationException) {
            // The throw is the point of this test; the exception's field
            // mapping is verified by testUniqueConstraintViolationIsMapped.
        }

        $this->assertFalse($this->connection->inTransaction());
        $rows = $this->connection->query(
            'SELECT name FROM sloop_connection_test',
        )->toArray();
        $this->assertSame([['name' => 'alice']], $rows);
    }

    public function testUniqueConstraintViolationIsMapped(): void
    {
        $this->connection->statement(
            'INSERT INTO sloop_connection_test (name) VALUES (?)',
            ['alice'],
        );

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->statement(
            'INSERT INTO sloop_connection_test (name) VALUES (?)',
            ['alice'],
        );
    }

    public function testForeignKeyViolationIsMapped(): void
    {
        $this->connection->statement(
            'CREATE TABLE sloop_fk_parent (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        );
        $this->connection->statement(
            'CREATE TABLE sloop_fk_child ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'parent_id INT UNSIGNED NOT NULL, '
                . 'FOREIGN KEY (parent_id) REFERENCES sloop_fk_parent(id)'
                . ') ENGINE=InnoDB',
        );

        $this->expectException(ForeignKeyViolationException::class);
        $this->connection->statement(
            'INSERT INTO sloop_fk_child (parent_id) VALUES (?)',
            [999],
        );
    }

    public function testSyntaxErrorIsMapped(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->connection->statement('NOT VALID SQL');
    }

    public function testDialectMatchesServerVersion(): void
    {
        $version  = $this->connection->serverVersion();
        $expected = str_contains($version, 'MariaDB') ? Dialect::MariaDB : Dialect::MySQL;

        $this->assertSame($expected, $this->connection->dialect());
    }

    public function testServerVersionReturnsNonEmptyString(): void
    {
        $this->assertNotSame('', $this->connection->serverVersion());
    }

    public function testPingSucceedsOnLiveConnection(): void
    {
        // ping() returns void on success; verify the connection stays usable
        // for a follow-up query, which is the production caller's reason for
        // pinging in the first place.
        $this->connection->ping();

        $rows = $this->connection->query('SELECT 1 AS one')->toArray();

        $this->assertSame([['one' => 1]], $rows);
    }

}
