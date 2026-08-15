<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Select;

final class SelectTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL)');

        $this->connection = new Connection($sqlite, 'select_test');
    }

    private function seedUsers(): void
    {
        $this->connection->statement(
            'INSERT INTO users (id, name, status) VALUES (1, \'alice\', \'active\'),'
                . ' (2, \'bob\', \'blocked\'), (3, \'carol\', \'active\')',
        );
    }

    public function testSelectWithoutColumnsReadsEveryColumn(): void
    {
        $select = $this->connection->select()->from('users');

        $this->assertSame('SELECT * FROM `users`', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testSelectWritesTheColumnsItWasStartedWith(): void
    {
        $select = $this->connection->select('id', 'name')->from('users');

        $this->assertSame('SELECT `id`, `name` FROM `users`', $select->toSql());
    }

    public function testSelectAcceptsAnExpressionAsAColumn(): void
    {
        $select = $this->connection->select('id', Expression::of('COUNT(*)'))->from('users');

        $this->assertSame('SELECT `id`, COUNT(*) FROM `users`', $select->toSql());
    }

    public function testCompilingWithoutATableIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('call from()');

        $this->connection->select('id')->toSql();
    }

    public function testTwoArgumentWhereComparesForEquality(): void
    {
        $select = $this->connection->select()->from('users')->where('status', 'active');

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
        $this->assertSame(['active'], $select->toBindings());
    }

    public function testTwoArgumentWhereTreatsAnOperatorLikeValueAsAValue(): void
    {
        $select = $this->connection->select()->from('users')->where('name', '=');

        $this->assertSame('SELECT * FROM `users` WHERE `name` = ?', $select->toSql());
        $this->assertSame(['='], $select->toBindings());
    }

    public function testThreeArgumentWhereUsesTheGivenOperator(): void
    {
        $select = $this->connection->select()->from('users')->where('id', '>=', 10);

        $this->assertSame('SELECT * FROM `users` WHERE `id` >= ?', $select->toSql());
        $this->assertSame([10], $select->toBindings());
    }

    public function testWhereAcceptsAnExpressionAsTheValue(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('created_at', '<', Expression::of('NOW()'));

        $this->assertSame('SELECT * FROM `users` WHERE `created_at` < NOW()', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testConditionsAreJoinedInTheOrderTheyWereAdded(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->andWhere('score', '>', 10)
            ->orWhere('name', 'root');

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND `score` > ? OR `name` = ?',
            $select->toSql(),
        );
        $this->assertSame(['active', 10, 'root'], $select->toBindings());
    }

    public function testTheFirstConditionCarriesNoConjunction(): void
    {
        $select = $this->connection->select()->from('users')->orWhere('status', 'active');

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testComparingAgainstNullIsRejectedInTheTwoArgumentForm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('never true');

        $this->connection->select()->from('users')->where('deleted_at', null);
    }

    public function testComparingAgainstNullIsRejectedInTheThreeArgumentForm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('never true');

        $this->connection->select()->from('users')->where('deleted_at', '=', null);
    }

    public function testAnOperatorThatIsNotAStringIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A comparison operator must be a string, got int.');

        $this->connection->select()->from('users')->where('id', 10, 20);
    }

    public function testAnUnsupportedOperatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported comparison operator');

        $this->connection->select()->from('users')->where('id', 'IS', 10);
    }

    public function testOrderBySortsAscendingByDefault(): void
    {
        $select = $this->connection->select()->from('users')->orderBy('name');

        $this->assertSame('SELECT * FROM `users` ORDER BY `name` ASC', $select->toSql());
    }

    /**
     * @param string $direction Direction as written by the caller
     * @param string $expected  Keyword expected in the SQL
     */
    #[DataProvider('sortDirections')]
    public function testOrderByReadsTheDirectionKeywordInAnyCase(string $direction, string $expected): void
    {
        $select = $this->connection->select()->from('users')->orderBy('name', $direction);

        $this->assertSame('SELECT * FROM `users` ORDER BY `name` ' . $expected, $select->toSql());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sortDirections(): array
    {
        return [
            'uppercase descending' => ['DESC', 'DESC'],
            'lowercase descending' => ['desc', 'DESC'],
            'lowercase ascending'  => ['asc', 'ASC'],
            'mixed case'           => ['DeSc', 'DESC'],
        ];
    }

    public function testAnUnknownSortDirectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A sort direction is ASC or DESC, got "sideways".');

        $this->connection->select()->from('users')->orderBy('name', 'sideways');
    }

    public function testOrderByAcceptsAnExpressionAsTheSortKey(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->orderBy(Expression::field('status', ['active', 'pending']));

        $this->assertSame(
            'SELECT * FROM `users` ORDER BY FIELD(`status`, ?, ?) ASC',
            $select->toSql(),
        );
        $this->assertSame(['active', 'pending'], $select->toBindings());
    }

    public function testSortTermsKeepTheOrderTheyWereAddedIn(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->orderBy('status')
            ->orderBy('id', 'DESC');

        $this->assertSame('SELECT * FROM `users` ORDER BY `status` ASC, `id` DESC', $select->toSql());
    }

    public function testLimitAndOffsetBoundTheRows(): void
    {
        $select = $this->connection->select()->from('users')->limit(5)->offset(10);

        $this->assertSame('SELECT * FROM `users` LIMIT 5 OFFSET 10', $select->toSql());
    }

    public function testAZeroLimitAsksForNoRowsRatherThanBeingRejected(): void
    {
        $select = $this->connection->select()->from('users')->limit(0);

        $this->assertSame('SELECT * FROM `users` LIMIT 0', $select->toSql());
    }

    public function testAZeroOffsetSkipsNothingRatherThanBeingRejected(): void
    {
        $select = $this->connection->select()->from('users')->limit(5)->offset(0);

        $this->assertSame('SELECT * FROM `users` LIMIT 5 OFFSET 0', $select->toSql());
    }

    public function testANegativeLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Limit must not be negative, got -1.');

        $this->connection->select()->from('users')->limit(-1);
    }

    public function testANegativeOffsetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Offset must not be negative, got -1.');

        $this->connection->select()->from('users')->offset(-1);
    }

    public function testAnOffsetWithoutALimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('offset needs a limit');

        $this->connection->select()->from('users')->offset(10)->toSql();
    }

    public function testEveryChainingMethodReturnsTheSameBuilder(): void
    {
        $select = $this->connection->select()->from('users');

        $this->assertSame($select, $select->from('users'));
        $this->assertSame($select, $select->where('status', 'active'));
        $this->assertSame($select, $select->andWhere('status', 'active'));
        $this->assertSame($select, $select->orWhere('status', 'active'));
        $this->assertSame($select, $select->orderBy('id'));
        $this->assertSame($select, $select->limit(1));
        $this->assertSame($select, $select->offset(1));
    }

    public function testTheLastTableNamedWins(): void
    {
        $select = $this->connection->select()->from('users')->from('posts');

        $this->assertSame('SELECT * FROM `posts`', $select->toSql());
    }

    public function testTheGrammarComesFromTheConnection(): void
    {
        $this->connection->setGrammar(new Grammar('app_'));

        $select = $this->connection->select('users.id')->from('users')->where('users.status', 'active');

        $this->assertSame(
            'SELECT `app_users`.`id` FROM `app_users` WHERE `app_users`.`status` = ?',
            $select->toSql(),
        );
    }

    public function testConnectionSelectReturnsABuilder(): void
    {
        $this->assertInstanceOf(Select::class, $this->connection->select());
    }

    public function testExecuteReturnsTheRowsTheStatementRead(): void
    {
        $this->seedUsers();

        $rows = $this->connection->select('id', 'name')
            ->from('users')
            ->where('status', 'active')
            ->orderBy('id', 'DESC')
            ->execute();

        $this->assertSame(
            [
                ['id' => 3, 'name' => 'carol'],
                ['id' => 1, 'name' => 'alice'],
            ],
            $rows->asArray(),
        );
    }

    public function testExecuteAppliesTheRowWindow(): void
    {
        $this->seedUsers();

        $rows = $this->connection->select('name')
            ->from('users')
            ->orderBy('id')
            ->limit(1)
            ->offset(1)
            ->execute();

        $this->assertSame([['name' => 'bob']], $rows->asArray());
    }

    public function testRawSqlWritesTheValuesInPlaceOfThePlaceholders(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('name', "O'Brien")
            ->andWhere('score', '>', 10);

        // The number is quoted because that is how the driver receives it:
        // PDOStatement::execute() binds every value of the array as a string.
        $this->assertSame(
            'SELECT * FROM `users` WHERE `name` = \'O\'\'Brien\' AND `score` > \'10\'',
            $select->toRawSql(),
        );
    }

    public function testRawSqlLeavesAPlaceholderWithNoValueAlone(): void
    {
        // The `?` inside the expression's string literal is not a placeholder,
        // so the rendering runs out of values before the SQL runs out of marks.
        $select = $this->connection->select(Expression::of("CONCAT(name, '?')"))
            ->from('users')
            ->where('status', 'active');

        $this->assertSame(
            'SELECT CONCAT(name, \'\'active\'\') FROM `users` WHERE `status` = ?',
            $select->toRawSql(),
        );
    }

    public function testRawSqlCannotTellAQuestionMarkInsideANameFromAPlaceholder(): void
    {
        // Pinned because it is a limitation rather than an accident: the value
        // lands inside the name and the one after it moves up. The statement
        // that runs is unaffected, and the text stops matching it visibly.
        $select = $this->connection->select()
            ->from('users')
            ->where('a?b', 'FIRST')
            ->andWhere('status', 'SECOND');

        $this->assertSame(
            'SELECT * FROM `users` WHERE `a\'FIRST\'b` = \'SECOND\' AND `status` = ?',
            $select->toRawSql(),
        );
    }

    public function testRawSqlOfAStatementWithoutValuesIsTheSqlItself(): void
    {
        $select = $this->connection->select()->from('users');

        $this->assertSame($select->toSql(), $select->toRawSql());
    }
}
