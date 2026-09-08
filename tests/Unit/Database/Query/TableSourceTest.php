<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\SubQuery;
use Sloop\Database\Query\TableSource;
use Sloop\Tests\Support\ThrowsAssertions;

final class TableSourceTest extends TestCase
{
    use ThrowsAssertions;

    private Connection $connection;

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->connection = new Connection($sqlite, 'table_source_test');
    }

    public function testKeepsTheTableAsGiven(): void
    {
        $this->assertSame('users', new TableSource('users')->source);
    }

    public function testRefersToTheRowsByTheTableNameUnlessAnAliasIsGiven(): void
    {
        $this->assertNull(new TableSource('users')->alias);
    }

    public function testKeepsTheAliasAsGiven(): void
    {
        $this->assertSame('u', new TableSource('users', 'u')->alias);
    }

    public function testKeepsAStatementAsGiven(): void
    {
        $query = new SubQuery($this->connection->select('id')->from('users'));

        $this->assertSame($query, new TableSource($query, 'sub')->source);
    }

    public function testAStatementReadAsATableNeedsAnAlias(): void
    {
        $query = new SubQuery($this->connection->select('id')->from('users'));

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): TableSource => new TableSource($query),
        );

        $this->assertSame(
            'A statement read as a table needs an alias, because its rows have no name of their own.',
            $thrown->getMessage(),
        );
    }

    public function testAQualifiedNameCannotStandAsAnAlias(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): TableSource => new TableSource('users', 'reporting.u'),
        );

        $this->assertSame('An alias is one name, so it cannot be qualified, got reporting.u.', $thrown->getMessage());
    }

    public function testAnEmptyAliasIsRefusedAsAnIdentifierWouldBe(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): TableSource => new TableSource('users', ''),
        );

        $this->assertSame('Identifier must not contain an empty segment, got .', $thrown->getMessage());
    }
}
