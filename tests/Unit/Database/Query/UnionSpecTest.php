<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Order;
use Sloop\Database\Query\SelectSpec;
use Sloop\Database\Query\SubQuery;
use Sloop\Database\Query\Union;
use Sloop\Database\Query\UnionSpec;
use Sloop\Tests\Support\ThrowsAssertions;

final class UnionSpecTest extends TestCase
{
    use ThrowsAssertions;

    private function union(): Union
    {
        $connection = new Connection(new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]), 'union_spec_test');

        return new Union(new SubQuery($connection->select('id')->from('admins')), false);
    }

    public function testTheCombinedSortAndWindowAreEmptyByDefault(): void
    {
        $first = new SelectSpec(from: 'users');
        $union = $this->union();

        $spec = new UnionSpec($first, [$union]);

        $this->assertSame($first, $spec->first);
        $this->assertSame([$union], $spec->unions);
        $this->assertSame([], $spec->orders);
        $this->assertNull($spec->limit);
        $this->assertNull($spec->offset);
    }

    public function testUnionsAreReindexedAsAList(): void
    {
        $first  = $this->union();
        $second = $this->union();

        $spec = new UnionSpec(new SelectSpec(from: 'users'), [4 => $first, 9 => $second]);

        $this->assertSame([$first, $second], $spec->unions);
    }

    public function testOrdersAreReindexedAsAList(): void
    {
        $order = new Order('id');

        $spec = new UnionSpec(new SelectSpec(from: 'users'), [$this->union()], orders: [3 => $order]);

        $this->assertSame([$order], $spec->orders);
    }

    public function testAStatementWithNothingAddedToItIsRefused(): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => new UnionSpec(new SelectSpec(from: 'users'), []),
        );

        $this->assertSame('A combined statement needs at least one statement to add.', $e->getMessage());
    }

    public function testAUnionOfTheWrongTypeIsRefusedAtItsPosition(): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new UnionSpec(new SelectSpec(from: 'users'), [5 => $this->union(), 8 => 'admins']),
        );

        $this->assertSame('Unions must be a Union, got string at index 1.', $e->getMessage());
    }

    public function testAnOrderOfTheWrongTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UnionSpec(new SelectSpec(from: 'users'), [$this->union()], orders: ['id']);
    }

    public function testANegativeLimitIsRefused(): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new UnionSpec(new SelectSpec(from: 'users'), [$this->union()], limit: -1),
        );

        $this->assertSame('Limit must not be negative, got -1.', $e->getMessage());
    }

    public function testANegativeOffsetIsRefused(): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new UnionSpec(new SelectSpec(from: 'users'), [$this->union()], limit: 5, offset: -1),
        );

        $this->assertSame('Offset must not be negative, got -1.', $e->getMessage());
    }

    public function testAZeroLimitAndOffsetAreAccepted(): void
    {
        $spec = new UnionSpec(new SelectSpec(from: 'users'), [$this->union()], limit: 0, offset: 0);

        $this->assertSame(0, $spec->limit);
        $this->assertSame(0, $spec->offset);
    }

    public function testAnOffsetWithoutALimitIsRefused(): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new UnionSpec(new SelectSpec(from: 'users'), [$this->union()], offset: 5),
        );

        $this->assertSame('An offset needs a limit, because MySQL has no OFFSET without LIMIT.', $e->getMessage());
    }
}
