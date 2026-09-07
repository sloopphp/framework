<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;

final class SelectSubQueryTest extends TestCase
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

        $this->connection = new Connection($sqlite, 'subquery_test');
    }

    private function paidUserIds(): Select
    {
        return $this->connection->select('user_id')->from('orders')->where('paid', 1);
    }

    public function testWhereInWritesTheSubqueryInsideTheParentheses(): void
    {
        $select = $this->connection->select('id')->from('users')->whereIn('id', $this->paidUserIds());

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `id` IN (SELECT `user_id` FROM `orders` WHERE `paid` = ?)',
            $select->toSql(),
        );
    }

    public function testWhereNotInNegatesTheSubqueryTest(): void
    {
        $select = $this->connection->select('id')->from('users')->whereNotIn('id', $this->paidUserIds());

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `id` NOT IN (SELECT `user_id` FROM `orders` WHERE `paid` = ?)',
            $select->toSql(),
        );
    }

    public function testTheSubqueryCarriesItsOwnBindings(): void
    {
        $select = $this->connection->select('id')->from('users')->whereIn('id', $this->paidUserIds());

        $this->assertSame([1], $select->toBindings());
    }

    public function testTheSubqueryBindingsStandWhereItsPlaceholdersAre(): void
    {
        // The conditions are written in the order they were added, so the
        // subquery's values belong between the ones on either side of it
        // rather than at the end.
        $select = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->whereIn('id', $this->paidUserIds())
            ->where('name', '!=', 'dave');

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `status` = ? AND `id` IN'
            . ' (SELECT `user_id` FROM `orders` WHERE `paid` = ?) AND `name` != ?',
            $select->toSql(),
        );
        $this->assertSame(['active', 1, 'dave'], $select->toBindings());
    }

    public function testTheSubqueryIsReadWhenTheOuterStatementIsCompiled(): void
    {
        // The builder is held rather than compiled on the spot, so a condition
        // added to it afterwards is part of the statement that runs.
        $sub    = $this->paidUserIds();
        $select = $this->connection->select('id')->from('users')->whereIn('id', $sub);

        $sub->where('user_id', '>', 1);

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `id` IN'
            . ' (SELECT `user_id` FROM `orders` WHERE `paid` = ? AND `user_id` > ?)',
            $select->toSql(),
        );
        $this->assertSame([1, 1], $select->toBindings());
    }

    public function testWhereInReadsTheRowsTheSubqueryMatches(): void
    {
        $ids = $this->connection->select('id')->from('users')->whereIn('id', $this->paidUserIds())->pluck('id');

        $this->assertSame([1], $ids);
    }

    public function testWhereNotInReadsTheRowsTheSubqueryLeavesOut(): void
    {
        $ids = $this->connection->select('id')->from('users')->whereNotIn('id', $this->paidUserIds())->pluck('id');

        $this->assertSame([2, 3], $ids);
    }

    public function testAQualifiedColumnIsQuotedOnBothSides(): void
    {
        $select = $this->connection->select('users.id')
            ->from('users')
            ->whereIn('users.id', $this->connection->select('orders.user_id')->from('orders'));

        $this->assertSame(
            'SELECT `users`.`id` FROM `users` WHERE `users`.`id` IN (SELECT `orders`.`user_id` FROM `orders`)',
            $select->toSql(),
        );
    }

    public function testAnExpressionMayStandForTheColumnBeingTested(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->whereIn(Expression::of('`id` + ?', [0]), $this->paidUserIds());

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `id` + ? IN (SELECT `user_id` FROM `orders` WHERE `paid` = ?)',
            $select->toSql(),
        );
        $this->assertSame([0, 1], $select->toBindings());
    }

    public function testASubqueryThatNamesNoTableIsRefusedWhenTheOuterStatementCompiles(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->whereIn('id', $this->connection->select('user_id'));

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertStringContainsString('call from() before compiling', $thrown->getMessage());
    }

    public function testASubqueryWithAnOpenGroupIsRefusedWhenTheOuterStatementCompiles(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->whereIn('id', $this->connection->select('user_id')->from('orders')->whereOpen());

        $this->assertThrows(LogicException::class, static fn () => $select->toSql());
    }

    public function testAnEmptyArrayIsStillRefused(): void
    {
        // The set written out by hand keeps its own guard; a subquery cannot
        // carry one because what it matches is not known until it runs.
        $select = $this->connection->select('id')->from('users');

        $this->assertThrows(InvalidArgumentException::class, static fn () => $select->whereIn('id', []));
    }

    public function testUpdateTakesASubqueryInItsWhereClause(): void
    {
        // whereIn() lives on the shared base, so the write builders reach the
        // same machinery.
        $update = $this->connection->update('users')
            ->set(['status' => 'blocked'])
            ->whereIn('id', $this->paidUserIds());

        $this->assertSame(
            'UPDATE `users` SET `status` = ? WHERE `id` IN (SELECT `user_id` FROM `orders` WHERE `paid` = ?)',
            $update->toSql(),
        );
        $this->assertSame(['blocked', 1], $update->toBindings());
    }

    public function testDeleteTakesASubqueryInItsWhereClause(): void
    {
        $delete = $this->connection->delete('users')->whereNotIn('id', $this->paidUserIds());

        $this->assertSame(
            'DELETE FROM `users` WHERE `id` NOT IN (SELECT `user_id` FROM `orders` WHERE `paid` = ?)',
            $delete->toSql(),
        );
        $this->assertSame([1], $delete->toBindings());
    }
}
