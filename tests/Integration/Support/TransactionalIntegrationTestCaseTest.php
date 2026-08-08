<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Support;

use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Sloop\Database\Connection;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class TransactionalIntegrationTestCaseTest extends TransactionalIntegrationTestCase
{
    private const string TABLE = 'sloop_transactional_fixture_test';

    private static function openConnectionStatically(): Connection
    {
        $host = self::envOrDefault('DB_HOST', '127.0.0.1');
        $port = self::envOrDefault('DB_PORT', '3306');
        $name = self::envOrDefault('DB_NAME', 'sloop_test');

        return Connection::open(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            self::envOrDefault('DB_USER', 'sloop'),
            self::envOrDefault('DB_PASS', 'secret'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            'fixture-observer',
        );
    }

    private static function envOrDefault(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    public static function setUpBeforeClass(): void
    {
        // DDL commits implicitly, so the table has to exist before the
        // per-test transaction opens. The parent's openConnection() is an
        // instance method and no instance exists yet, hence the direct open.
        $connection = self::openConnectionStatically();
        $connection->statement('DROP TABLE IF EXISTS ' . self::TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'name VARCHAR(64) NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::openConnectionStatically()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    public function testSetUpLeavesTheConnectionInsideATransaction(): void
    {
        $this->assertTrue($this->connection->inTransaction());
    }

    public function testWritesAreInvisibleToOtherConnectionsUntilTheFixtureEnds(): void
    {
        $this->connection->statement('INSERT INTO ' . self::TABLE . ' (name) VALUES (?)', ['alice']);

        $this->assertCount(1, $this->connection->query('SELECT id FROM ' . self::TABLE)->toArray());

        // A separate session proves the row is still uncommitted rather than
        // merely absent from a stale read.
        $observer = self::openConnectionStatically();
        $this->assertCount(0, $observer->query('SELECT id FROM ' . self::TABLE)->toArray());
    }

    #[Depends('testWritesAreInvisibleToOtherConnectionsUntilTheFixtureEnds')]
    public function testRowsWrittenByThePreviousTestAreGone(): void
    {
        $this->assertCount(0, $this->connection->query('SELECT id FROM ' . self::TABLE)->toArray());
    }

    public function testTeardownRejectsAFixtureTransactionThatWasAlreadyCommitted(): void
    {
        // A committed fixture leaks rows into the next test, so teardown has to
        // report it rather than roll back whatever happens to be open.
        $this->connection->commit();

        try {
            $this->tearDown();
            $this->fail('Expected tearDown() to reject the committed fixture transaction.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('committed its writes', $e->getMessage());
        }

        // Hand a live transaction back so the real teardown finds one.
        $this->connection->begin();
    }
}
