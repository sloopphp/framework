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

final class SelectColumnAliasTest extends TestCase
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
        $sqlite->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, amount INTEGER NOT NULL)');

        $this->connection = new Connection($sqlite, 'column_alias_test');
    }

    private function orderCount(): Select
    {
        return $this->connection->select(Expression::of('COUNT(*)'))->from('orders');
    }

    public function testANameIsWrittenAfterTheColumnItRenames(): void
    {
        $select = $this->connection->select('id', ['name', 'label'])->from('users');

        $this->assertSame('SELECT `id`, `name` AS `label` FROM `users`', $select->toSql());
    }

    public function testAQualifiedColumnKeepsItsQualifierWhenRenamed(): void
    {
        $select = $this->connection->select(['users.name', 'label'])->from('users');

        $this->assertSame('SELECT `users`.`name` AS `label` FROM `users`', $select->toSql());
    }

    public function testAnExpressionCanCarryTheNameInsteadOfWritingItItself(): void
    {
        $select = $this->connection->select([Expression::of('COUNT(*)'), 'total'])->from('users');

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `users`', $select->toSql());
    }

    public function testTheValuesOfANamedExpressionStandBeforeTheOnesOfTheConditions(): void
    {
        // The select list is written before WHERE, so an Expression carrying
        // values inside a pair binds first. Without this the pair could drop
        // its values and every other test would still pass, because the only
        // other Expression written as a pair carries none.
        $select = $this->connection->select([Expression::of('IF(`status` = ?, 1, 0)', ['active']), 'flag'])
            ->from('users')
            ->where('id', '>', 5);

        $this->assertSame(
            'SELECT IF(`status` = ?, 1, 0) AS `flag` FROM `users` WHERE `id` > ?',
            $select->toSql(),
        );
        $this->assertSame(['active', 5], $select->toBindings());
    }

    public function testAStatementStandsWhereAColumnWould(): void
    {
        $select = $this->connection->select('id', [$this->orderCount(), 'order_count'])->from('users');

        $this->assertSame(
            'SELECT `id`, (SELECT COUNT(*) FROM `orders`) AS `order_count` FROM `users`',
            $select->toSql(),
        );
    }

    public function testAStatementInTheSelectListReadsTheRowOfTheOneAroundIt(): void
    {
        // The correlation is written as an Expression, because the name it
        // reaches for belongs to the statement outside this one and so cannot
        // be resolved against the tables this one names.
        $perUser = $this->connection->select(Expression::of('COUNT(*)'))
            ->from('orders')
            ->where('orders.user_id', Expression::of('users.id'));

        $select = $this->connection->select('id', [$perUser, 'order_count'])->from('users');

        $this->assertSame(
            'SELECT `id`, (SELECT COUNT(*) FROM `orders` WHERE `orders`.`user_id` = users.id) AS `order_count`'
            . ' FROM `users`',
            $select->toSql(),
        );
    }

    public function testTheStatementIsReadWhenTheOneAroundItIsCompiled(): void
    {
        $count  = $this->orderCount();
        $select = $this->connection->select([$count, 'order_count'])->from('users');

        $count->where('amount', '>', 100);

        $this->assertSame(
            'SELECT (SELECT COUNT(*) FROM `orders` WHERE `amount` > ?) AS `order_count` FROM `users`',
            $select->toSql(),
        );
        $this->assertSame([100], $select->toBindings());
    }

    public function testTheBindingsOfTheSelectListStandBeforeTheOnesOfEveryOtherClause(): void
    {
        // The placeholders are read left to right, so the values of a statement
        // in the select list come before those of one read as a table, and both
        // before the ones of the conditions.
        $counted = $this->orderCount()->where('amount', '>', 10);
        $active  = $this->connection->select('id')->from('users')->where('status', 'active');

        $select = $this->connection->select([$counted, 'order_count'])
            ->from($active, 'sub')
            ->where('id', '>', 5);

        $this->assertSame([10, 'active', 5], $select->toBindings());
    }

    public function testANameGetsNoTablePrefix(): void
    {
        // A prefix belongs on a name the server resolves against the schema.
        // This one is only ever a key in the rows that come back, and the
        // caller reads them by the name it wrote.
        $this->connection->setGrammar(new Grammar('app_'));

        $select = $this->connection->select(['name', 'label'])->from('users');

        $this->assertSame('SELECT `name` AS `label` FROM `app_users`', $select->toSql());
    }

    public function testANameStandsWhereTheStatementSortsAndGroups(): void
    {
        // GROUP BY and ORDER BY leave an unqualified name alone, which is the
        // same spelling the select list introduced.
        $this->connection->setGrammar(new Grammar('app_'));

        $select = $this->connection->select(['status', 'state'], [Expression::of('COUNT(*)'), 'total'])
            ->from('users')
            ->groupBy('state')
            ->orderBy('total');

        $this->assertSame(
            'SELECT `status` AS `state`, COUNT(*) AS `total` FROM `app_users` GROUP BY `state` ORDER BY `total` ASC',
            $select->toSql(),
        );
    }

    public function testAStatementIsOnlySelectableUnderAName(): void
    {
        $count = $this->orderCount();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $this->connection->select($count),
        );

        $this->assertSame(
            'A statement in the select list needs a name, because the server would return it under its own text;'
            . ' write it as [$statement, $name].',
            $thrown->getMessage(),
        );
    }

    public function testAPairOfOneElementIsRefused(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $this->connection->select(['name']),
        );

        $this->assertSame(
            'A named column is written as [$column, $name], so it has exactly two elements, got 1.',
            $thrown->getMessage(),
        );
    }

    public function testAPairOfThreeElementsIsRefused(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $this->connection->select(['name', 'label', 'extra']),
        );

        $this->assertSame(
            'A named column is written as [$column, $name], so it has exactly two elements, got 3.',
            $thrown->getMessage(),
        );
    }

    public function testAKeyedPairIsRefused(): void
    {
        // Counting the elements would call this one well formed, so the keys
        // are reported instead of the count.
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $this->connection->select(['column' => 'name', 'as' => 'label']),
        );

        $this->assertSame(
            'A named column is written as [$column, $name], so the column comes first and neither is keyed,'
            . ' got column, as as keys.',
            $thrown->getMessage(),
        );
    }

    public function testAPairGivenInTheOtherOrderIsRefused(): void
    {
        // The name reads as a column and the column as a name, so taking it as
        // written would compile and return the wrong thing under the wrong key.
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $this->connection->select([1 => 'name', 0 => 'label']),
        );

        $this->assertSame(
            'A named column is written as [$column, $name], so the column comes first and neither is keyed,'
            . ' got 1, 0 as keys.',
            $thrown->getMessage(),
        );
    }

    public function testANameThatIsNotAStringIsRefused(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $this->connection->select(['name', 1]),
        );

        $this->assertSame('The name a column is returned under must be a string, got int.', $thrown->getMessage());
    }

    public function testSomethingThatIsNotSelectableIsRefused(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $this->connection->select([1, 'label']),
        );

        $this->assertSame(
            'A named column selects a column name, an Expression, or a statement, got int.',
            $thrown->getMessage(),
        );
    }

    public function testAQualifiedNameCannotStandAsTheNameOfAColumn(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $this->connection->select(['name', 'users.label']),
        );

        $this->assertSame('An alias is one name, so it cannot be qualified, got users.label.', $thrown->getMessage());
    }

    public function testTheRowsComeBackUnderTheNameThatWasGiven(): void
    {
        $this->connection->statement("INSERT INTO users (id, name, status) VALUES (1, 'alice', 'active')");
        $this->connection->statement('INSERT INTO orders (id, user_id, amount) VALUES (1, 1, 100), (2, 1, 200)');

        $row = $this->connection->select('id', [$this->orderCount(), 'order_count'])
            ->from('users')
            ->first();

        $this->assertSame(['id' => 1, 'order_count' => 2], $row);
    }
}
