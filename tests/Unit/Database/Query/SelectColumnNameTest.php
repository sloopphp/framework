<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;
use TypeError;

final class SelectColumnNameTest extends TestCase
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
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, score INTEGER NOT NULL)');
        $sqlite->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, total INTEGER NOT NULL)');
        $sqlite->exec("INSERT INTO users (id, name, score) VALUES (1, 'alice', 10), (2, 'bob', 20)");
        $sqlite->exec('INSERT INTO orders (id, user_id, total) VALUES (1, 1, 30), (2, 2, 5), (3, 1, 40)');

        $this->connection = new Connection($sqlite, 'column_name_test');
    }

    public function testAComparisonAgainstAColumnNameQuotesItInsteadOfBindingAValue(): void
    {
        $select = $this->connection->select('id')
            ->from('orders')
            ->where('orders.total', '>', Expression::column('orders.id'));

        $this->assertSame(
            'SELECT `id` FROM `orders` WHERE `orders`.`total` > `orders`.`id`',
            $select->toSql(),
        );
        $this->assertSame([], $select->toBindings());
    }

    public function testTheTwoArgumentFormComparesAgainstAColumnNameForEquality(): void
    {
        $select = $this->connection->select('id')->from('orders')->where('total', Expression::column('id'));

        $this->assertSame('SELECT `id` FROM `orders` WHERE `total` = `id`', $select->toSql());
    }

    public function testTheArrayFormOfAConditionTakesAColumnNameToo(): void
    {
        // A condition written in a list is read by a check of its own rather
        // than by the signature, so what may stand on the right is settled
        // there and needs saying here.
        $select = $this->connection->select('id')
            ->from('orders')
            ->where([['total', '>', Expression::column('id')], ['user_id', Expression::column('id')]]);

        $this->assertSame(
            'SELECT `id` FROM `orders` WHERE `total` > `id` AND `user_id` = `id`',
            $select->toSql(),
        );
        $this->assertSame([], $select->toBindings());
    }

    public function testAColumnNameNamesTheOuterRowOfACorrelatedSubquery(): void
    {
        $select = $this->connection->select('name')
            ->from('users')
            ->whereExists(
                $this->connection->select('id')
                    ->from('orders')
                    ->where('orders.user_id', '=', Expression::column('users.id'))
                    ->where('orders.total', '>', 10),
            );

        $this->assertSame(
            'SELECT `name` FROM `users` WHERE EXISTS (SELECT `id` FROM `orders`'
            . ' WHERE `orders`.`user_id` = `users`.`id` AND `orders`.`total` > ?)',
            $select->toSql(),
        );
        $this->assertSame(['alice'], $select->pluck('name'));
    }

    public function testTheTablePrefixReachesBothSidesOfAComparisonWithAColumnName(): void
    {
        // What separates this from writing the same reference with
        // Expression::of(): the name is held unresolved, so the prefix rule
        // that puts app_ on the table segment applies to it the way it does to
        // any column the builder is told about.
        $this->connection->setGrammar(new Grammar('app_'));

        $select = $this->connection->select('id')
            ->from('users')
            ->whereExists(
                $this->connection->select('id')
                    ->from('orders')
                    ->where('orders.user_id', '=', Expression::column('users.id')),
            );

        $this->assertSame(
            'SELECT `id` FROM `app_users` WHERE EXISTS'
            . ' (SELECT `id` FROM `app_orders` WHERE `app_orders`.`user_id` = `app_users`.`id`)',
            $select->toSql(),
        );
    }

    public function testHavingComparesAnAggregateAgainstAColumnName(): void
    {
        $select = $this->connection->select('user_id')
            ->from('orders')
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', Expression::column('user_id'));

        $this->assertSame(
            'SELECT `user_id` FROM `orders` GROUP BY `user_id` HAVING COUNT(*) > `user_id`',
            $select->toSql(),
        );
    }

    public function testARangeTestTakesColumnNamesAsItsBounds(): void
    {
        $select = $this->connection->select('id')
            ->from('orders')
            ->whereBetween('total', Expression::column('id'), Expression::column('user_id'));

        $this->assertSame(
            'SELECT `id` FROM `orders` WHERE `total` BETWEEN `id` AND `user_id`',
            $select->toSql(),
        );
        $this->assertSame([], $select->toBindings());
    }

    public function testAnOperatorReadingAKeywordRefusesAColumnName(): void
    {
        // IS is followed by NULL, TRUE or FALSE, so a column there is a syntax
        // error rather than a comparison. Refused where the condition is built,
        // which is the line that named the column.
        $select = $this->connection->select('id')->from('orders');

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Select => $select->where('total', 'IS', Expression::column('id')),
        );

        $this->assertSame(
            'IS tests against a keyword, so null, true and false are the only right-hand sides it takes;'
            . ' got Sloop\Database\Query\ColumnName. Use = to compare against a value.',
            $error->getMessage(),
        );
    }

    public function testTheColumnBeingComparedIsNamedAsAStringRatherThanAColumnName(): void
    {
        // Only the side that would otherwise hold a value takes one: the left
        // of a comparison is read as a column already, so the type refuses the
        // second way of saying the same thing.
        $this->expectException(TypeError::class);

        // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
        $this->connection->select('id')->from('orders')->where(Expression::column('total'), 1);
    }

    public function testAColumnNameStandingForEveryColumnIsRefused(): void
    {
        $select = $this->connection->select('id')->from('orders')->where('total', Expression::column('orders.*'));

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): string => $select->toSql(),
        );

        $this->assertSame(
            '* names every column, so it only stands where a list of columns does, got orders.*.',
            $error->getMessage(),
        );
    }
}
