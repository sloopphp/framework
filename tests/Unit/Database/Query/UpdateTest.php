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
use Sloop\Database\Query\Update;
use Sloop\Tests\Support\ThrowsAssertions;

final class UpdateTest extends TestCase
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
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT, score INTEGER NOT NULL)');

        $this->connection = new Connection($sqlite, 'update_test');
    }

    private function update(string $table = 'users'): Update
    {
        return $this->connection->update($table);
    }

    private function seedUsers(): void
    {
        $this->connection->statement(
            'INSERT INTO users (id, name, status, score) VALUES'
                . " (1, 'alice', 'active', 10), (2, 'bob', 'blocked', 20), (3, 'carol', 'blocked', 30)",
        );
    }

    /**
     * @return list<array{id: int, status: string|null, score: int}>
     */
    private function users(): array
    {
        $rows = [];

        foreach ($this->connection->query('SELECT id, status, score FROM users ORDER BY id') as $row) {
            self::assertIsInt($row['id']);
            self::assertIsInt($row['score']);
            self::assertTrue($row['status'] === null || \is_string($row['status']));

            $rows[] = ['id' => $row['id'], 'status' => $row['status'], 'score' => $row['score']];
        }

        return $rows;
    }

    public function testAssignmentsBecomeASetClause(): void
    {
        $update = $this->update()->set(['status' => 'active', 'score' => 0]);

        $this->assertSame('UPDATE `users` SET `status` = ?, `score` = ?', $update->toSql());
        $this->assertSame(['active', 0], $update->toBindings());
    }

    public function testConditionsFollowTheAssignmentsInPlaceholderOrder(): void
    {
        $update = $this->update()->set(['status' => 'active'])->where('id', '>', 1);

        $this->assertSame('UPDATE `users` SET `status` = ? WHERE `id` > ?', $update->toSql());
        $this->assertSame(['active', 1], $update->toBindings());
    }

    public function testANullAssignmentIsWrittenAsABoundValue(): void
    {
        $update = $this->update()->set(['status' => null]);

        $this->assertSame('UPDATE `users` SET `status` = ?', $update->toSql());
        $this->assertSame([null], $update->toBindings());
    }

    public function testALaterAssignmentReplacesAnEarlierOneForTheSameColumn(): void
    {
        $update = $this->update()->set(['status' => 'active'])->set(['status' => 'blocked']);

        $this->assertSame('UPDATE `users` SET `status` = ?', $update->toSql());
        $this->assertSame(['blocked'], $update->toBindings());
    }

    public function testAReplacedAssignmentKeepsThePlaceOfTheFirstMention(): void
    {
        $update = $this->update()->set(['status' => 'active', 'score' => 0])->set(['status' => 'blocked']);

        $this->assertSame('UPDATE `users` SET `status` = ?, `score` = ?', $update->toSql());
        $this->assertSame(['blocked', 0], $update->toBindings());
    }

    public function testTheSameColumnNamedUnderTwoKeysStaysTwoAssignments(): void
    {
        $update = $this->update()->set(['status' => 'active'])->set(['users.status' => 'blocked']);

        $this->assertSame('UPDATE `users` SET `status` = ?, `users`.`status` = ?', $update->toSql());
        $this->assertSame(['active', 'blocked'], $update->toBindings());
    }

    public function testAnExpressionAssignmentKeepsItsBindingsInPlaceholderOrder(): void
    {
        $update = $this->update()
            ->set(['score' => Expression::of('score + ?', [5])])
            ->where('status', 'blocked');

        $this->assertSame('UPDATE `users` SET `score` = score + ? WHERE `status` = ?', $update->toSql());
        $this->assertSame([5, 'blocked'], $update->toBindings());
    }

    public function testAQualifiedColumnIsQuotedSegmentBySegment(): void
    {
        $update = $this->update()->set(['users.status' => 'vip']);

        $this->assertSame('UPDATE `users` SET `users`.`status` = ?', $update->toSql());
    }

    public function testOrderAndLimitPickWhichRowsChange(): void
    {
        $update = $this->update()->set(['status' => 'active'])->orderBy('id', 'DESC')->limit(1);

        $this->assertSame('UPDATE `users` SET `status` = ? ORDER BY `id` DESC LIMIT 1', $update->toSql());
    }

    public function testTheTablePrefixOfTheGrammarIsApplied(): void
    {
        $this->connection->setGrammar(new Grammar('wp_'));

        $this->assertSame(
            'UPDATE `wp_users` SET `status` = ?',
            $this->connection->update('users')->set(['status' => 'active'])->toSql(),
        );
    }

    public function testWithoutConditionsTheWholeTableIsAddressed(): void
    {
        $this->assertSame('UPDATE `users` SET `status` = ?', $this->update()->set(['status' => 'active'])->toSql());
    }

    public function testAStatementWithNothingToAssignIsRefused(): void
    {
        $update = $this->update()->where('id', 1);

        $thrown = $this->assertThrows(LogicException::class, static fn (): string => $update->toSql());

        $this->assertSame(
            'An UPDATE writes at least one column, and this one names none; call set() before running it.',
            $thrown->getMessage(),
        );
    }

    public function testAnEmptyArrayOfAssignmentsLeavesTheStatementWithNothingToAssign(): void
    {
        $update = $this->update()->set([]);

        $this->assertThrows(LogicException::class, static fn (): string => $update->toSql());
    }

    public function testAnUnclosedGroupOfConditionsIsRefused(): void
    {
        $update = $this->update()->set(['status' => 'active'])->whereOpen()->where('id', 1);

        $thrown = $this->assertThrows(LogicException::class, static fn (): string => $update->toSql());

        $this->assertSame(
            'A group of conditions was opened and not closed; call whereClose() 1 more time.',
            $thrown->getMessage(),
        );
    }

    public function testAnOffsetIsRefusedBecauseTheStatementHasNothingToSkipPast(): void
    {
        $update = $this->update()->set(['status' => 'active'])->limit(1)->offset(1);

        $thrown = $this->assertThrows(LogicException::class, static fn (): int => $update->execute());

        $this->assertSame(
            'An UPDATE takes no offset: MySQL orders the rows and changes the first LIMIT of them,'
                . ' with nothing to skip past. Narrow the statement with where() instead.',
            $thrown->getMessage(),
        );
    }

    public function testAnAssignmentKeyThatDoesNotNameAColumnIsRefused(): void
    {
        $update = $this->update();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Update => $update->set(['status']),
        );

        $this->assertSame(
            'An assignment names the column it writes, so its key must be a string, got int at index 0.',
            $thrown->getMessage(),
        );
    }

    public function testTheIndexAKeyIsRefusedAtCountsThroughTheAssignmentsGiven(): void
    {
        $update = $this->update();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Update => $update->set(['status' => 'active', 'blocked']),
        );

        $this->assertSame(
            'An assignment names the column it writes, so its key must be a string, got int at index 1.',
            $thrown->getMessage(),
        );
    }

    public function testAnAssignmentValueThatCannotBeWrittenIsRefused(): void
    {
        $update = $this->update();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): Update => $update->set(['status' => ['active']]),
        );

        $this->assertSame(
            'The value of an assignment must be a scalar, null or an Expression, got array for column "status".',
            $thrown->getMessage(),
        );
    }

    public function testExecuteChangesTheMatchingRowsAndReportsHowMany(): void
    {
        $this->seedUsers();

        $changed = $this->update()->set(['status' => 'active'])->where('status', 'blocked')->execute();

        $this->assertSame(2, $changed);
        $this->assertSame(
            [
                ['id' => 1, 'status' => 'active', 'score' => 10],
                ['id' => 2, 'status' => 'active', 'score' => 20],
                ['id' => 3, 'status' => 'active', 'score' => 30],
            ],
            $this->users(),
        );
    }

    public function testExecuteWritesAnExpressionAgainstTheStoredValue(): void
    {
        $this->seedUsers();

        $changed = $this->update()
            ->set(['score' => Expression::of('score + ?', [5])])
            ->where('id', 2)
            ->execute();

        $this->assertSame(1, $changed);
        $this->assertSame(
            [
                ['id' => 1, 'status' => 'active', 'score' => 10],
                ['id' => 2, 'status' => 'blocked', 'score' => 25],
                ['id' => 3, 'status' => 'blocked', 'score' => 30],
            ],
            $this->users(),
        );
    }

    public function testExecuteWritesNullOverAStoredValue(): void
    {
        $this->seedUsers();

        $changed = $this->update()->set(['status' => null])->where('id', 1)->execute();

        $this->assertSame(1, $changed);
        $this->assertSame(
            [
                ['id' => 1, 'status' => null, 'score' => 10],
                ['id' => 2, 'status' => 'blocked', 'score' => 20],
                ['id' => 3, 'status' => 'blocked', 'score' => 30],
            ],
            $this->users(),
        );
    }

    public function testExecuteReportsZeroWhenNothingMatched(): void
    {
        $this->seedUsers();

        $changed = $this->update()->set(['status' => 'active'])->where('status', 'gone')->execute();

        $this->assertSame(0, $changed);
    }
}
