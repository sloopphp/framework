<?php

declare(strict_types=1);

namespace Sloop\Tests\Support;

use LogicException;
use Sloop\Database\Connection;

/**
 * Base class for integration tests whose writes must not outlive the test.
 *
 * Each test runs inside a transaction that is rolled back afterwards, so a
 * test can insert, update and delete freely without cleaning up and without
 * leaking rows into the next one.
 *
 * The connection is opened per test and exposed as $connection; a test that
 * opens its own connection gets a separate session and is therefore outside
 * the fixture.
 *
 * Not suitable for every integration test:
 * - Tests that exercise transactions themselves (begin / commit / rollback,
 *   isolation levels, deadlock retries) cannot nest inside the fixture, since
 *   Connection::begin() rejects nesting.
 * - DDL commits implicitly on MySQL and ends the fixture's transaction. Table
 *   creation belongs in setUpBeforeClass, outside the per-test transaction.
 */
abstract class TransactionalIntegrationTestCase extends IntegrationTestCase
{
    /**
     * Connection wrapped in the per-test transaction.
     *
     * @var Connection
     */
    protected Connection $connection;

    /**
     * Open the connection and start the transaction that isolates the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->openConnection();
        $this->connection->begin();
    }

    /**
     * Roll back the transaction started in setUp().
     *
     * @return void
     * @throws LogicException When the transaction ended before teardown, which means the test's writes were committed
     */
    protected function tearDown(): void
    {
        if (!$this->connection->inTransaction()) {
            // Staying silent here would let committed rows reach the next test
            // and turn this fixture into a source of order-dependent failures.
            throw new LogicException(
                'The fixture transaction was already closed at teardown, so this test committed its writes. '
                    . 'A DDL statement (implicit commit on MySQL) or an explicit commit() is the usual cause. '
                    . 'Move DDL to setUpBeforeClass, or extend IntegrationTestCase instead of this class.',
            );
        }

        $this->connection->rollback();

        parent::tearDown();
    }
}
