<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Database\Connection;
use Sloop\Database\LoggingOptions;
use Sloop\Database\Query\CompiledSql;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\RowLock;
use Sloop\Database\Query\Select;
use Sloop\Database\Query\SelectSpec;
use Sloop\Database\Result;
use Sloop\Tests\Support\ThrowsAssertions;
use UnexpectedValueException;

final class SelectTest extends TestCase
{
    use ThrowsAssertions;

    private Connection $connection;

    private string $walked = '';

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL, nickname TEXT, weight REAL)');

        // A timeout is written in for the dialect the connection detected, so
        // the statements below need one to report. MySQL's form is the only
        // one SQLite can still run: it is a comment there, where MariaDB's
        // prefix is a statement SQLite has no idea about.
        $sqlite->createFunction('version', static fn (): string => '8.0.37');

        $this->connection = new Connection($sqlite, 'select_test');
        $this->walked     = '';
    }

    private function attachLogger(): TestHandler
    {
        $handler = new TestHandler();
        $this->connection->setLogger(new Logger('database', [$handler]), new LoggingOptions(logAllQueries: true));

        return $handler;
    }

    private function loggedSql(TestHandler $handler): string
    {
        $records = $handler->getRecords();
        $this->assertNotSame([], $records, 'the connection logged no query');

        $last = end($records);
        $this->assertNotFalse($last);

        $sql = $last->context['sql'] ?? null;
        $this->assertIsString($sql);

        return $sql;
    }

    /**
     * Run the callable and return the InvalidArgumentException it throws.
     *
     * @param  callable():mixed         $run Code expected to throw
     * @return InvalidArgumentException
     */
    private function assertThrowsInvalidArgument(callable $run): InvalidArgumentException
    {
        try {
            $run();
        } catch (InvalidArgumentException $e) {
            return $e;
        }

        $this->fail('Expected an InvalidArgumentException, none was thrown.');
    }

    private function seedNumberedUsers(int $count, string $status = 'active'): void
    {
        $rows = [];

        for ($id = 1; $id <= $count; $id++) {
            $rows[] = '(' . $id . ', \'user' . $id . '\', \'' . $status . '\', NULL, 1.0)';
        }

        $this->connection->statement(
            'INSERT INTO users (id, name, status, nickname, weight) VALUES ' . implode(', ', $rows),
        );
    }

    private static function idsOf(Result $batch): string
    {
        $ids = [];

        foreach ($batch as $row) {
            $ids[] = (string) $row['id'];
        }

        return implode(',', $ids);
    }

    private function record(Result $batch, int $index): bool
    {
        // Held as text rather than a list so that the batch boundaries and
        // their numbering land in the same assertion as the ids, and so that
        // the walks can collect without a closure inheriting by reference.
        $this->walked .= '|' . $index . ':' . self::idsOf($batch);

        // Every walk below ends well inside ten batches. Stopping there anyway
        // means a walk that cannot advance is a wrong string rather than a
        // statement that never stops: a test that hangs says nothing about
        // whether the behaviour is pinned, it only says the run was killed.
        return substr_count($this->walked, '|') < 10;
    }

    private function seedUsers(): void
    {
        $this->connection->statement(
            'INSERT INTO users (id, name, status, nickname, weight) VALUES'
                . ' (1, \'alice\', \'active\', NULL, 1.5),'
                . ' (2, \'bob\', \'blocked\', NULL, 2.5),'
                . ' (3, \'carol\', \'active\', NULL, 3.5)',
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

        $this->connection->select()->from('users')->where('id', 'BETWEEN', 10);
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

    public function testAListOfConditionsAddsEachOfThem(): void
    {
        $select = $this->connection->select()->from('users')->where([
            ['status', 'active'],
            ['score', '>=', 10],
            ['deleted_at', 'IS', null],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND `score` >= ? AND `deleted_at` IS NULL',
            $select->toSql(),
        );
        $this->assertSame(['active', 10], $select->toBindings());
    }

    public function testAConditionOfTwoComparesForEquality(): void
    {
        $listed     = $this->connection->select()->from('users')->where([['status', 'active']]);
        $spelledOut = $this->connection->select()->from('users')->where('status', 'active');

        $this->assertSame($spelledOut->toSql(), $listed->toSql());
        $this->assertSame($spelledOut->toBindings(), $listed->toBindings());
    }

    public function testAnEmptyListAddsNothing(): void
    {
        $select = $this->connection->select()->from('users')->where([]);

        $this->assertSame('SELECT * FROM `users`', $select->toSql());
    }

    public function testAnEmptyListLeavesTheConditionsAlreadyAdded(): void
    {
        $select = $this->connection->select()->from('users')->where('status', 'active')->where([]);

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testAListJoinsToWhatPrecedesItWithTheConjunctionOfTheCall(): void
    {
        // The OR joins the first of the listed conditions and the rest follow
        // with AND, so the set reads as one alternative. MySQL binds AND tighter
        // than OR, which is what makes the parentheses unnecessary.
        $select = $this->connection->select()
            ->from('users')
            ->where('id', 1)
            ->orWhere([['status', 'active'], ['score', '>=', 10]]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? OR `status` = ? AND `score` >= ?',
            $select->toSql(),
        );
    }

    public function testAListAcceptsAnExpressionAsAColumnAndAsAValue(): void
    {
        $select = $this->connection->select()->from('users')->where([
            [Expression::of('LOWER(`name`)'), 'alice'],
            ['created_at', '<', Expression::of('NOW()')],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE LOWER(`name`) = ? AND `created_at` < NOW()',
            $select->toSql(),
        );
        $this->assertSame(['alice'], $select->toBindings());
    }

    public function testACallableOpensAGroupAroundWhatItAdds(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->where(static fn (Select $query): Select => $query->where('id', 1)->orWhere('id', 2));

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`id` = ? OR `id` = ?)',
            $select->toSql(),
        );
    }

    public function testACallableGivenToOrWhereJoinsItsGroupWithOr(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->orWhere(static fn (Select $query): Select => $query->where('id', 1)->andWhere('score', '>', 10));

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? OR (`id` = ? AND `score` > ?)',
            $select->toSql(),
        );
    }

    public function testACallableWritesTheSameStatementAsTheOpenAndCloseCalls(): void
    {
        $withCallable = $this->connection->select()
            ->from('users')
            ->where(static fn (Select $query): Select => $query->where('id', 1)->orWhere('id', 2));

        $withOpenClose = $this->connection->select()
            ->from('users')
            ->whereOpen()->where('id', 1)->orWhere('id', 2)->whereClose();

        $this->assertSame($withOpenClose->toSql(), $withCallable->toSql());
    }

    public function testACallableThatAddsNothingLeavesNoEmptyParentheses(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->where(static fn (Select $query): Select => $query);

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testAGroupIsClosedEvenWhereTheClosureFails(): void
    {
        $select = $this->connection->select()->from('users')->where('status', 'active');

        try {
            $select->where(static function (Select $query): void {
                $query->where('id', 1);

                throw new RuntimeException('from inside the group');
            });
            $this->fail('The failure inside the closure should reach the caller.');
        } catch (RuntimeException $failure) {
            $this->assertSame('from inside the group', $failure->getMessage());
        }

        // The parentheses balance, so this still compiles; if they did not,
        // compile() would raise a LogicException.
        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`id` = ?)',
            $select->toSql(),
        );
    }

    public function testAClosureThatFailsWithAGroupOpenDoesNotHaveItsFailureReplaced(): void
    {
        // Closing unconditionally in the finally would raise a LogicException of
        // its own here and replace the failure the caller needs to see.
        $select = $this->connection->select()->from('users')->where('status', 'active');

        try {
            $select->where(static function (Select $query): void {
                $query->whereOpen()->where('id', 1);

                throw new RuntimeException('the failure that matters');
            });
            $this->fail('The failure inside the closure should reach the caller.');
        } catch (RuntimeException $failure) {
            $this->assertSame('the failure that matters', $failure->getMessage());
        }

        // The group the closure left open is reported where the statement is
        // compiled, not swallowed here.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'A callback opened a group of conditions and returned without closing it.'
            . ' Close it inside the callback, or leave the parentheses it was handed to close themselves.',
        );

        $select->toSql();
    }

    public function testAClosureCannotCloseTheGroupItWasHanded(): void
    {
        // Letting it through would put everything the closure writes afterwards
        // outside the parentheses it appears to be writing inside, which changes
        // the rows that come back without raising anything.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'This group was opened outside the callback holding the builder, so the callback cannot close it.',
        );

        $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->where(static function (Select $query): void {
                $query->where('id', 1)->whereClose();
            });
    }

    public function testAClosureMayOpenAndCloseGroupsOfItsOwn(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->where(static function (Select $query): void {
                $query->where('id', 1)
                    ->andWhereOpen()
                        ->where('name', 'alice')
                        ->orWhere('name', 'bob')
                    ->andWhereClose();
            });

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`id` = ? AND (`name` = ? OR `name` = ?))',
            $select->toSql(),
        );
    }

    public function testWhatAClosureWritesAfterClosingItsOwnGroupStaysInsideTheOneItWasHanded(): void
    {
        // The direct converse of closing the handed group: everything the
        // closure writes belongs inside its parentheses, before and after a
        // group of its own.
        $select = $this->connection->select()
            ->from('users')
            ->where(static function (Select $query): void {
                $query->whereOpen()->where('id', 1)->whereClose();
                $query->orWhere('status', 'active');
            });

        $this->assertSame(
            'SELECT * FROM `users` WHERE ((`id` = ?) OR `status` = ?)',
            $select->toSql(),
        );
    }

    public function testAClosureInsideAGroupOpenedByHandNests(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->whereOpen()
                ->where(static fn (Select $query): Select => $query->where('id', 1))
            ->whereClose();

        $this->assertSame('SELECT * FROM `users` WHERE ((`id` = ?))', $select->toSql());
    }

    public function testAClosureInsideAGroupOpenedByAnotherClosureNests(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where(static function (Select $outer): void {
                $outer->whereOpen()
                    ->where(static fn (Select $inner): Select => $inner->where('id', 1))
                    ->whereClose();
            });

        $this->assertSame('SELECT * FROM `users` WHERE (((`id` = ?)))', $select->toSql());
    }

    public function testAClosureThatLeavesAGroupOpenIsReportedEvenWhereALaterCloseWouldBalanceIt(): void
    {
        // Two mistakes in one chain used to cancel: the group the closure left
        // open absorbed the stray close, and the condition in between landed in
        // parentheses nobody wrote. The depth on the way out of a closure is now
        // the depth on the way in, so the stray close has nothing to take.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('No group of conditions is open, so there is nothing to close.');

        $this->connection->select()
            ->from('users')
            ->where(static function (Select $query): void {
                $query->whereOpen()->where('status', 'active');
            })
            ->orWhere('name', 'bob')
            ->whereClose();
    }

    public function testEveryGroupAClosureLeavesOpenIsClosedForIt(): void
    {
        // Tidying up after a closure has to run until the depth is back where
        // it started, not once: with two groups left open, closing a single one
        // would hand the chain a group that is not its own, and the close below
        // would take it instead of saying there is nothing to close.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('No group of conditions is open, so there is nothing to close.');

        $this->connection->select()
            ->from('users')
            ->where(static function (Select $query): void {
                $query->whereOpen()->where('status', 'active')->whereOpen()->where('role', 'admin');
            })
            ->whereClose();
    }

    #[DataProvider('provideCallsWithArgumentsThatWouldBeIgnored')]
    public function testAListOrClosureGivenOtherArgumentsIsRejected(callable $call, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $call($this->connection->select()->from('users'));
    }

    /**
     * @return array<string, array{callable(Select): mixed, string}>
     */
    public static function provideCallsWithArgumentsThatWouldBeIgnored(): array
    {
        return [
            'list with one more' => [
                static fn (Select $query): Select => $query->where([['status', 'active']], 'EXTRA'),
                'A list of conditions says everything on its own, so the other 1 argument would be ignored.',
            ],
            'empty list with one more' => [
                static fn (Select $query): Select => $query->where([], 'EXTRA'),
                'A list of conditions says everything on its own, so the other 1 argument would be ignored.',
            ],
            'list with two more' => [
                static fn (Select $query): Select => $query->where([['status', 'active']], '=', 'EXTRA'),
                'A list of conditions says everything on its own, so the other 2 arguments would be ignored.',
            ],
            'closure with one more' => [
                static fn (Select $query): Select => $query->where(
                    static fn (Select $inner): Select => $inner->where('id', 1),
                    'EXTRA',
                ),
                'A closure says everything on its own, so the other 1 argument would be ignored.',
            ],
        ];
    }

    public function testAClosureCannotCloseAGroupItDidNotOpen(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'This group was opened outside the callback holding the builder, so the callback cannot close it.',
        );

        $this->connection->select()
            ->from('users')
            ->whereOpen()
            ->where(static function (Select $query): void {
                $query->whereClose();
            });
    }

    public function testANestedClosureCannotCloseTheGroupOfTheOneAroundIt(): void
    {
        // Without the floor this closed the outer group, and the conditions the
        // outer closure added afterwards landed outside its parentheses — which
        // changes the rows that come back, silently.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'This group was opened outside the callback holding the builder, so the callback cannot close it.',
        );

        $this->connection->select()
            ->from('users')
            ->where(static function (Select $outer): void {
                $outer->where(static function (Select $inner): void {
                    $inner->whereClose();
                });
            });
    }

    public function testClosingWithNothingOpenStillSaysThereIsNothingToClose(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('No group of conditions is open, so there is nothing to close.');

        $this->connection->select()->from('users')->whereClose();
    }

    public function testAGroupLeftOpenInsideAClosureIsStillReported(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'A callback opened a group of conditions and returned without closing it.'
            . ' Close it inside the callback, or leave the parentheses it was handed to close themselves.',
        );

        $this->connection->select()
            ->from('users')
            ->where(static function (Select $query): void {
                $query->whereOpen()->where('id', 1);
            })
            ->toSql();
    }

    public function testAListAndACallableCombine(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where([['status', 'active'], ['score', '>=', 10]])
            ->where(static fn (Select $query): Select => $query->where('role', 'admin')->orWhere('role', 'editor'));

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND `score` >= ? AND (`role` = ? OR `role` = ?)',
            $select->toSql(),
        );
    }

    public function testAColumnOnItsOwnIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A comparison needs something to compare against, but only a column was given.'
            . ' Pass a value, or an operator and a value.',
        );

        $this->connection->select()->from('users')->where('status');
    }

    /**
     * @param array<int|string, mixed> $conditions
     */
    #[DataProvider('provideMalformedConditionLists')]
    public function testAMalformedListIsRejected(array $conditions, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->connection->select()->from('users')->where($conditions);
    }

    /**
     * @return array<string, array{array<int|string, mixed>, string}>
     */
    public static function provideMalformedConditionLists(): array
    {
        return [
            'condition is not a list' => [
                ['junk'],
                'Each condition must be a list of two or three, got string at index 0.',
            ],
            'condition of one' => [
                [['status']],
                'got 1 at index 0.',
            ],
            'condition of four' => [
                [['status', '=', 'active', 'or']],
                'got 4 at index 0.',
            ],
            'the reported index is the position' => [
                [['status', 'active'], ['score']],
                'got 1 at index 1.',
            ],
            'column is not a column' => [
                [[10, '=', 1]],
                'so it must be a string or an Expression, got int at index 0.',
            ],
            'value cannot be compared' => [
                [['status', ['nested']]],
                'compares against a scalar, null or an Expression, got array at index 0.',
            ],
            'value of a condition of three cannot be compared' => [
                [['status', '=', ['nested']]],
                'compares against a scalar, null or an Expression, got array at index 0.',
            ],
            'operator is not a string' => [
                [['status', 10, 'active']],
                'The middle part of a condition of three is the operator, so it must be a string, got int at index 0.',
            ],
        ];
    }

    public function testTheReportedPositionIsThePositionInTheListNotTheOriginalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('got 1 at index 0.');

        $this->connection->select()->from('users')->where([7 => ['status']]);
    }

    public function testAConditionIsReadByPositionRatherThanByKey(): void
    {
        // The keys are deliberately in the wrong order: were they consulted,
        // the column and the value would come out swapped.
        $select = $this->connection->select()->from('users')->where([
            ['value' => 'active', 'column' => 'status'],
        ]);

        $this->assertSame('SELECT * FROM `users` WHERE `active` = ?', $select->toSql());
        $this->assertSame(['status'], $select->toBindings());
    }

    public function testAListRejectsNullTheSameWayTheArgumentsDo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Write IS or IS NOT to test for NULL.');

        $this->connection->select()->from('users')->where([['deleted_at', '=', null]]);
    }

    public function testTestingForNullWritesTheKeywordRatherThanAPlaceholder(): void
    {
        $select = $this->connection->select()->from('users')->where('deleted_at', 'IS', null);

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testTestingForNotNullWritesTheKeywordRatherThanAPlaceholder(): void
    {
        $select = $this->connection->select()->from('users')->where('deleted_at', 'IS NOT', null);

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NOT NULL', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testWhereNullIsTheSameStatementAsTheIsOperator(): void
    {
        $spelledOut = $this->connection->select()->from('users')->where('deleted_at', 'IS', null);
        $named      = $this->connection->select()->from('users')->whereNull('deleted_at');

        $this->assertSame($spelledOut->toSql(), $named->toSql());
    }

    public function testWhereNotNullIsTheSameStatementAsTheIsNotOperator(): void
    {
        $spelledOut = $this->connection->select()->from('users')->where('deleted_at', 'IS NOT', null);
        $named      = $this->connection->select()->from('users')->whereNotNull('deleted_at');

        $this->assertSame($spelledOut->toSql(), $named->toSql());
    }

    public function testTheNullSafeEqualBindsNullRatherThanWritingTheKeyword(): void
    {
        // <=> compares values and answers true for two nulls, so null reaches
        // it as a bound value like any other rather than as a keyword.
        $select = $this->connection->select()->from('users')->where('deleted_at', '<=>', null);

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` <=> ?', $select->toSql());
        $this->assertSame([null], $select->toBindings());
    }

    public function testTheIsOperatorRefusesAValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Use = to compare against a value.');

        $this->connection->select()->from('users')->where('id', 'IS', 10);
    }

    public function testTestingForTrueWritesTheKeywordRatherThanAPlaceholder(): void
    {
        $select = $this->connection->select()->from('users')->where('active', 'IS', true);

        $this->assertSame('SELECT * FROM `users` WHERE `active` IS TRUE', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testTestingForNotFalseWritesTheKeywordRatherThanAPlaceholder(): void
    {
        $select = $this->connection->select()->from('users')->where('active', 'IS NOT', false);

        $this->assertSame('SELECT * FROM `users` WHERE `active` IS NOT FALSE', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testComparingAgainstTrueWithEqualsBindsItRatherThanWritingTheKeyword(): void
    {
        // = compares values, so true is bound like any other value; only the
        // keyword operators answer for the three-valued logic IS reads.
        $select = $this->connection->select()->from('users')->where('active', '=', true);

        $this->assertSame('SELECT * FROM `users` WHERE `active` = ?', $select->toSql());
        $this->assertSame([true], $select->toBindings());
    }

    #[DataProvider('providePatternOperators')]
    public function testAPatternMatchBindsItsPatternLikeAnyOtherValue(string $operator, string $expected): void
    {
        $select = $this->connection->select()->from('users')->where('name', $operator, '^a');

        $this->assertSame('SELECT * FROM `users` WHERE `name` ' . $expected . ' ?', $select->toSql());
        $this->assertSame(['^a'], $select->toBindings());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function providePatternOperators(): array
    {
        return [
            'regexp'      => ['REGEXP', 'REGEXP'],
            'not regexp'  => ['NOT REGEXP', 'NOT REGEXP'],
            'rlike'       => ['rlike', 'RLIKE'],
            'sounds like' => ['sounds like', 'SOUNDS LIKE'],
        ];
    }

    public function testRejectingNullPointsAtTheOperatorThatTestsForIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Write IS or IS NOT to test for NULL.');

        $this->connection->select()->from('users')->where('deleted_at', '=', null);
    }

    public function testWhereInWritesOnePlaceholderPerValue(): void
    {
        $select = $this->connection->select()->from('users')->whereIn('status', ['active', 'pending']);

        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?, ?)', $select->toSql());
        $this->assertSame(['active', 'pending'], $select->toBindings());
    }

    public function testWhereNotInNegatesTheMembershipTest(): void
    {
        $select = $this->connection->select()->from('users')->whereNotIn('status', ['blocked']);

        $this->assertSame('SELECT * FROM `users` WHERE `status` NOT IN (?)', $select->toSql());
        $this->assertSame(['blocked'], $select->toBindings());
    }

    public function testNullAmongTheValuesOfAMembershipTestIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('makes NOT IN match no rows at all');

        $this->connection->select()->from('users')->whereNotIn('status', ['blocked', null]);
    }

    public function testWhereBetweenWritesBothBounds(): void
    {
        $select = $this->connection->select()->from('users')->whereBetween('id', 10, 20);

        $this->assertSame('SELECT * FROM `users` WHERE `id` BETWEEN ? AND ?', $select->toSql());
        $this->assertSame([10, 20], $select->toBindings());
    }

    public function testAGroupOfConditionsIsParenthesised(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->whereOpen()
                ->where('id', '<', 10)
                ->orWhere('name', 'alice')
            ->whereClose();

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`id` < ? OR `name` = ?)',
            $select->toSql(),
        );
        $this->assertSame(['active', 10, 'alice'], $select->toBindings());
    }

    public function testAGroupCarriesTheConjunctionItWasOpenedWith(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->orWhereOpen()
                ->where('id', '<', 10)
            ->orWhereClose();

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? OR (`id` < ?)',
            $select->toSql(),
        );
    }

    public function testAGroupOpeningTheClauseCarriesNoConjunction(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->whereOpen()
                ->where('id', '<', 10)
            ->whereClose()
            ->andWhere('status', 'active');

        $this->assertSame(
            'SELECT * FROM `users` WHERE (`id` < ?) AND `status` = ?',
            $select->toSql(),
        );
    }

    public function testGroupsNestOneInsideAnother(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->whereOpen()
                ->where('id', '<', 10)
                ->orWhereOpen()
                    ->where('name', 'alice')
                    ->andWhere('status', 'active')
                ->orWhereClose()
            ->whereClose();

        $this->assertSame(
            'SELECT * FROM `users` WHERE (`id` < ? OR (`name` = ? AND `status` = ?))',
            $select->toSql(),
        );
    }

    public function testAndWhereOpenReadsTheSameAsWhereOpen(): void
    {
        $plain   = $this->connection->select()->from('users')->where('id', 1)->whereOpen()->where('id', 2)->whereClose();
        $spelled = $this->connection->select()->from('users')->where('id', 1)->andWhereOpen()->where('id', 2)->andWhereClose();

        $this->assertSame($plain->toSql(), $spelled->toSql());
    }

    public function testLeavingAGroupOpenIsRejectedWhenTheStatementIsCompiled(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('call whereClose() 1 more time.');

        $this->connection->select()->from('users')->whereOpen()->where('id', 1)->toSql();
    }

    public function testTheNumberOfGroupsLeftOpenIsReported(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('call whereClose() 2 more times.');

        $this->connection->select()->from('users')->whereOpen()->whereOpen()->where('id', 1)->toSql();
    }

    public function testAGroupLeftEmptyIsDroppedRatherThanWrittenAsEmptyParentheses(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->whereOpen()
            ->whereClose();

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testAStatementWhoseOnlyGroupIsEmptyHasNoWhereClause(): void
    {
        $select = $this->connection->select()->from('users')->whereOpen()->whereClose();

        $this->assertSame('SELECT * FROM `users`', $select->toSql());
    }

    public function testNestedEmptyGroupsAreDroppedTogether(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->whereOpen()
                ->whereOpen()
                ->whereClose()
            ->whereClose();

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testWhenAppliesTheCallbackWhileTheConditionHolds(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(true, static fn (Select $query): Select => $query->where('status', 'active'));

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testWhenLeavesTheStatementAloneWhileTheConditionDoesNotHold(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(false, static fn (Select $query): Select => $query->where('status', 'active'));

        $this->assertSame('SELECT * FROM `users`', $select->toSql());
    }

    public function testWhenFallsBackToTheSecondCallback(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(
                false,
                static fn (Select $query): Select => $query->where('status', 'active'),
                static fn (Select $query): Select => $query->where('status', 'blocked'),
            );

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
        $this->assertSame(['blocked'], $select->toBindings());
    }

    public function testWhenLeavesTheSecondCallbackAloneWhileTheConditionHolds(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(
                true,
                static fn (Select $query): Select => $query->where('status', 'active'),
                static fn (Select $query): Select => $query->where('status', 'blocked'),
            );

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
        $this->assertSame(['active'], $select->toBindings());
    }

    public function testAWhenCallbackCannotCloseAGroupOpenedBeforeIt(): void
    {
        // Closing it would put everything the callback writes afterwards
        // outside the parentheses the chain is holding open.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'This group was opened outside the callback holding the builder, so the callback cannot close it.',
        );

        $this->connection->select()
            ->from('users')
            ->whereOpen()
            ->where('role', 'admin')
            ->when(true, static fn (Select $query): Select => $query->whereClose()->where('status', 'active'));
    }

    public function testAWhenCallbackCannotCloseAGroupWhenNoneIsOpen(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('No group of conditions is open, so there is nothing to close.');

        $this->connection->select()
            ->from('users')
            ->when(true, static fn (Select $query): Select => $query->whereClose());
    }

    public function testAWhenCallbackMayOpenAndCloseItsOwnGroup(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->when(true, static fn (Select $query): Select => $query
                ->whereOpen()
                ->where('role', 'admin')
                ->orWhere('role', 'editor')
                ->whereClose());

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`role` = ? OR `role` = ?)',
            $select->toSql(),
        );
    }

    public function testAWhenCallbackWritesInsideAGroupTheChainOpened(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->whereOpen()
            ->where('role', 'admin')
            ->when(true, static fn (Select $query): Select => $query->orWhere('role', 'editor'))
            ->whereClose();

        $this->assertSame('SELECT * FROM `users` WHERE (`role` = ? OR `role` = ?)', $select->toSql());
    }

    public function testAGroupLeftOpenInAWhenCallbackIsReported(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(true, static fn (Select $query): Select => $query->whereOpen()->where('role', 'admin'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'A callback opened a group of conditions and returned without closing it.',
        );

        $select->toSql();
    }

    public function testAGroupLeftOpenInAWhenCallbackDoesNotAbsorbAStrayClose(): void
    {
        // Two mistakes in one chain would cancel out: the group the callback
        // left open would absorb the close that follows, and the condition in
        // between would land in parentheses nobody wrote. The depth on the way
        // out of a callback is the depth on the way in, so that close has
        // nothing to take and fails at the line that made it.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('No group of conditions is open, so there is nothing to close.');

        $this->connection->select()
            ->from('users')
            ->when(true, static fn (Select $query): Select => $query->whereOpen()->where('status', 'active'))
            ->orWhere('name', 'bob')
            ->whereClose();
    }

    public function testAWhenDefaultCallbackIsHeldToTheSameGroups(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'This group was opened outside the callback holding the builder, so the callback cannot close it.',
        );

        $this->connection->select()
            ->from('users')
            ->whereOpen()
            ->where('role', 'admin')
            ->when(
                false,
                static fn (Select $query): Select => $query->where('status', 'active'),
                static fn (Select $query): Select => $query->whereClose(),
            );
    }

    public function testNestedWhenCallbacksEachKeepTheirOwnGroup(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(true, static fn (Select $query): Select => $query
                ->whereOpen()
                ->where('role', 'admin')
                ->when(true, static fn (Select $inner): Select => $inner
                    ->whereOpen()
                    ->where('status', 'active')
                    ->whereClose())
                ->whereClose());

        $this->assertSame('SELECT * FROM `users` WHERE (`role` = ? AND (`status` = ?))', $select->toSql());
    }

    public function testAWhenCallbackCannotCloseMoreGroupsThanItOpened(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'This group was opened outside the callback holding the builder, so the callback cannot close it.',
        );

        $this->connection->select()
            ->from('users')
            ->whereOpen()
            ->where('role', 'admin')
            ->when(true, static fn (Select $query): Select => $query
                ->whereOpen()
                ->where('status', 'active')
                ->whereClose()
                ->whereClose());
    }

    public function testAWhenCallbackInsideAClosureCannotCloseTheClosuresGroup(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'This group was opened outside the callback holding the builder, so the callback cannot close it.',
        );

        $this->connection->select()
            ->from('users')
            ->where(static fn (Select $query): Select => $query
                ->where('role', 'admin')
                ->when(true, static fn (Select $inner): Select => $inner->whereClose()));
    }

    public function testWhereRawWritesTheConditionAsGiven(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->whereRaw('LENGTH(name) > ?', [3]);

        $this->assertSame('SELECT * FROM `users` WHERE LENGTH(name) > ?', $select->toSql());
        $this->assertSame([3], $select->toBindings());
    }

    public function testWhereRawJoinsToWhatPrecedesIt(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->whereRaw('LENGTH(name) > ?', [3]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND LENGTH(name) > ?',
            $select->toSql(),
        );
        $this->assertSame(['active', 3], $select->toBindings());
    }

    public function testOrWhereRawJoinsWithOr(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->orWhereRaw('LENGTH(name) > ?', [3]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? OR LENGTH(name) > ?',
            $select->toSql(),
        );
    }

    public function testSelectRawAddsAColumnWrittenAsSql(): void
    {
        $select = $this->connection->select('id')->from('users')->selectRaw('LENGTH(name) AS len');

        $this->assertSame('SELECT `id`, LENGTH(name) AS len FROM `users`', $select->toSql());
    }

    public function testOrderByRawWritesTheTermWithoutADirection(): void
    {
        $select = $this->connection->select()->from('users')->orderByRaw('FIELD(status, ?, ?)', ['active', 'blocked']);

        $this->assertSame('SELECT * FROM `users` ORDER BY FIELD(status, ?, ?)', $select->toSql());
        $this->assertSame(['active', 'blocked'], $select->toBindings());
    }

    public function testBindingsFollowThePlaceholdersAcrossEveryClause(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->selectRaw('IF(status = ?, 1, 0) AS flag', ['active'])
            ->where('id', '>', 1)
            ->whereIn('status', ['active', 'pending'])
            ->orderByRaw('FIELD(name, ?)', ['alice']);

        $this->assertSame(['active', 1, 'active', 'pending', 'alice'], $select->toBindings());
    }

    public function testFirstReturnsTheFirstRowThatMatches(): void
    {
        $this->seedUsers();

        $row = $this->connection->select('id', 'name')
            ->from('users')
            ->where('status', 'active')
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertSame(['id' => 3, 'name' => 'carol'], $row);
    }

    public function testFirstReturnsNullWhenNothingMatches(): void
    {
        $this->seedUsers();

        $row = $this->connection->select()->from('users')->where('status', 'gone')->first();

        $this->assertNull($row);
    }

    public function testFirstAsksTheServerForOneRowOnly(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select('name')->from('users')->orderBy('id')->first();

        $this->assertSame('SELECT `name` FROM `users` ORDER BY `id` ASC LIMIT 1', $this->loggedSql($handler));
    }

    public function testFirstNarrowsTheRowWindowThatWasAlreadySet(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select('name')->from('users')->orderBy('id')->limit(10)->offset(1)->first();

        $this->assertSame(
            'SELECT `name` FROM `users` ORDER BY `id` ASC LIMIT 1 OFFSET 1',
            $this->loggedSql($handler),
        );
    }

    public function testFirstLeavesTheBuilderAsItWas(): void
    {
        $select = $this->connection->select('name')->from('users')->limit(10);
        $before = $select->toSql();

        $select->first();

        $this->assertSame($before, $select->toSql());
    }

    public function testValueReturnsOneColumnOfTheFirstRow(): void
    {
        $this->seedUsers();

        $name = $this->connection->select()
            ->from('users')
            ->where('id', 2)
            ->value('name');

        $this->assertSame('bob', $name);
    }

    public function testValueReturnsNullWhenNothingMatches(): void
    {
        $this->seedUsers();

        $this->assertNull($this->connection->select()->from('users')->where('id', 99)->value('name'));
    }

    public function testValueNarrowsTheRowWindowThatWasAlreadySet(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select('id', 'name')->from('users')->orderBy('id')->limit(10)->offset(1)->value('name');

        $this->assertSame(
            'SELECT `name` FROM `users` ORDER BY `id` ASC LIMIT 1 OFFSET 1',
            $this->loggedSql($handler),
        );
    }

    public function testValueReadsThroughAnOffset(): void
    {
        $this->seedUsers();

        $name = $this->connection->select()->from('users')->orderBy('id')->offset(1)->value('name');

        $this->assertSame('bob', $name);
    }

    public function testValueAsksOnlyForTheColumnItReturns(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select('id', 'name', 'status')->from('users')->orderBy('id')->value('name');

        $this->assertSame('SELECT `name` FROM `users` ORDER BY `id` ASC LIMIT 1', $this->loggedSql($handler));
    }

    public function testGetReturnsEveryRowThatMatches(): void
    {
        $this->seedUsers();

        $rows = $this->connection->select('name')
            ->from('users')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $this->assertSame([['name' => 'alice'], ['name' => 'carol']], $rows);
    }

    public function testCountReturnsHowManyRowsMatch(): void
    {
        $this->seedUsers();

        $count = $this->connection->select('id', 'name')
            ->from('users')
            ->where('status', 'active')
            ->count();

        $this->assertSame(2, $count);
    }

    public function testCountAsksTheServerToCountRatherThanReadingTheRows(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select('id', 'name')->from('users')->where('status', 'active')->count();

        $this->assertSame('SELECT COUNT(*) FROM `users` WHERE `status` = ?', $this->loggedSql($handler));
    }

    public function testCountDropsTheRowWindow(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select()->from('users')->limit(2)->offset(1)->count();

        $this->assertSame('SELECT COUNT(*) FROM `users`', $this->loggedSql($handler));
    }

    public function testCountReportsEveryMatchThroughARowWindow(): void
    {
        // The window would have applied to the single row COUNT(*) produces,
        // throwing it away rather than limiting what was counted.
        $this->seedUsers();

        $count = $this->connection->select()->from('users')->limit(2)->offset(1)->count();

        $this->assertSame(3, $count);
    }

    public function testExistsDropsTheRowWindow(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select()->from('users')->limit(2)->offset(1)->exists();

        $this->assertSame('SELECT 1 FROM `users` LIMIT 1', $this->loggedSql($handler));
    }

    public function testExistsIsTrueThroughAnOffsetThatWouldSkipTheOnlyMatch(): void
    {
        $this->seedUsers();

        $this->assertTrue($this->connection->select()->from('users')->where('id', 1)->offset(1)->exists());
    }

    public function testPluckKeepsTheRowWindow(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select()->from('users')->orderBy('id')->limit(2)->offset(1)->pluck('name');

        $this->assertSame(
            'SELECT `name` FROM `users` ORDER BY `id` ASC LIMIT 2 OFFSET 1',
            $this->loggedSql($handler),
        );
    }

    public function testPluckKeepsALimitThatHasNoOffset(): void
    {
        // Without this case the limit would only be held by the offset test,
        // where dropping it leaves a bare OFFSET that SelectSpec refuses. The
        // failure would then come from that constraint rather than from an
        // assertion about what pluck() sends.
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select()->from('users')->orderBy('id')->limit(2)->pluck('name');

        $this->assertSame('SELECT `name` FROM `users` ORDER BY `id` ASC LIMIT 2', $this->loggedSql($handler));
    }

    public function testPluckRefusesTwoColumnsThatComeBackUnderOneName(): void
    {
        $this->seedUsers();

        $e = $this->assertThrowsInvalidArgument(
            fn () => $this->connection->select()->from('users')->pluck('name', 'name'),
        );
        $this->assertSame(
            'Columns "name" and "name" came back under one name, so there is no value to key.',
            $e->getMessage(),
        );
    }

    public function testValueLeavesTheBuilderAsItWas(): void
    {
        $select = $this->connection->select('id', 'name')->from('users')->limit(10);
        $before = $select->toSql();

        $select->value('name');

        $this->assertSame($before, $select->toSql());
    }

    public function testCountLeavesTheBuilderAsItWas(): void
    {
        $select = $this->connection->select('id', 'name')->from('users')->limit(10)->offset(1);
        $before = $select->toSql();

        $select->count();

        $this->assertSame($before, $select->toSql());
    }

    public function testPluckLeavesTheBuilderAsItWas(): void
    {
        // The columns pluck() reads must differ from the ones the builder
        // names, or writing them back would leave toSql() looking unchanged.
        $select = $this->connection->select('id', 'name', 'status')->from('users')->limit(10);
        $before = $select->toSql();

        $select->pluck('name');

        $this->assertSame($before, $select->toSql());
    }

    public function testExistsLeavesTheBuilderAsItWas(): void
    {
        $select = $this->connection->select('id', 'name')->from('users')->limit(10)->offset(1);
        $before = $select->toSql();

        $select->exists();

        $this->assertSame($before, $select->toSql());
    }

    public function testExistsIsTrueWhenARowMatches(): void
    {
        $this->seedUsers();

        $this->assertTrue($this->connection->select()->from('users')->where('status', 'active')->exists());
    }

    public function testExistsIsFalseWhenNothingMatches(): void
    {
        $this->seedUsers();

        $this->assertFalse($this->connection->select()->from('users')->where('status', 'gone')->exists());
    }

    public function testExistsAsksForASingleConstantRow(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select('id', 'name')->from('users')->where('status', 'active')->exists();

        $this->assertSame('SELECT 1 FROM `users` WHERE `status` = ? LIMIT 1', $this->loggedSql($handler));
    }

    public function testDoesntExistIsTrueWhenNothingMatches(): void
    {
        $this->seedUsers();

        $this->assertTrue($this->connection->select()->from('users')->where('status', 'gone')->doesntExist());
    }

    public function testDoesntExistIsFalseWhenARowMatches(): void
    {
        $this->seedUsers();

        $this->assertFalse($this->connection->select()->from('users')->where('status', 'active')->doesntExist());
    }

    public function testPluckReturnsTheValuesOfOneColumn(): void
    {
        $this->seedUsers();

        $names = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('name');

        $this->assertSame(['alice', 'carol'], $names);
    }

    public function testPluckReturnsAMapWhenGivenAKeyColumn(): void
    {
        $this->seedUsers();

        $names = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('name', 'id');

        $this->assertSame([1 => 'alice', 3 => 'carol'], $names);
    }

    public function testPluckAsksOnlyForTheColumnsItNeeds(): void
    {
        $this->seedUsers();
        $handler = $this->attachLogger();

        $this->connection->select('id', 'name', 'status')->from('users')->orderBy('id')->pluck('name', 'id');

        $this->assertSame('SELECT `id`, `name` FROM `users` ORDER BY `id` ASC', $this->loggedSql($handler));
    }

    public function testPluckReturnsAnEmptyArrayWhenNothingMatches(): void
    {
        $this->seedUsers();

        $this->assertSame([], $this->connection->select()->from('users')->where('status', 'gone')->pluck('name'));
    }

    public function testPluckRefusesAKeyColumnHoldingNull(): void
    {
        $this->seedUsers();

        $e = $this->assertThrowsInvalidArgument(
            fn () => $this->connection->select()->from('users')->pluck('name', 'nickname'),
        );
        $this->assertSame(
            'Column "nickname" holds null, which cannot key an array.',
            $e->getMessage(),
        );
    }

    public function testPluckRefusesAKeyColumnHoldingAFloat(): void
    {
        $this->seedUsers();

        $e = $this->assertThrowsInvalidArgument(
            fn () => $this->connection->select()->from('users')->pluck('name', 'weight'),
        );
        $this->assertSame(
            'Column "weight" holds float, which cannot key an array.',
            $e->getMessage(),
        );
    }

    public function testPluckAcceptsAStringKeyColumn(): void
    {
        $this->seedUsers();

        $byName = $this->connection->select()->from('users')->orderBy('id')->pluck('status', 'name');

        $this->assertSame(['alice' => 'active', 'bob' => 'blocked', 'carol' => 'active'], $byName);
    }

    public function testCountRefusesACountThatIsNotAnInteger(): void
    {
        // Connection::open() lets a caller keep PDO::ATTR_STRINGIFY_FETCHES on,
        // which hands COUNT(*) back as a string. count() contracts to int, so
        // it says so rather than casting something it did not expect.
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => true,
        ]);
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $sqlite->exec('INSERT INTO users (id) VALUES (1)');

        $connection = new Connection($sqlite, 'stringify_test');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('COUNT(*) returned string where an integer was expected.');

        $connection->select()->from('users')->count();
    }

    public function testCountRefusesAStatementThatReadNoRow(): void
    {
        // Grammar is an extension point: a subclass can replace compileSelect
        // outright, so the statement count() runs is not guaranteed to be the
        // COUNT(*) it asked for. Without the fallback the row would reach
        // array_values() as null and raise a TypeError instead of saying which
        // contract was broken.
        $this->connection->setGrammar(new class () extends Grammar {
            public function compileSelect(SelectSpec $spec): CompiledSql
            {
                return new CompiledSql('SELECT 1 WHERE 1 = 0', []);
            }
        });

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('COUNT(*) returned null where an integer was expected.');

        $this->connection->select()->from('users')->count();
    }

    public function testShortcutsRefuseAStatementWithNoTable(): void
    {
        $this->expectException(LogicException::class);

        $this->connection->select()->first();
    }

    public function testShortcutsRefuseAGroupLeftOpen(): void
    {
        $this->expectException(LogicException::class);

        $this->connection->select()->from('users')->whereOpen()->where('id', 1)->count();
    }

    public function testForUpdateWritesALockClause(): void
    {
        $sql = $this->connection->select()->from('users')->forUpdate()->compile()->sql;

        $this->assertSame('SELECT * FROM `users` FOR UPDATE', $sql);
    }

    public function testForUpdateSkipLockedWritesALockClause(): void
    {
        $sql = $this->connection->select()->from('users')->forUpdate(skipLocked: true)->compile()->sql;

        $this->assertSame('SELECT * FROM `users` FOR UPDATE SKIP LOCKED', $sql);
    }

    public function testForUpdateNoWaitWritesALockClause(): void
    {
        $sql = $this->connection->select()->from('users')->forUpdate(noWait: true)->compile()->sql;

        $this->assertSame('SELECT * FROM `users` FOR UPDATE NOWAIT', $sql);
    }

    public function testSharedLockWritesALockClause(): void
    {
        $sql = $this->connection->select()->from('users')->sharedLock()->compile()->sql;

        $this->assertSame('SELECT * FROM `users` LOCK IN SHARE MODE', $sql);
    }

    public function testAStatementCarriesNoLockUntilOneIsAskedFor(): void
    {
        $sql = $this->connection->select()->from('users')->where('id', 1)->compile()->sql;

        $this->assertSame('SELECT * FROM `users` WHERE `id` = ?', $sql);
    }

    public function testForUpdateRefusesSkipLockedAndNoWaitTogether(): void
    {
        $e = $this->assertThrowsInvalidArgument(
            fn () => $this->connection->select()->from('users')->forUpdate(skipLocked: true, noWait: true),
        );
        $this->assertSame(
            'SKIP LOCKED and NOWAIT each say what to do about a row that is already locked, so a statement takes one or the other.',
            $e->getMessage(),
        );
    }

    public function testTheLastLockAskedForIsTheOneWritten(): void
    {
        $sql = $this->connection->select()->from('users')->forUpdate()->sharedLock()->compile()->sql;

        $this->assertSame('SELECT * FROM `users` LOCK IN SHARE MODE', $sql);
    }

    /**
     * @param 'count'|'doesntExist'|'exists'|'first'|'get'|'pluck'|'value' $shortcut Shortcut to call
     */
    #[DataProvider('provideShortcuts')]
    public function testShortcutsKeepTheLock(string $shortcut): void
    {
        // A lock says how the rows this statement reaches should be held, which
        // is independent of how many of them come back. The shortcuts narrow or
        // drop the row window, so this holds them to leaving the lock alone.
        //
        // SQLite cannot parse a lock clause, so the grammar records what it was
        // handed and writes nothing, letting the statement itself still run.
        $this->seedUsers();

        $grammar = new class () extends Grammar {
            public ?RowLock $seen = null;

            protected function compileLock(?RowLock $lock): CompiledSql
            {
                $this->seen = $lock;

                return new CompiledSql('');
            }
        };

        $this->connection->setGrammar($grammar);

        $select = $this->connection->select()->from('users')->limit(2)->offset(1)->forUpdate();

        match ($shortcut) {
            'first'       => $select->first(),
            'value'       => $select->value('name'),
            'get'         => $select->get(),
            'count'       => $select->count(),
            'exists'      => $select->exists(),
            'doesntExist' => $select->doesntExist(),
            'pluck'       => $select->pluck('name'),
        };

        $this->assertSame(RowLock::Update, $grammar->seen);
    }

    /**
     * @return array<string, array{'count'|'doesntExist'|'exists'|'first'|'get'|'pluck'|'value'}>
     */
    public static function provideShortcuts(): array
    {
        return [
            'first'       => ['first'],
            'value'       => ['value'],
            'get'         => ['get'],
            'count'       => ['count'],
            'exists'      => ['exists'],
            'doesntExist' => ['doesntExist'],
            'pluck'       => ['pluck'],
        ];
    }

    public function testCountRefusesANoWaitLock(): void
    {
        $this->seedUsers();

        $e = $this->assertThrows(
            LogicException::class,
            fn () => $this->connection->select()->from('users')->forUpdate(noWait: true)->count(),
        );

        // The whole message, not a leading fragment: it is assembled from four
        // concatenated pieces, and matching only the first would let the rest
        // be reordered or dropped without a test noticing.
        $this->assertSame(
            'count() cannot count rows held with NOWAIT. MySQL swallows the abort inside a COUNT and'
                . ' answers with the rows it reached before the held one, so the number looks ordinary and'
                . ' nothing says it is short. Count the rows of get(), or take the lock without NOWAIT.',
            $e->getMessage(),
        );
    }

    public function testCountAcceptsTheOtherLocks(): void
    {
        // Only NOWAIT is refused. The server answers the other three properly,
        // so narrowing the refusal any further would close working combinations.
        $this->seedUsers();

        $grammar = new class () extends Grammar {
            protected function compileLock(?RowLock $lock): CompiledSql
            {
                return new CompiledSql('');
            }
        };

        $this->connection->setGrammar($grammar);

        $this->assertSame(3, $this->connection->select()->from('users')->forUpdate()->count());
        $this->assertSame(3, $this->connection->select()->from('users')->forUpdate(skipLocked: true)->count());
        $this->assertSame(3, $this->connection->select()->from('users')->sharedLock()->count());
    }

    public function testShortcutsOtherThanCountAcceptANoWaitLock(): void
    {
        // Only that the builder does not refuse these; what the servers do with
        // the clause is not reachable from here, because the stub grammar
        // writes no lock and SQLite could not parse one anyway.
        // SelectLockTest pins the server side for get(). All six were measured
        // against both servers and every one reports a held row; the select
        // list count() writes is the only one among the shortcuts that the
        // servers answer with a number instead.
        $this->seedUsers();

        $grammar = new class () extends Grammar {
            protected function compileLock(?RowLock $lock): CompiledSql
            {
                return new CompiledSql('');
            }
        };

        $this->connection->setGrammar($grammar);

        $select = fn (): Select => $this->connection->select()->from('users')->forUpdate(noWait: true);

        $this->assertNotNull($select()->first());
        $this->assertNotNull($select()->value('name'));
        $this->assertNotSame([], $select()->get());
        $this->assertTrue($select()->exists());
        $this->assertFalse($select()->doesntExist());
        $this->assertNotSame([], $select()->pluck('name'));
    }

    public function testTimeoutIsWrittenInWhenTheStatementIsHandedToAConnection(): void
    {
        $handler = $this->attachLogger();

        $this->connection->select('id')->from('users')->timeout(400)->execute();

        $this->assertSame(
            'SELECT /*+ MAX_EXECUTION_TIME(400) */ `id` FROM `users`',
            $this->loggedSql($handler),
        );
    }

    public function testTimeoutIsAbsentFromTheCompiledStatement(): void
    {
        // Which server the statement is going to is not known while it is
        // being written, and the two servers ask for the limit differently,
        // so compiling cannot carry it.
        $select = $this->connection->select('id')->from('users')->timeout(400);

        $this->assertSame('SELECT `id` FROM `users`', $select->toSql());
        $this->assertSame('SELECT `id` FROM `users`', $select->toRawSql());
    }

    public function testTimeoutIsCarriedByTheShortcutsToo(): void
    {
        // The timeout is builder state, as the lock is, so asking for a count
        // or a first row does not drop it.
        $this->seedUsers();

        $handler = $this->attachLogger();
        $select  = fn (): Select => $this->connection->select('id')->from('users')->timeout(400);

        $select()->count();
        $this->assertStringContainsString('MAX_EXECUTION_TIME(400)', $this->loggedSql($handler));

        $select()->first();
        $this->assertStringContainsString('MAX_EXECUTION_TIME(400)', $this->loggedSql($handler));

        $select()->exists();
        $this->assertStringContainsString('MAX_EXECUTION_TIME(400)', $this->loggedSql($handler));

        $select()->pluck('id');
        $this->assertStringContainsString('MAX_EXECUTION_TIME(400)', $this->loggedSql($handler));
    }

    public function testTimeoutKeepsTheLastCountAsked(): void
    {
        $handler = $this->attachLogger();

        $this->connection->select('id')->from('users')->timeout(400)->timeout(900)->execute();

        $this->assertSame(
            'SELECT /*+ MAX_EXECUTION_TIME(900) */ `id` FROM `users`',
            $this->loggedSql($handler),
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function nonPositiveTimeoutProvider(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-400],
        ];
    }

    #[DataProvider('nonPositiveTimeoutProvider')]
    public function testTimeoutRejectsANonPositiveCount(int $ms): void
    {
        // Both servers read a zero as no limit at all, so accepting one would
        // make asking for a timeout the same as asking for none.
        $e = $this->assertThrowsInvalidArgument(
            fn (): Select => $this->connection->select()->from('users')->timeout($ms),
        );

        $this->assertSame(
            'A timeout is a count of milliseconds to run for, so it starts at 1; got ' . $ms
                . '. Both servers read a zero as no limit at all, which is what leaving it unset already says.',
            $e->getMessage(),
        );
    }

    public function testTimeoutAcceptsASingleMillisecond(): void
    {
        // One is the smallest count that means anything, and the boundary is
        // where a limit of none would slip through.
        $handler = $this->attachLogger();

        $this->connection->select('id')->from('users')->timeout(1)->execute();

        $this->assertSame(
            'SELECT /*+ MAX_EXECUTION_TIME(1) */ `id` FROM `users`',
            $this->loggedSql($handler),
        );
    }

    public function testChunkWalksEveryMatchingRow(): void
    {
        $this->seedNumberedUsers(7);

        $finished = $this->connection->select()->from('users')->chunk(3, $this->record(...));

        $this->assertTrue($finished);
        $this->assertSame('|0:1,2,3|1:4,5,6|2:7', $this->walked);
    }

    public function testChunkMovesPastEachBatchRatherThanReadingTheFirstAgain(): void
    {
        // A walk that does not advance reads the same rows for ever. Stopping
        // after three batches turns that into ids an assertion can compare,
        // rather than a statement that never stops going out.
        $this->seedNumberedUsers(9);

        $this->connection->select()->from('users')->chunk(3, function (Result $batch, int $index): bool {
            $this->record($batch, $index);

            return substr_count($this->walked, '|') < 3;
        });

        $this->assertSame('|0:1,2,3|1:4,5,6|2:7,8,9', $this->walked);
    }

    public function testChunkStopsWhereTheCallbackSaysSo(): void
    {
        $this->seedNumberedUsers(7);

        $finished = $this->connection->select()->from('users')->chunk(3, function (Result $batch, int $index): bool {
            $this->record($batch, $index);

            return false;
        });

        $this->assertFalse($finished);
        $this->assertSame('|0:1,2,3', $this->walked);
    }

    public function testChunkStopsAskingOnceABatchComesBackShort(): void
    {
        // A batch smaller than the size means the server had nothing more to
        // give, so the walk ends there. Without that, seven rows in threes
        // would cost a fourth statement to learn what the third already said.
        $this->seedNumberedUsers(7);
        $handler = $this->attachLogger();

        $this->connection->select()->from('users')->chunk(3, $this->record(...));

        $this->assertSame('|0:1,2,3|1:4,5,6|2:7', $this->walked);
        $this->assertCount(3, $handler->getRecords());
    }

    public function testChunkByIdStopsAskingOnceABatchComesBackShort(): void
    {
        // As with chunk(): a batch smaller than the size means the server had
        // nothing more above the value reached, so the walk ends there rather
        // than spending a fourth statement to be told the same thing.
        $this->seedNumberedUsers(7);
        $handler = $this->attachLogger();

        $this->connection->select()->from('users')->chunkById(3, $this->record(...));

        $this->assertSame('|0:1,2,3|1:4,5,6|2:7', $this->walked);
        $this->assertCount(3, $handler->getRecords());
    }

    public function testChunkOverNothingNeverReachesTheCallback(): void
    {
        $finished = $this->connection->select()->from('users')->chunk(3, $this->record(...));

        $this->assertTrue($finished);
        $this->assertSame('', $this->walked);
    }

    public function testChunkWalksOnlyTheRowsTheConditionsMatch(): void
    {
        $this->seedNumberedUsers(4);
        $this->connection->statement('UPDATE users SET status = \'blocked\' WHERE id IN (2, 4)');

        $this->connection->select()->from('users')->where('status', 'active')->chunk(2, $this->record(...));

        $this->assertSame('|0:1,3', $this->walked);
    }

    public function testChunkByIdWalksEveryMatchingRow(): void
    {
        $this->seedNumberedUsers(7);

        $finished = $this->connection->select()->from('users')->chunkById(3, $this->record(...));

        $this->assertTrue($finished);
        $this->assertSame('|0:1,2,3|1:4,5,6|2:7', $this->walked);
    }

    public function testChunkByIdMovesPastEachBatchRatherThanReadingTheFirstAgain(): void
    {
        // As with chunk(), a walk that does not carry the highest value forward
        // reads the same rows for ever; stopping after three batches makes that
        // an assertion rather than a statement that never stops going out.
        $this->seedNumberedUsers(9);

        $this->connection->select()->from('users')->chunkById(3, function (Result $batch, int $index): bool {
            $this->record($batch, $index);

            return substr_count($this->walked, '|') < 3;
        });

        $this->assertSame('|0:1,2,3|1:4,5,6|2:7,8,9', $this->walked);
    }

    public function testChunkByIdKeepsAnOrConditionTogether(): void
    {
        // The walk requires `id > ?` alongside whatever the builder collected.
        // Appended to the end it would sit beside the last condition, and AND
        // binds tighter than OR, so `a OR b` would come back meaning
        // `a OR (b AND id > ?)` and the walk would keep answering with a.
        $this->seedNumberedUsers(5);

        // Stopping after three batches keeps a walk that cannot advance from
        // running for ever: `id = 1 OR (id = 2 AND id > 1)` matches row 1 on
        // every pass, so without a bound the failure would be a statement that
        // never stops rather than an assertion.
        $this->connection->select()->from('users')
            ->where('id', 1)->orWhere('id', 2)
            ->chunkById(1, function (Result $batch, int $index): bool {
                $this->record($batch, $index);

                return substr_count($this->walked, '|') < 3;
            });

        $this->assertSame('|0:1|1:2', $this->walked);
    }

    public function testChunkByIdStopsWhereTheCallbackSaysSo(): void
    {
        $this->seedNumberedUsers(7);

        $finished = $this->connection->select()->from('users')
            ->chunkById(3, function (Result $batch, int $index): bool {
                $this->record($batch, $index);

                return false;
            });

        $this->assertFalse($finished);
        $this->assertSame('|0:1,2,3', $this->walked);
    }

    public function testChunkByIdWalksByAColumnWrittenWithItsTable(): void
    {
        $this->seedNumberedUsers(4);

        $this->connection->select()->from('users')->chunkById(2, $this->record(...), 'users.id');

        $this->assertSame('|0:1,2|1:3,4', $this->walked);
    }

    public function testChunkByIdRefusesAColumnThatWasNotReadBeforeHandingOverABatch(): void
    {
        // The column is missing from every batch, so the walk cannot go on. The
        // callback is where writing and sending happen, and running it once for
        // a walk that is about to fail leaves that work behind.
        $this->seedNumberedUsers(2);

        $this->assertThrows(
            UnexpectedValueException::class,
            fn () => $this->connection->select('name')->from('users')->chunkById(1, $this->record(...)),
        );

        $this->assertSame('', $this->walked);
    }

    public function testChunkByIdRefusesAColumnThatWasNotRead(): void
    {
        $this->seedNumberedUsers(2);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains(
            'chunkById() walks by "id", which is not among the columns read (name). Select it, or walk'
                . ' by one of them.',
        );

        $this->connection->select('name')->from('users')->chunkById(1, static function (): void {
        });
    }

    public function testChunkByIdRefusesAWalkedColumnWithNoValueInIt(): void
    {
        // Nothing compares above null, so the next batch would come back empty
        // and every row after this one would go unwalked.
        $this->seedNumberedUsers(2);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains(
            'chunkById() walks by "nickname", and a row came back with no value in it. Nothing compares'
                . ' above null, so the walk would stop there and leave the rest unseen. Walk by a column'
                . ' that holds a value per row.',
        );

        $this->connection->select('id', 'nickname')->from('users')->chunkById(1, static function (): void {
        }, 'nickname');
    }

    public function testChunkByIdIsRefusedWhenTheBuilderAlreadySorts(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'chunkById() sorts by "id" to walk the rows, so a sort already on the builder would either'
                . ' come first and break the walk, or come second and never be reached. Drop the orderBy()'
                . ' call, or walk the rows with chunk().',
        );

        $this->connection->select()->from('users')->orderBy('name')->chunkById(2, static function (): void {
        });
    }

    /**
     * @return array<string, array{callable(Select): Select, string}>
     */
    public static function provideWalksOverAnAddressedSliceOfTheRows(): array
    {
        return [
            'chunk after limit'      => [static fn (Select $q): Select => $q->limit(5), 'chunk'],
            'chunk after offset'     => [static fn (Select $q): Select => $q->offset(5), 'chunk'],
            'chunkById after limit'  => [static fn (Select $q): Select => $q->limit(5), 'chunkById'],
            'chunkById after offset' => [static fn (Select $q): Select => $q->offset(5), 'chunkById'],
        ];
    }

    #[DataProvider('provideWalksOverAnAddressedSliceOfTheRows')]
    public function testAWalkIsRefusedWhenARowWindowIsAlreadySet(callable $window, string $walk): void
    {
        $select = $window($this->connection->select()->from('users'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            $walk . '() addresses the rows a batch at a time, so it sets the limit and offset itself and'
                . ' cannot keep the ones already on this builder. Drop the limit()/offset() call, or read'
                . ' that slice on its own with execute().',
        );

        $select->{$walk}(2, static function (): void {
        });
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideWalkNames(): array
    {
        return ['chunk' => ['chunk'], 'chunkById' => ['chunkById']];
    }

    #[DataProvider('provideWalkNames')]
    public function testAWalkRefusesABatchThatCouldNotCarryARow(string $walk): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Batch size must be at least 1, got 0.');

        $this->connection->select()->from('users')->{$walk}(0, static function (): void {
        });
    }

    public function testPaginateReadsTheRequestedPageAndCountsEveryMatch(): void
    {
        $this->seedNumberedUsers(7);

        $page = $this->connection->select()->from('users')->paginate(3, 2);

        $this->assertSame('4,5,6', self::idsOf($page->items));
        $this->assertSame(7, $page->total);
        $this->assertSame(3, $page->perPage);
        $this->assertSame(2, $page->currentPage);
        $this->assertSame(3, $page->lastPage);
    }

    public function testPaginateCountsWhatTheConditionsMatchRatherThanTheWholeTable(): void
    {
        $this->seedNumberedUsers(6);
        $this->connection->statement('UPDATE users SET status = \'blocked\' WHERE id IN (2, 4, 6)');

        $page = $this->connection->select()->from('users')->where('status', 'active')->paginate(2, 1);

        $this->assertSame('1,3', self::idsOf($page->items));
        $this->assertSame(3, $page->total);
    }

    public function testPaginatePastTheLastPageReadsNothingButStillCounts(): void
    {
        $this->seedNumberedUsers(3);

        $page = $this->connection->select()->from('users')->paginate(2, 9);

        $this->assertTrue($page->items->isEmpty());
        $this->assertSame(3, $page->total);
        $this->assertSame(2, $page->lastPage);
        $this->assertFalse($page->hasMorePages);
    }

    public function testAPageOfOneRowIsRead(): void
    {
        // One is the smallest page there is, and the boundary the refusal of
        // nought sits against.
        $this->seedNumberedUsers(3);

        $page = $this->connection->select()->from('users')->paginate(1, 2);

        $this->assertSame('2', self::idsOf($page->items));
        $this->assertSame(3, $page->total);
        $this->assertSame(3, $page->lastPage);
    }

    public function testAWalkOverRowsThatDivideEvenlyEndsOnAnEmptyBatch(): void
    {
        // Six rows in threes leave no short batch, so the walk learns it is
        // done from a statement that comes back empty. That is a different way
        // out of the loop than the one every other walk here takes.
        $this->seedNumberedUsers(6);

        $finished = $this->connection->select()->from('users')->chunkById(3, $this->record(...));

        $this->assertTrue($finished);
        $this->assertSame('|0:1,2,3|1:4,5,6', $this->walked);
    }

    public function testPaginateRefusesRowsHeldWithNoWaitBeforeReadingThePage(): void
    {
        // The refusal has to come before the page goes out: on a server that
        // takes the lock, a page read first would leave the caller holding rows
        // for a statement that then failed.
        $this->seedNumberedUsers(3);
        $handler = $this->attachLogger();

        $e = $this->assertThrows(
            LogicException::class,
            fn () => $this->connection->select()->from('users')->forUpdate(noWait: true)->paginate(2, 1),
        );

        $this->assertSame(
            'paginate() cannot count rows held with NOWAIT. MySQL swallows the abort inside a COUNT and'
                . ' answers with the rows it reached before the held one, so the number looks ordinary and'
                . ' nothing says it is short. Count the rows of get(), or take the lock without NOWAIT.',
            $e->getMessage(),
        );
        $this->assertSame([], $handler->getRecords());
    }

    public function testPaginateIsRefusedWhenARowWindowIsAlreadySet(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'paginate() addresses the rows a batch at a time, so it sets the limit and offset itself and'
                . ' cannot keep the ones already on this builder. Drop the limit()/offset() call, or read'
                . ' that slice on its own with execute().',
        );

        $this->connection->select()->from('users')->limit(5)->paginate(2, 1);
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function providePagesThatCannotBeRead(): array
    {
        return [
            'no rows per page' => [0, 1, 'paginate() reads at least one row per page, got 0.'],
            'a page below one' => [2, 0, 'paginate() counts pages from 1, got 0.'],
        ];
    }

    #[DataProvider('providePagesThatCannotBeRead')]
    public function testPagesThatCannotBeReadAreRefused(int $perPage, int $page, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->connection->select()->from('users')->paginate($perPage, $page);
    }
}
