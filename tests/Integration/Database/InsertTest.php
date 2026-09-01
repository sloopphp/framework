<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Exception\ConstraintViolationException;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Exception\UniqueConstraintViolationException;
use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

final class InsertTest extends TransactionalIntegrationTestCase
{
    use ThrowsAssertions;

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

        $this->assertThrows(UniqueConstraintViolationException::class, static fn (): int => $insert->execute());
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

        $this->assertThrows(QueryException::class, static fn (): int => $refused->execute());

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

        $this->assertThrows(ConstraintViolationException::class, static fn (): int => $refused->execute());

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
}
