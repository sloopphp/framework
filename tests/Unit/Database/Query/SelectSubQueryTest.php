<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testOrWhereInTakesAStatementWhereASetStands(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->where('name', 'alice')
            ->orWhereIn('id', $this->paidUserIds());

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `name` = ?'
            . ' OR `id` IN (SELECT `user_id` FROM `orders` WHERE `paid` = ?)',
            $select->toSql(),
        );
        $this->assertSame(['alice', 1], $select->toBindings());
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

    public function testASubqueryMayHoldOneOfItsOwn(): void
    {
        // The grammar reaches a nested statement the same way at any depth, so
        // this holds the binding order rather than the nesting itself: each
        // statement's values stand where its own placeholders are.
        $inner = $this->connection->select('user_id')->from('orders')->where('paid', 2);

        $select = $this->connection->select('id')
            ->from('users')
            ->where('status', 'active')
            ->whereIn('id', $this->paidUserIds()->whereIn('user_id', $inner))
            ->where('name', '!=', 'dave');

        $this->assertSame(
            'SELECT `id` FROM `users` WHERE `status` = ? AND `id` IN'
            . ' (SELECT `user_id` FROM `orders` WHERE `paid` = ? AND `user_id` IN'
            . ' (SELECT `user_id` FROM `orders` WHERE `paid` = ?)) AND `name` != ?',
            $select->toSql(),
        );
        $this->assertSame(['active', 1, 2, 'dave'], $select->toBindings());
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

    /**
     * @return array<string, array{Closure(Connection): mixed}>
     */
    public static function limitedSetProvider(): array
    {
        $ids = static fn (Connection $c): Select => $c->select('user_id')->from('orders');

        return [
            'whereIn()'                    => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereIn('id', $ids($c)->limit(1))->toSql()],
            'andWhereIn()'                 => [static fn (Connection $c): mixed => $c->select('id')->from('users')->andWhereIn('id', $ids($c)->limit(1))->toSql()],
            'orWhereIn()'                  => [static fn (Connection $c): mixed => $c->select('id')->from('users')->where('id', 1)->orWhereIn('id', $ids($c)->limit(1))->toSql()],
            'whereNotIn()'                 => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereNotIn('id', $ids($c)->limit(1))->toSql()],
            'andWhereNotIn()'              => [static fn (Connection $c): mixed => $c->select('id')->from('users')->andWhereNotIn('id', $ids($c)->limit(1))->toSql()],
            'orWhereNotIn()'               => [static fn (Connection $c): mixed => $c->select('id')->from('users')->where('id', 1)->orWhereNotIn('id', $ids($c)->limit(1))->toSql()],
            'whereNotInOrNull()'           => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereNotInOrNull('id', $ids($c)->limit(1))->toSql()],
            'andWhereNotInOrNull()'        => [static fn (Connection $c): mixed => $c->select('id')->from('users')->andWhereNotInOrNull('id', $ids($c)->limit(1))->toSql()],
            'orWhereNotInOrNull()'         => [static fn (Connection $c): mixed => $c->select('id')->from('users')->where('id', 1)->orWhereNotInOrNull('id', $ids($c)->limit(1))->toSql()],
            'a limit of zero'              => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereIn('id', $ids($c)->limit(0))->toSql()],
            'a limit with an offset'       => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereIn('id', $ids($c)->limit(1)->offset(1))->toSql()],
            'the combined rows'            => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereIn('id', $ids($c)->union($ids($c))->limit(1))->toSql()],
            'the first of a union'         => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereIn('id', $ids($c)->limit(1)->union($ids($c)))->toSql()],
            'a statement added to a union' => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereIn('id', $ids($c)->union($ids($c)->limit(1)))->toSql()],
            'a union inside an added one'  => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereIn('id', $ids($c)->union($ids($c)->union($ids($c)->limit(1))))->toSql()],
            'a nested set'                 => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereIn('id', $ids($c)->whereIn('user_id', $ids($c)->limit(1)))->toSql()],
            'a shortcut'                   => [static fn (Connection $c): mixed => $c->select('id')->from('users')->whereIn('id', $ids($c)->limit(1))->count()],
            'an update'                    => [static fn (Connection $c): mixed => $c->update('users')->set(['status' => 'blocked'])->whereIn('id', $ids($c)->limit(1))->toSql()],
            'a delete'                     => [static fn (Connection $c): mixed => $c->delete('users')->whereIn('id', $ids($c)->limit(1))->toSql()],
        ];
    }

    #[DataProvider('limitedSetProvider')]
    public function testASetThatCarriesALimitIsRefusedWhenTheStatementCompiles(Closure $compile): void
    {
        $e = $this->assertThrows(LogicException::class, fn (): mixed => $compile($this->connection));

        $this->assertSame(LogicException::class, $e::class);
        $this->assertStringContainsString('both servers refuse LIMIT there (1235)', $e->getMessage());
    }

    public function testTheRefusalNamesTheShapeTheServersTake(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->whereIn('id', $this->paidUserIds()->limit(1));

        $e = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertSame(
            'The set of an IN test cannot carry a limit: both servers refuse LIMIT there (1235),'
                . ' on the statement and on any statement combined with it by union(). Read the limited'
                . ' rows as a table with from($statement, $alias), and select the column from that.',
            $e->getMessage(),
        );
    }

    public function testALimitAddedAfterTheSetWasHandedOverIsRefused(): void
    {
        // The set is read when the outer statement compiles, so a limit given
        // to it afterwards would reach the server all the same.
        $ids    = $this->connection->select('user_id')->from('orders');
        $select = $this->connection->select('id')->from('users')->whereIn('id', $ids);

        $ids->limit(1);

        $this->assertThrows(LogicException::class, static fn () => $select->toSql());
    }

    /**
     * @return array<string, array{Closure(Connection): string, string}>
     */
    public static function limitOutsideTheSetProvider(): array
    {
        return [
            'a derived table read by the set' => [
                static fn (Connection $c): string => $c->select('id')->from('users')
                    ->whereIn('id', $c->select('t.user_id')->from($c->select('user_id')->from('orders')->limit(1), 't'))
                    ->toSql(),
                'SELECT `id` FROM `users` WHERE `id` IN (SELECT `t`.`user_id` FROM (SELECT `user_id` FROM `orders` LIMIT 1) AS `t`)',
            ],
            'a statement the set names with with()' => [
                static fn (Connection $c): string => $c->select('id')->from('users')
                    ->whereIn('id', $c->select('user_id')->with('t', $c->select('user_id')->from('orders')->limit(1))->from('t'))
                    ->toSql(),
                'SELECT `id` FROM `users` WHERE `id` IN (WITH `t` AS (SELECT `user_id` FROM `orders` LIMIT 1) SELECT `user_id` FROM `t`)',
            ],
            'a statement in the select list of the set' => [
                static fn (Connection $c): string => $c->select('id')->from('users')
                    ->whereIn('id', $c->select([$c->select('user_id')->from('orders')->limit(1), 'first'])->from('users'))
                    ->toSql(),
                'SELECT `id` FROM `users` WHERE `id` IN (SELECT (SELECT `user_id` FROM `orders` LIMIT 1) AS `first` FROM `users`)',
            ],
            'a statement asked whether it returns a row' => [
                static fn (Connection $c): string => $c->select('id')->from('users')
                    ->whereExists($c->select('user_id')->from('orders')->limit(1))
                    ->toSql(),
                'SELECT `id` FROM `users` WHERE EXISTS (SELECT `user_id` FROM `orders` LIMIT 1)',
            ],
            'the outer statement' => [
                static fn (Connection $c): string => $c->select('id')->from('users')
                    ->whereIn('id', $c->select('user_id')->from('orders'))
                    ->limit(1)
                    ->toSql(),
                'SELECT `id` FROM `users` WHERE `id` IN (SELECT `user_id` FROM `orders`) LIMIT 1',
            ],
        ];
    }

    #[DataProvider('limitOutsideTheSetProvider')]
    public function testALimitTheServersTakeIsLeftAlone(Closure $compile, string $sql): void
    {
        // Both servers take a LIMIT a level below the set, or on a statement
        // that is not standing as one; the first case is the rewrite the
        // refusal points to.
        $this->assertSame($sql, $compile($this->connection));
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
