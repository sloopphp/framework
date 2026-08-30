<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\BuilderWhere;
use Sloop\Database\Query\Delete;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Tests\Support\ThrowsAssertions;

final class DeleteTest extends TestCase
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
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL)');

        $this->connection = new Connection($sqlite, 'delete_test');
    }

    private function delete(string $table = 'users'): Delete
    {
        return $this->connection->delete($table);
    }

    private function seedUsers(): void
    {
        $this->connection->statement(
            "INSERT INTO users (id, name, status) VALUES (1, 'alice', 'active'), (2, 'bob', 'blocked'), (3, 'carol', 'blocked')",
        );
    }

    /**
     * @return list<int>
     */
    private function remainingIds(): array
    {
        $ids = [];

        foreach ($this->connection->query('SELECT id FROM users ORDER BY id') as $row) {
            $id = $row['id'];
            self::assertIsInt($id);
            $ids[] = $id;
        }

        return $ids;
    }

    public function testWithoutConditionsRemovesEveryRow(): void
    {
        $this->assertSame('DELETE FROM `users`', $this->delete()->toSql());
        $this->assertSame([], $this->delete()->toBindings());
    }

    public function testConditionsBecomeAWhereClause(): void
    {
        $delete = $this->delete()->where('status', 'blocked')->where('id', '>', 1);

        $this->assertSame('DELETE FROM `users` WHERE `status` = ? AND `id` > ?', $delete->toSql());
        $this->assertSame(['blocked', 1], $delete->toBindings());
    }

    public function testOrderAndLimitPickWhichRowsGo(): void
    {
        $delete = $this->delete()->where('status', 'blocked')->orderBy('id', 'DESC')->limit(1);

        $this->assertSame(
            'DELETE FROM `users` WHERE `status` = ? ORDER BY `id` DESC LIMIT 1',
            $delete->toSql(),
        );
    }

    public function testAnExpressionKeepsItsBindingsInPlaceholderOrder(): void
    {
        $delete = $this->delete()
            ->where('status', 'blocked')
            ->orderBy(Expression::of('ABS(id - ?)', [5]));

        $this->assertSame('DELETE FROM `users` WHERE `status` = ? ORDER BY ABS(id - ?) ASC', $delete->toSql());
        $this->assertSame(['blocked', 5], $delete->toBindings());
    }

    public function testTheTablePrefixOfTheGrammarIsApplied(): void
    {
        $this->connection->setGrammar(new Grammar('wp_'));

        $this->assertSame('DELETE FROM `wp_users`', $this->connection->delete('users')->toSql());
    }

    public function testAnUnclosedGroupOfConditionsIsRefused(): void
    {
        $delete = $this->delete()->whereOpen()->where('status', 'blocked');

        $thrown = $this->assertThrows(LogicException::class, static fn (): string => $delete->toSql());

        $this->assertSame(
            'A group of conditions was opened and not closed; call whereClose() 1 more time.',
            $thrown->getMessage(),
        );
    }

    public function testAGroupLeftOpenByACallbackIsRefused(): void
    {
        $delete = $this->delete()->where(static function (BuilderWhere $builder): void {
            $builder->whereOpen()->where('status', 'blocked');
        });

        $thrown = $this->assertThrows(LogicException::class, static fn (): string => $delete->toSql());

        $this->assertStringContainsString(
            'A callback opened a group of conditions and returned without closing it.',
            $thrown->getMessage(),
        );
    }

    public function testAnOffsetIsRefusedBecauseTheStatementHasNothingToSkipPast(): void
    {
        $delete = $this->delete()->where('status', 'blocked')->limit(1)->offset(1);

        $thrown = $this->assertThrows(LogicException::class, static fn (): int => $delete->execute());

        $this->assertSame(
            'A DELETE takes no offset: MySQL orders the rows and removes the first LIMIT of them,'
                . ' with nothing to skip past. Narrow the statement with where() instead.',
            $thrown->getMessage(),
        );
    }

    public function testExecuteRemovesTheMatchingRowsAndReportsHowMany(): void
    {
        $this->seedUsers();

        $removed = $this->delete()->where('status', 'blocked')->execute();

        $this->assertSame(2, $removed);
        $this->assertSame([1], $this->remainingIds());
    }

    public function testExecuteReportsZeroWhenNothingMatched(): void
    {
        $this->seedUsers();

        $removed = $this->delete()->where('status', 'gone')->execute();

        $this->assertSame(0, $removed);
        $this->assertSame([1, 2, 3], $this->remainingIds());
    }
}
