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
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;

final class SelectCommonTableTest extends TestCase
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
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, manager_id INTEGER NULL)');
        $sqlite->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT NOT NULL)');
        $sqlite->exec("INSERT INTO users (id, name, manager_id) VALUES (1, 'alice', NULL),"
            . " (2, 'bob', 1), (3, 'carol', 2)");
        $sqlite->exec("INSERT INTO posts (id, user_id, title) VALUES (1, 1, 'a'), (2, 3, 'b')");

        $this->connection = new Connection($sqlite, 'common_table_test');
    }

    private function named(string $name = 'recent'): Select
    {
        return $this->connection->select('id')
            ->from('users')
            ->with($name, $this->connection->select('id')->from('posts')->where('user_id', '>', 1));
    }

    public function testTheNamedStatementLeadsTheOneReadingIt(): void
    {
        $select = $this->connection->select('user_id')
            ->from('recent')
            ->with('recent', $this->connection->select('user_id')->from('posts')->where('id', '>', 1));

        $this->assertSame(
            'WITH `recent` AS (SELECT `user_id` FROM `posts` WHERE `id` > ?) SELECT `user_id` FROM `recent`',
            $select->toSql(),
        );
        $this->assertSame([1], $select->toBindings());
        $this->assertSame([3], $select->pluck('user_id'));
    }

    public function testTheColumnsTheRowsAreReadUnderAreNamedWhereTheStatementIs(): void
    {
        $select = $this->connection->select('n')
            ->from('counted')
            ->with('counted', $this->connection->select(Expression::of('COUNT(*)'))->from('posts'), ['n']);

        $this->assertSame(
            'WITH `counted` (`n`) AS (SELECT COUNT(*) FROM `posts`) SELECT `n` FROM `counted`',
            $select->toSql(),
        );
        $this->assertSame([2], $select->pluck('n'));
    }

    public function testSeveralStatementsAreWrittenInTheOrderTheyWereNamed(): void
    {
        $select = $this->connection->select('id')
            ->from('second')
            ->with('first', $this->connection->select('id')->from('users')->where('id', '>', 1))
            ->with('second', $this->connection->select('id')->from('first')->where('id', '<', 3));

        $this->assertSame(
            'WITH `first` AS (SELECT `id` FROM `users` WHERE `id` > ?),'
            . ' `second` AS (SELECT `id` FROM `first` WHERE `id` < ?)'
            . ' SELECT `id` FROM `second`',
            $select->toSql(),
        );
        $this->assertSame([1, 3], $select->toBindings());
    }

    public function testBindingsOfTheNamedStatementsComeBeforeTheRest(): void
    {
        $select = $this->named()->where('id', '<', 99);

        $this->assertSame([1, 99], $select->toBindings());
    }

    public function testRecursiveIsWrittenOnceForTheWholeClause(): void
    {
        $seed = $this->connection->select('id', Expression::of('0'))->from('users')->whereNull('manager_id');
        $step = $this->connection->select('users.id', Expression::of('`tree`.`depth` + 1'))
            ->from('users')
            ->join('tree')
            ->on('users.manager_id', '=', 'tree.id');

        $select = $this->connection->select('id', 'depth')
            ->from('tree')
            ->with('roots', $this->connection->select('id')->from('users')->whereNull('manager_id'))
            ->withRecursive('tree', $seed->unionAll($step), ['id', 'depth']);

        $this->assertSame(
            'WITH RECURSIVE `roots` AS (SELECT `id` FROM `users` WHERE `manager_id` IS NULL),'
            . ' `tree` (`id`, `depth`) AS ((SELECT `id`, 0 FROM `users` WHERE `manager_id` IS NULL)'
            . ' UNION ALL (SELECT `users`.`id`, `tree`.`depth` + 1 FROM `users`'
            . ' JOIN `tree` ON `users`.`manager_id` = `tree`.`id`))'
            . ' SELECT `id`, `depth` FROM `tree`',
            $select->toSql(),
        );
    }

    public function testTheNameCarriesTheTablePrefixSoBothEndsMeet(): void
    {
        // A statement reads the name where a table name stands, and that
        // reference is prefixed like any other table. Writing the definition
        // without the prefix would leave the two naming different things.
        $this->connection->setGrammar(new Grammar('app_'));

        $select = $this->connection->select('id')
            ->from('recent')
            ->with('recent', $this->connection->select('user_id')->from('posts'));

        $this->assertSame(
            'WITH `app_recent` AS (SELECT `user_id` FROM `app_posts`) SELECT `id` FROM `app_recent`',
            $select->toSql(),
        );
    }

    public function testTheClauseLeadsAUnionRatherThanGoingInsideItsParentheses(): void
    {
        // MariaDB refuses a WITH clause inside the parentheses of a combined
        // statement, and a name declared there would be out of reach of the
        // statements that follow it.
        $select = $this->connection->select('id')
            ->from('recent')
            ->where('id', '<', 10)
            ->with('recent', $this->connection->select('user_id')->from('posts')->where('id', '>', 0))
            ->union($this->connection->select('id')->from('users')->where('id', '=', 2));

        $this->assertSame(
            'WITH `recent` AS (SELECT `user_id` FROM `posts` WHERE `id` > ?)'
            . ' (SELECT `id` FROM `recent` WHERE `id` < ?) UNION (SELECT `id` FROM `users` WHERE `id` = ?)',
            $select->toSql(),
        );
        // The clause leads the statement, so its values are bound first. The
        // first statement carries one of its own, which is what tells the two
        // apart from each other.
        $this->assertSame([0, 10, 2], $select->toBindings());
    }

    public function testAClauseGivenToAnAddedStatementAfterwardsIsRefusedWhenTheUnionIsWritten(): void
    {
        // The statement is held rather than copied, so a clause given to it
        // after it was added would otherwise reach the SQL inside the
        // parentheses, where MariaDB answers 1064.
        $added  = $this->connection->select('id')->from('users');
        $select = $this->connection->select('user_id')->from('posts')->union($added);

        $added->with('own', $this->connection->select('id')->from('users'));

        $error = $this->assertThrows(
            LogicException::class,
            static fn (): string => $select->toSql(),
        );

        $this->assertSame(
            'A statement added to a union carries no WITH clause of its own, and one was given to it'
            . ' after it was added. Name what it declares on the statement the union is added to.',
            $error->getMessage(),
        );
    }

    public function testAStatementAddedToAUnionCarriesNoClauseOfItsOwn(): void
    {
        $added = $this->connection->select('id')
            ->from('own')
            ->with('own', $this->connection->select('id')->from('users'));

        $select = $this->connection->select('id')->from('posts');

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Select => $select->union($added),
        );

        $this->assertSame(
            'A statement added to a union carries no WITH clause of its own.'
            . ' Name what it declares on the statement the union is added to.',
            $error->getMessage(),
        );
    }

    public function testANamedStatementCarriesNoClauseOfItsOwn(): void
    {
        $nested = $this->connection->select('id')
            ->from('inner_name')
            ->with('inner_name', $this->connection->select('id')->from('users'));

        $select = $this->connection->select('id')->from('outer_name');

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Select => $select->with('outer_name', $nested),
        );

        $this->assertSame(
            'A statement named in a WITH clause carries no WITH clause of its own, and the one named'
            . ' outer_name does. Name what it declares in this clause instead, where the rest of the'
            . ' statement can read it too.',
            $error->getMessage(),
        );
    }

    public function testTheSameNameIsNotGivenTwice(): void
    {
        $select = $this->named();

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $select->with('recent', $this->connection->select('id')->from('users')),
        );

        $this->assertSame(
            'A WITH clause names each statement once, and recent is named twice.',
            $error->getMessage(),
        );
    }

    public function testAQualifiedNameIsRefused(): void
    {
        $select = $this->connection->select('id')->from('users');

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $select->with('reporting.recent', $this->connection->select('id')->from('posts')),
        );

        $this->assertSame(
            'A name given to a statement in a WITH clause stands on its own and is not qualified,'
            . ' got reporting.recent.',
            $error->getMessage(),
        );
    }

    public function testAQualifiedColumnNameIsRefused(): void
    {
        $select = $this->connection->select('id')->from('users');

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $select->with(
                'recent',
                $this->connection->select('id')->from('posts'),
                ['posts.id'],
            ),
        );

        $this->assertSame(
            'A column of a statement in a WITH clause stands on its own and is not qualified,'
            . ' got posts.id at index 0.',
            $error->getMessage(),
        );
    }

    public function testAColumnNamedWithSomethingOtherThanAStringIsRefused(): void
    {
        $select = $this->connection->select('id')->from('users');

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $select->with('recent', $this->connection->select('id')->from('posts'), [42]),
        );

        $this->assertSame(
            'A column of a statement in a WITH clause is named with a string, got int at index 0.',
            $error->getMessage(),
        );
    }

    public function testTheNamedStatementIsReadWhenTheOuterOneIsCompiled(): void
    {
        // The body is held rather than written at the time it is named, which
        // is what lets a condition be added to it afterwards, as with any other
        // statement standing inside another.
        $body   = $this->connection->select('user_id')->from('posts');
        $select = $this->connection->select('id')->from('recent')->with('recent', $body);

        $body->where('id', '>', 1);

        $this->assertSame(
            'WITH `recent` AS (SELECT `user_id` FROM `posts` WHERE `id` > ?) SELECT `id` FROM `recent`',
            $select->toSql(),
        );
    }
}
