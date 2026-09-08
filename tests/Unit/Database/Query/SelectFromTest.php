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

final class SelectFromTest extends TestCase
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
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL, score INTEGER NOT NULL)');
        $sqlite->exec("INSERT INTO users (id, name, status, score) VALUES (1, 'alice', 'active', 30),"
            . " (2, 'bob', 'active', 10), (3, 'carol', 'blocked', 20)");

        $this->connection = new Connection($sqlite, 'from_test');
    }

    private function activeUsers(): Select
    {
        return $this->connection->select('id', 'score')->from('users')->where('status', 'active');
    }

    public function testAStatementStandsWhereATableNameWould(): void
    {
        $select = $this->connection->select('id')->from($this->activeUsers(), 'sub');

        $this->assertSame(
            'SELECT `id` FROM (SELECT `id`, `score` FROM `users` WHERE `status` = ?) AS `sub`',
            $select->toSql(),
        );
    }

    public function testTheStatementCarriesItsOwnBindings(): void
    {
        $select = $this->connection->select('id')->from($this->activeUsers(), 'sub');

        $this->assertSame(['active'], $select->toBindings());
    }

    public function testTheBindingsOfTheFromClauseStandBeforeTheOnesOfTheWhereClause(): void
    {
        // The placeholders are read left to right, and FROM is written before
        // WHERE, so the values of a statement read as a table belong between
        // the ones of the select list and the ones of the conditions.
        $select = $this->connection->select('id', Expression::of('? AS tag', ['t']))
            ->from($this->activeUsers(), 'sub')
            ->where('score', '>', 15);

        $this->assertSame(
            'SELECT `id`, ? AS tag FROM (SELECT `id`, `score` FROM `users` WHERE `status` = ?) AS `sub`'
            . ' WHERE `score` > ?',
            $select->toSql(),
        );
        $this->assertSame(['t', 'active', 15], $select->toBindings());
    }

    public function testTheBindingsOfTheFromClauseStandBeforeTheOnesOfTheJoin(): void
    {
        // FROM sits between the select list and the joins, and the ON clause is
        // the only other slot next to it that carries values of its own. Moving
        // the FROM bindings past the joins in the merge leaves the test above
        // green, because it has no join.
        $select = $this->connection->select('id')
            ->from($this->activeUsers(), 'sub')
            ->join('logins')
            ->on('logins.user_id', '=', Expression::of('?', [7]))
            ->where('score', '>', 15);

        $this->assertSame(['active', 7, 15], $select->toBindings());
    }

    public function testTheStatementIsReadWhenTheOuterStatementIsCompiled(): void
    {
        $inner  = $this->activeUsers();
        $select = $this->connection->select('id')->from($inner, 'sub');

        $inner->where('score', '>', 15);

        $this->assertSame(
            'SELECT `id` FROM (SELECT `id`, `score` FROM `users` WHERE `status` = ? AND `score` > ?) AS `sub`',
            $select->toSql(),
        );
        $this->assertSame(['active', 15], $select->toBindings());
    }

    public function testAStatementReadAsATableMayHoldOneOfItsOwn(): void
    {
        $inner  = $this->connection->select('id')->from($this->activeUsers(), 'inner');
        $select = $this->connection->select('id')->from($inner, 'outer');

        $this->assertSame(
            'SELECT `id` FROM (SELECT `id` FROM (SELECT `id`, `score` FROM `users` WHERE `status` = ?)'
            . ' AS `inner`) AS `outer`',
            $select->toSql(),
        );
    }

    public function testAStatementReadAsATableIsRefusedWithoutAnAlias(): void
    {
        $select = $this->connection->select('id');

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): Select => $select->from($this->activeUsers()),
        );

        $this->assertSame(
            'A statement read as a table needs an alias, because its rows have no name of their own.',
            $thrown->getMessage(),
        );
    }

    public function testAStatementThatNamesNoTableIsRefusedWhenTheOuterStatementCompiles(): void
    {
        $select = $this->connection->select('id')->from($this->connection->select('id'), 'sub');

        $thrown = $this->assertThrows(LogicException::class, $select->toSql(...));

        $this->assertSame(
            'A SELECT reads from a table; call from() before compiling the statement.',
            $thrown->getMessage(),
        );
    }

    public function testATableNameTakesAnAliasToo(): void
    {
        $select = $this->connection->select('u.id')->from('users', 'u')->where('u.status', 'active');

        $this->assertSame('SELECT `u`.`id` FROM `users` AS `u` WHERE `u`.`status` = ?', $select->toSql());
    }

    public function testTheLastFromCallIsTheOneThatCounts(): void
    {
        $select = $this->connection->select('id')->from($this->activeUsers(), 'sub')->from('users');

        $this->assertSame('SELECT `id` FROM `users`', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testAnAliasCarriesTheTablePrefixSoQualifiedColumnsReachIt(): void
    {
        // A qualified column gets the prefix on its table segment, and an
        // alias stands in that segment. Prefixing the alias where FROM
        // introduces it is what keeps the two spellings the same.
        $this->connection->setGrammar(new Grammar('app_'));

        $select = $this->connection->select('sub.id')
            ->from($this->connection->select('id')->from('users'), 'sub')
            ->where('sub.id', '>', 1);

        $this->assertSame(
            'SELECT `app_sub`.`id` FROM (SELECT `id` FROM `app_users`) AS `app_sub` WHERE `app_sub`.`id` > ?',
            $select->toSql(),
        );
    }

    public function testTheAliasOfATableNameCarriesThePrefixAsWell(): void
    {
        $this->connection->setGrammar(new Grammar('app_'));

        $select = $this->connection->select('u.id')->from('users', 'u');

        $this->assertSame('SELECT `app_u`.`id` FROM `app_users` AS `app_u`', $select->toSql());
    }

    public function testReadsTheRowsTheStatementReturns(): void
    {
        $ids = $this->connection->select('id')
            ->from($this->activeUsers(), 'sub')
            ->where('score', '>', 15)
            ->pluck('id');

        $this->assertSame([1], $ids);
    }
}
