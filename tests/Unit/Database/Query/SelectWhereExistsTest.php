<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\CompiledSql;
use Sloop\Database\Query\ExistsCondition;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;

final class SelectWhereExistsTest extends TestCase
{
    use ThrowsAssertions;

    private Connection $connection;

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->createFunction('version', static fn (): string => '8.0.37');
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL)');
        $sqlite->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, paid INTEGER NOT NULL)');
        $sqlite->exec("INSERT INTO users (id, name, status) VALUES (1, 'alice', 'active'),"
            . " (2, 'bob', 'active'), (3, 'carol', 'blocked')");
        $sqlite->exec('INSERT INTO orders (id, user_id, paid) VALUES (1, 1, 1), (2, 2, 0), (3, 1, 1)');

        $this->connection = new Connection($sqlite, 'exists_test');
    }

    private function paidOrders(): Select
    {
        return $this->connection->select('id')->from('orders')->where('paid', 1);
    }

    private function ordersOfTheRowBeingRead(): Select
    {
        return $this->connection->select('id')
            ->from('orders')
            ->where('orders.user_id', '=', Expression::of('users.id'));
    }

    public function testWhereExistsWritesTheStatementInsideTheParentheses(): void
    {
        $select = $this->connection->select('id')->from('users')->whereExists($this->paidOrders());

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE EXISTS (SELECT `id` FROM `orders` WHERE `paid` = ?)',
            $select->toSql(),
        );
    }

    public function testWhereNotExistsNegatesTheTest(): void
    {
        $select = $this->connection->select('id')->from('users')->whereNotExists($this->paidOrders());

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE NOT EXISTS (SELECT `id` FROM `orders` WHERE `paid` = ?)',
            $select->toSql(),
        );
    }

    public function testOrWhereExistsJoinsTheTestToWhatPrecedesItWithOr(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->orWhereExists($this->paidOrders());

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `status` = ? OR EXISTS (SELECT `id` FROM `orders` WHERE `paid` = ?)',
            $select->toSql(),
        );
        $this->assertSame(['active', 1], $select->toBindings());
    }

    public function testAndWhereExistsIsTheSameStatementAsWhereExists(): void
    {
        $named    = $this->connection->select('id')->from('users')->where('status', 'active')
            ->whereExists($this->paidOrders());
        $spelling = $this->connection->select('id')->from('users')->where('status', 'active')
            ->andWhereExists($this->paidOrders());

        $this->assertSame($named->toSql(), $spelling->toSql());
        $this->assertSame($named->toBindings(), $spelling->toBindings());
    }

    public function testOrWhereNotExistsJoinsTheTestToWhatPrecedesItWithOr(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->orWhereNotExists($this->paidOrders());

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `status` = ? OR NOT EXISTS (SELECT `id` FROM `orders` WHERE `paid` = ?)',
            $select->toSql(),
        );
        $this->assertSame(['active', 1], $select->toBindings());
    }

    public function testAndWhereNotExistsIsTheSameStatementAsWhereNotExists(): void
    {
        $named    = $this->connection->select('id')->from('users')->where('status', 'active')
            ->whereNotExists($this->paidOrders());
        $spelling = $this->connection->select('id')->from('users')->where('status', 'active')
            ->andWhereNotExists($this->paidOrders());

        $this->assertSame($named->toSql(), $spelling->toSql());
        $this->assertSame($named->toBindings(), $spelling->toBindings());
    }

    public function testTheStatementCarriesItsOwnBindings(): void
    {
        $select = $this->connection->select('id')->from('users')->whereExists($this->paidOrders());

        $this->assertSame([1], $select->toBindings());
    }

    public function testTheStatementBindingsStandWhereItsPlaceholdersAre(): void
    {
        // The conditions are written in the order they were added, so the inner
        // statement's values belong between the ones on either side of it
        // rather than at the end.
        $select = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->whereExists($this->paidOrders())
            ->where('name', '!=', 'dave');

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `status` = ? AND EXISTS'
            . ' (SELECT `id` FROM `orders` WHERE `paid` = ?) AND `name` != ?',
            $select->toSql(),
        );
        $this->assertSame(['active', 1, 'dave'], $select->toBindings());
    }

    public function testTheStatementIsReadWhenTheOuterStatementIsCompiled(): void
    {
        // The builder is held rather than compiled on the spot, so a condition
        // added to it afterwards is part of the statement that runs.
        $inner  = $this->paidOrders();
        $select = $this->connection->select('id')->from('users')->whereExists($inner);

        $inner->where('user_id', '>', 1);

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE EXISTS'
            . ' (SELECT `id` FROM `orders` WHERE `paid` = ? AND `user_id` > ?)',
            $select->toSql(),
        );
        $this->assertSame([1, 1], $select->toBindings());
    }

    public function testATestMayHoldOneOfItsOwn(): void
    {
        // The grammar reaches a nested statement the same way at any depth, so
        // this holds the binding order rather than the nesting itself: each
        // statement's values stand where its own placeholders are.
        $select = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->whereExists(
                $this->connection->select('id')
                    ->from('orders')
                    ->where('paid', 1)
                    ->whereExists($this->connection->select('id')->from('users')->where('status', 'blocked')),
            )
            ->where('name', '!=', 'dave');

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `status` = ? AND EXISTS'
            . ' (SELECT `id` FROM `orders` WHERE `paid` = ? AND EXISTS'
            . ' (SELECT `id` FROM `users` WHERE `status` = ?)) AND `name` != ?',
            $select->toSql(),
        );
        $this->assertSame(['active', 1, 'blocked', 'dave'], $select->toBindings());
    }

    public function testAStatementReadingTheRowBeingTestedNarrowsTheOuterRows(): void
    {
        // The point of the test: the inner statement names a column of the
        // outer one, so it is answered once per row rather than once.
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereExists($this->ordersOfTheRowBeingRead())
            ->orderBy('id')
            ->pluck('id');

        $this->assertSame([1, 2], $ids);
    }

    public function testWhereNotExistsReadsTheRowsTheStatementLeavesOut(): void
    {
        $ids = $this->connection->select('id')
            ->from('users')
            ->whereNotExists($this->ordersOfTheRowBeingRead())
            ->pluck('id');

        $this->assertSame([3], $ids);
    }

    public function testTheColumnsTheInnerStatementSelectsDoNotMatter(): void
    {
        // Unlike an IN test, which compares against what the rows hold, this
        // one only asks whether a row came back. More than one column is
        // therefore a shape the server accepts.
        $select = $this->connection->select('id')
            ->from('users')
            ->whereExists($this->connection->select('id', 'user_id')->from('orders'));

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE EXISTS (SELECT `id`, `user_id` FROM `orders`)',
            $select->toSql(),
        );
        $select->orderBy('id');

        $this->assertSame([1, 2, 3], $select->pluck('id'));
    }

    public function testTheInnerStatementCarriesTheTablePrefixAndTheExpressionDoesNot(): void
    {
        // Tables and qualified columns the builder is told about are prefixed
        // on both sides of the test. What stands inside an expression is
        // written as given, so a column of the outer statement named there
        // carries the prefix only if the caller spelled it.
        $this->connection->setGrammar(new Grammar('app_'));

        $select = $this->connection->select('id')
            ->from('users')
            ->whereExists(
                $this->connection->select('id')
                    ->from('orders')
                    ->where('orders.user_id', '=', Expression::of('app_users.id')),
            );

        $this->assertSame(
            'SELECT `id` FROM `app_users` WHERE EXISTS'
            . ' (SELECT `id` FROM `app_orders` WHERE `app_orders`.`user_id` = app_users.id)',
            $select->toSql(),
        );
    }

    public function testAGrammarSubclassCanReplaceHowThePresenceTestIsWritten(): void
    {
        // The test is compiled by a method of its own, so a dialect that spells
        // it differently replaces that one rather than the whole WHERE clause.
        $this->connection->setGrammar(new class () extends Grammar {
            protected function compileExists(ExistsCondition $condition): CompiledSql
            {
                return new CompiledSql($condition->negated ? 'NO ROWS' : 'ANY ROW');
            }
        });

        $select = $this->connection->select('id')->from('users')->whereExists($this->paidOrders());

        $this->assertSame('SELECT `id` FROM `users` WHERE ANY ROW', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testAStatementThatNamesNoTableIsRefusedWhenTheOuterStatementCompiles(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->whereExists($this->connection->select('id'));

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertStringContainsString('call from() before compiling', $thrown->getMessage());
    }

    public function testAStatementWithAnOpenGroupIsRefusedWhenTheOuterStatementCompiles(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->whereExists($this->connection->select('id')->from('orders')->whereOpen());

        $this->assertThrows(LogicException::class, static fn () => $select->toSql());
    }

    public function testUpdateTakesTheTestInItsWhereClause(): void
    {
        // whereExists() lives on the shared base, so the write builders reach
        // the same machinery.
        $update = $this->connection->update('users')
            ->set(['status' => 'blocked'])
            ->whereExists($this->paidOrders());

        $this->assertSame(
            'UPDATE `users` SET `status` = ? WHERE EXISTS (SELECT `id` FROM `orders` WHERE `paid` = ?)',
            $update->toSql(),
        );
        $this->assertSame(['blocked', 1], $update->toBindings());
    }

    public function testDeleteTakesTheTestInItsWhereClause(): void
    {
        $delete = $this->connection->delete('users')->whereNotExists($this->paidOrders());

        $this->assertSame(
            'DELETE FROM `users` WHERE NOT EXISTS (SELECT `id` FROM `orders` WHERE `paid` = ?)',
            $delete->toSql(),
        );
        $this->assertSame([1], $delete->toBindings());
    }

    public function testUpdateTakesTheOrSpellingsTheSharedBaseCarries(): void
    {
        $update = $this->connection->update('users')
            ->set(['status' => 'blocked'])
            ->where('name', 'alice')
            ->orWhereExists($this->paidOrders());

        $this->assertSame(
            'UPDATE `users` SET `status` = ? WHERE `name` = ?'
            . ' OR EXISTS (SELECT `id` FROM `orders` WHERE `paid` = ?)',
            $update->toSql(),
        );
        $this->assertSame(['blocked', 'alice', 1], $update->toBindings());
    }

    public function testDeleteTakesTheOrSpellingsTheSharedBaseCarries(): void
    {
        $delete = $this->connection->delete('users')
            ->where('name', 'alice')
            ->orWhereIn('id', [1, 2]);

        $this->assertSame('DELETE FROM `users` WHERE `name` = ? OR `id` IN (?, ?)', $delete->toSql());
        $this->assertSame(['alice', 1, 2], $delete->toBindings());
    }
}
