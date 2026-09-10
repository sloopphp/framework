<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\SelectedColumn;
use Sloop\Database\Query\SubQuery;
use Sloop\Tests\Support\ThrowsAssertions;

final class SelectedColumnTest extends TestCase
{
    use ThrowsAssertions;

    private Connection $connection;

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->connection = new Connection($sqlite, 'selected_column_test');
    }

    private function orderCount(): SubQuery
    {
        return new SubQuery($this->connection->select(Expression::of('COUNT(*)'))->from('orders'));
    }

    public function testKeepsTheColumnAsGiven(): void
    {
        $this->assertSame('name', new SelectedColumn('name', 'n')->source);
    }

    public function testKeepsTheNameAsGiven(): void
    {
        $this->assertSame('n', new SelectedColumn('name', 'n')->alias);
    }

    public function testKeepsAnExpressionAsGiven(): void
    {
        $total = Expression::of('COUNT(*)');

        $this->assertSame($total, new SelectedColumn($total, 'total')->source);
    }

    public function testKeepsAStatementAsGiven(): void
    {
        $count = $this->orderCount();

        $this->assertSame($count, new SelectedColumn($count, 'order_count')->source);
    }

    public function testAQualifiedNameCannotStandAsAName(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): SelectedColumn => new SelectedColumn('name', 'users.n'),
        );

        $this->assertSame('An alias is one name, so it cannot be qualified, got users.n.', $thrown->getMessage());
    }

    public function testAnEmptyNameIsRefusedAsAnIdentifierWouldBe(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): SelectedColumn => new SelectedColumn('name', ''),
        );

        $this->assertSame('Identifier must not contain an empty segment, got .', $thrown->getMessage());
    }
}
