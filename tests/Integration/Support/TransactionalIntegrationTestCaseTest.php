<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Support;

use LogicException;
use PHPUnit\Framework\Attributes\Depends;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class TransactionalIntegrationTestCaseTest extends TransactionalIntegrationTestCase
{
    use ThrowsAssertions;

    private const string TABLE = 'sloop_transactional_fixture_test';

    #[\Override]
    protected static function setUpSharedFixtures(): void
    {
        // DDL commits implicitly, so the table has to exist before the
        // per-test transaction opens.
        $connection = self::openConnection();
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
        self::openConnection()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    public function testSetUpLeavesTheConnectionInsideATransaction(): void
    {
        $this->assertTrue($this->connection->inTransaction());
    }

    public function testWritesAreInvisibleToOtherConnectionsUntilTheFixtureEnds(): void
    {
        $this->connection->statement('INSERT INTO ' . self::TABLE . ' (name) VALUES (?)', ['alice']);

        $this->assertCount(1, $this->connection->query('SELECT id FROM ' . self::TABLE)->asArray());

        // A separate session proves the row is still uncommitted rather than
        // merely absent from a stale read.
        $observer = self::openConnection();
        $this->assertCount(0, $observer->query('SELECT id FROM ' . self::TABLE)->asArray());
    }

    #[Depends('testWritesAreInvisibleToOtherConnectionsUntilTheFixtureEnds')]
    public function testRowsWrittenByThePreviousTestAreGone(): void
    {
        $this->assertCount(0, $this->connection->query('SELECT id FROM ' . self::TABLE)->asArray());
    }

    public function testTeardownRejectsAFixtureTransactionThatWasAlreadyCommitted(): void
    {
        // A committed fixture leaks rows into the next test, so teardown has to
        // report it rather than roll back whatever happens to be open.
        $this->connection->commit();

        $e = $this->assertThrows(LogicException::class, fn () => $this->tearDown());
        $this->assertStringContainsString('committed its writes', $e->getMessage());

        // Hand a live transaction back so the real teardown finds one.
        $this->connection->begin();
    }
}
