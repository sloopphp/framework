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
use Sloop\Tests\Support\ThrowsAssertions;

final class SelectJoinTest extends TestCase
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

        $this->connection = new Connection($sqlite, 'join_test');
    }

    private function select(): Select
    {
        return $this->connection->select('users.id', 'posts.title')->from('users');
    }

    public function testJoinWritesAnInnerJoinWithItsOnClause(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id');

        $this->assertSame(
            'SELECT `users`.`id`, `posts`.`title` FROM `users` JOIN `posts` ON `posts`.`user_id` = `users`.`id`',
            $select->toSql(),
        );
    }

    public function testLeftJoinAndRightJoinWriteTheirOwnKeyword(): void
    {
        $left  = $this->select()->leftJoin('posts')->on('posts.user_id', '=', 'users.id');
        $right = $this->select()->rightJoin('posts')->on('posts.user_id', '=', 'users.id');

        $this->assertStringContainsString('LEFT JOIN `posts` ON', $left->toSql());
        $this->assertStringContainsString('RIGHT JOIN `posts` ON', $right->toSql());
    }

    public function testOnTakesTwoArgumentsAsAnEqualityComparison(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', 'users.id');

        $this->assertStringContainsString('ON `posts`.`user_id` = `users`.`id`', $select->toSql());
    }

    public function testAndOnAndOrOnJoinTheConditionsTheyAdd(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->andOn('posts.published', '=', 'users.score')
            ->orOn('posts.id', '=', 'users.id');

        $this->assertStringContainsString(
            'ON `posts`.`user_id` = `users`.`id` AND `posts`.`published` = `users`.`score`'
            . ' OR `posts`.`id` = `users`.`id`',
            $select->toSql(),
        );
    }

    public function testOnGroupsAreParenthesised(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->orOnOpen()
            ->on('posts.published', '=', 'users.score')
            ->andOn('posts.id', '=', 'users.id')
            ->onClose();

        $this->assertStringContainsString(
            'ON `posts`.`user_id` = `users`.`id` OR (`posts`.`published` = `users`.`score`'
            . ' AND `posts`.`id` = `users`.`id`)',
            $select->toSql(),
        );
    }

    public function testAndOnOpenAndTheCloseSpellingsReadTheSameAsTheirBaseForm(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->andOnOpen()
            ->on('posts.published', '=', 'users.score')
            ->andOnClose()
            ->onOpen()
            ->on('posts.id', '=', 'users.id')
            ->orOnClose();

        $this->assertStringContainsString(
            'ON `posts`.`user_id` = `users`.`id` AND (`posts`.`published` = `users`.`score`)'
            . ' AND (`posts`.`id` = `users`.`id`)',
            $select->toSql(),
        );
    }

    public function testSeveralJoinsAreWrittenInTheOrderTheyWereAdded(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->leftJoin('comments')
            ->on('comments.post_id', '=', 'posts.id');

        $this->assertSame(
            'SELECT `users`.`id`, `posts`.`title` FROM `users`'
            . ' JOIN `posts` ON `posts`.`user_id` = `users`.`id`'
            . ' LEFT JOIN `comments` ON `comments`.`post_id` = `posts`.`id`',
            $select->toSql(),
        );
    }

    public function testANewJoinTakesTheConditionsAddedAfterIt(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->leftJoin('comments')
            ->on('comments.post_id', '=', 'posts.id')
            ->andOn('comments.user_id', '=', 'users.id');

        $this->assertStringContainsString(
            'JOIN `posts` ON `posts`.`user_id` = `users`.`id`'
            . ' LEFT JOIN `comments` ON `comments`.`post_id` = `posts`.`id`'
            . ' AND `comments`.`user_id` = `users`.`id`',
            $select->toSql(),
        );
    }

    public function testJoinsAreWrittenBetweenTheTableAndTheConditions(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->where('users.status', 'active');

        $this->assertSame(
            'SELECT `users`.`id`, `posts`.`title` FROM `users`'
            . ' JOIN `posts` ON `posts`.`user_id` = `users`.`id`'
            . ' WHERE `users`.`status` = ?',
            $select->toSql(),
        );
        $this->assertSame(['active'], $select->toBindings());
    }

    public function testAnExpressionStandsWhereEitherSideOfAnOnConditionDoes(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on(Expression::of('LOWER(`posts`.`title`)'), '=', Expression::of('?', ['sloop']));

        $this->assertStringContainsString('ON LOWER(`posts`.`title`) = ?', $select->toSql());
        $this->assertSame(['sloop'], $select->toBindings());
    }

    public function testOnBindingsComeBeforeTheConditionsOfTheStatement(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', Expression::of('?', [7]))
            ->where('users.status', 'active');

        $this->assertSame([7, 'active'], $select->toBindings());
    }

    public function testOnWithoutAJoinIsRefused(): void
    {
        $select = $this->select();

        $exception = $this->assertThrows(LogicException::class, static fn () => $select->on('a.id', '=', 'b.id'));

        $this->assertSame(
            'An ON condition belongs to a join, so call join() before on().',
            $exception->getMessage(),
        );
    }

    public function testOnOpenWithoutAJoinIsRefused(): void
    {
        $select = $this->select();

        $this->assertThrows(LogicException::class, static fn () => $select->onOpen());
    }

    public function testAJoinWithoutAnOnConditionIsRefused(): void
    {
        $select = $this->select()->join('posts');

        $exception = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertSame(
            'The join on `posts` carries no ON condition, so it would pair every row with every other.'
            . ' Add one with on(), or write the cross join as a raw statement.',
            $exception->getMessage(),
        );
    }

    public function testAGroupThatOnlyOpenedAndClosedLeavesTheJoinWithoutACondition(): void
    {
        $select = $this->select()->join('posts')->onOpen()->onClose();

        $this->assertThrows(LogicException::class, static fn () => $select->toSql());
    }

    public function testAnOnGroupLeftOpenIsRefusedWhenTheStatementIsCompiled(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->onOpen()
            ->on('posts.published', '=', 'users.score');

        $exception = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertSame(
            'A group of ON conditions was opened and not closed; call onClose() 1 more time.',
            $exception->getMessage(),
        );
    }

    public function testAnOnGroupLeftOpenIsRefusedWhenTheNextJoinStarts(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->onOpen();

        $exception = $this->assertThrows(LogicException::class, static fn () => $select->leftJoin('comments'));

        $this->assertSame(
            'A group of ON conditions was opened and not closed; call onClose() 1 more time.',
            $exception->getMessage(),
        );
    }

    public function testTwoOnGroupsLeftOpenAreCountedInTheMessage(): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id')
            ->onOpen()
            ->onOpen();

        $exception = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertSame(
            'A group of ON conditions was opened and not closed; call onClose() 2 more times.',
            $exception->getMessage(),
        );
    }

    public function testClosingAnOnGroupThatWasNeverOpenedIsRefused(): void
    {
        $select = $this->select()->join('posts')->on('posts.user_id', '=', 'users.id');

        $exception = $this->assertThrows(LogicException::class, static fn () => $select->onClose());

        $this->assertSame(
            'No group of ON conditions is open, so there is nothing to close.',
            $exception->getMessage(),
        );
    }

    public function testAnOperatorTestingAgainstAKeywordIsRefusedOnAJoin(): void
    {
        $select = $this->select()->join('posts');

        $exception = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => $select->on('posts.user_id', 'IS', 'users.id'),
        );

        $this->assertSame(
            'IS tests against a keyword, so it compares a column against NULL, TRUE or FALSE rather than'
            . ' against another column; it has no meaning in an ON clause.',
            $exception->getMessage(),
        );
    }

    public function testAnExpressionCannotStandWhereTheOperatorGoes(): void
    {
        $select = $this->select()->join('posts');

        $exception = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => $select->on('posts.user_id', Expression::of('='), 'users.id'),
        );

        $this->assertSame(
            'A comparison operator must be a string, got Sloop\\Database\\Query\\Expression.',
            $exception->getMessage(),
        );
    }

    public function testAnOnConditionWithoutARightHandSideIsRefused(): void
    {
        $select = $this->select()->join('posts');

        $exception = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => $select->on('posts.user_id', '=', null),
        );

        $this->assertSame(
            'An ON condition compares two columns, so the right-hand side cannot be null.'
            . ' Write a column, or an Expression carrying the value it stands for.',
            $exception->getMessage(),
        );
    }

    public function testAnUnsupportedOperatorIsRefusedOnAJoin(): void
    {
        $select = $this->select()->join('posts');

        $exception = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => $select->on('posts.user_id', '=>', 'users.id'),
        );

        $this->assertSame('Unsupported comparison operator "=>".', $exception->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function joinComparisonOperatorProvider(): array
    {
        return [
            'equal'         => ['='],
            'not equal'     => ['!='],
            'less than'     => ['<'],
            'null safe'     => ['<=>'],
            'like'          => ['LIKE'],
            'lower cased'   => ['like'],
        ];
    }

    #[DataProvider('joinComparisonOperatorProvider')]
    public function testAnOperatorComparingTwoColumnsIsWrittenUpperCased(string $operator): void
    {
        $select = $this->select()
            ->join('posts')
            ->on('posts.user_id', $operator, 'users.id');

        $this->assertStringContainsString(
            'ON `posts`.`user_id` ' . strtoupper($operator) . ' `users`.`id`',
            $select->toSql(),
        );
    }

    public function testTheTablePrefixReachesBothTheJoinedTableAndItsColumns(): void
    {
        $this->connection->setGrammar(new Grammar('wp_'));

        $select = $this->connection->select('users.id')
            ->from('users')
            ->join('posts')
            ->on('posts.user_id', '=', 'users.id');

        $this->assertSame(
            'SELECT `wp_users`.`id` FROM `wp_users` JOIN `wp_posts` ON `wp_posts`.`user_id` = `wp_users`.`id`',
            $select->toSql(),
        );
    }
}
