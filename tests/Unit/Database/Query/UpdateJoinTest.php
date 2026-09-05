<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Update;
use Sloop\Tests\Support\ThrowsAssertions;

final class UpdateJoinTest extends TestCase
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

        $this->connection = new Connection($sqlite, 'update_join_test');
    }

    private function update(): Update
    {
        return $this->connection->update('users');
    }

    public function testJoinWritesItselfBetweenTheTableAndTheAssignments(): void
    {
        $update = $this->update()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'vip'])
            ->where('posts.views', '>', 100);

        $this->assertSame(
            'UPDATE `users` JOIN `posts` ON `posts`.`user_id` = `users`.`id`'
                . ' SET `users`.`status` = ? WHERE `posts`.`views` > ?',
            $update->toSql(),
        );
    }

    public function testLeftJoinAndRightJoinWriteTheirOwnKeyword(): void
    {
        $left  = $this->update()->leftJoin('posts')->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'vip']);
        $right = $this->update()->rightJoin('posts')->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'vip']);

        $this->assertStringContainsString('LEFT JOIN `posts` ON', $left->toSql());
        $this->assertStringContainsString('RIGHT JOIN `posts` ON', $right->toSql());
    }

    public function testOnTakesTwoArgumentsAsAnEqualityComparison(): void
    {
        $update = $this->update()
            ->join('posts')
            ->on('posts.user_id', 'users.id')
            ->set(['users.status' => 'vip']);

        $this->assertStringContainsString('ON `posts`.`user_id` = `users`.`id`', $update->toSql());
    }

    public function testSeveralJoinsAreWrittenInTheOrderTheyWereAdded(): void
    {
        $update = $this->update()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->leftJoin('comments')
            ->on('comments.post_id', '=', 'posts.id')
            ->set(['users.status' => 'vip']);

        $this->assertStringContainsString(
            'JOIN `posts` ON `posts`.`user_id` = `users`.`id`'
                . ' LEFT JOIN `comments` ON `comments`.`post_id` = `posts`.`id` SET',
            $update->toSql(),
        );
    }

    public function testAGroupOfOnConditionsIsParenthesised(): void
    {
        $update = $this->update()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->andOnOpen()
            ->on('posts.status', '=', Expression::of('?', ['live']))
            ->orOn('posts.pinned', '=', Expression::of('?', [1]))
            ->onClose()
            ->set(['users.status' => 'vip']);

        $this->assertStringContainsString(
            'ON `posts`.`user_id` = `users`.`id`'
                . ' AND (`posts`.`status` = ? OR `posts`.`pinned` = ?)',
            $update->toSql(),
        );
    }

    public function testOnBindingsComeBeforeTheAssignmentsAndTheConditions(): void
    {
        $update = $this->update()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->andOn('posts.status', '=', Expression::of('?', ['live']))
            ->set(['users.status' => 'vip'])
            ->where('posts.views', '>', 100);

        $this->assertSame(['live', 'vip', 100], $update->toBindings());
    }

    public function testOnWithoutAJoinIsRefused(): void
    {
        $update = $this->update();

        $thrown = $this->assertThrows(
            LogicException::class,
            static fn (): Update => $update->on('posts.user_id', '=', 'users.id'),
        );

        $this->assertSame(
            'An ON condition belongs to a join, so call join() before on().',
            $thrown->getMessage(),
        );
    }

    public function testAJoinWithoutAnOnConditionIsRefused(): void
    {
        $update = $this->update()->join('posts')->set(['users.status' => 'vip']);

        $thrown = $this->assertThrows(LogicException::class, static fn (): string => $update->toSql());

        $this->assertSame(
            'The join on `posts` carries no ON condition, so it would pair every row with every other.'
                . ' Add one with on(), or write the cross join as a raw statement.',
            $thrown->getMessage(),
        );
    }

    public function testAnOnGroupLeftOpenIsRefusedWhenTheStatementIsCompiled(): void
    {
        $update = $this->update()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->onOpen()
            ->set(['users.status' => 'vip']);

        $thrown = $this->assertThrows(LogicException::class, static fn (): string => $update->toSql());

        $this->assertSame(
            'A group of ON conditions was opened and not closed; call onClose() 1 more time.',
            $thrown->getMessage(),
        );
    }

    public function testAJoinedUpdateRefusesAnOrderBy(): void
    {
        $update = $this->update()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'vip'])
            ->orderBy('users.id');

        $thrown = $this->assertThrows(LogicException::class, static fn (): string => $update->toSql());

        $this->assertSame(
            'MySQL 8.0 refuses ORDER BY and LIMIT on an UPDATE that joins another table (error 1221),'
                . ' while MariaDB accepts them. This one is refused here so that it means the same on'
                . ' either server; narrow it with where() instead.',
            $thrown->getMessage(),
        );
    }

    public function testAJoinedUpdateRefusesALimit(): void
    {
        $update = $this->update()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'vip'])
            ->limit(10);

        $this->assertThrows(LogicException::class, static fn (): string => $update->toSql());
    }

    public function testAnUpdateWithoutAJoinStillTakesAnOrderByAndALimit(): void
    {
        $update = $this->update()
            ->set(['users.status' => 'vip'])
            ->orderBy('users.id')
            ->limit(10);

        $this->assertSame(
            'UPDATE `users` SET `users`.`status` = ? ORDER BY `users`.`id` ASC LIMIT 10',
            $update->toSql(),
        );
    }

    public function testStrictModeStillWantsAWhereClauseOnAJoinedUpdate(): void
    {
        $this->connection->setStrictMode(true);
        $update = $this->connection->update('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'vip']);

        $thrown = $this->assertThrows(LogicException::class, static fn (): int => $update->execute());

        $this->assertStringContainsString('carries no WHERE clause', $thrown->getMessage());
    }

    public function testTheTablePrefixReachesTheJoinedTableAndItsColumns(): void
    {
        $this->connection->setGrammar(new Grammar('wp_'));

        $update = $this->connection->update('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->set(['users.status' => 'vip']);

        $this->assertSame(
            'UPDATE `wp_users` JOIN `wp_posts` ON `wp_posts`.`user_id` = `wp_users`.`id`'
                . ' SET `wp_users`.`status` = ?',
            $update->toSql(),
        );
    }
}
