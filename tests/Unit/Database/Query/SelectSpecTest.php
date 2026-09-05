<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\Condition;
use Sloop\Database\Query\Join;
use Sloop\Database\Query\JoinType;
use Sloop\Database\Query\Order;
use Sloop\Database\Query\RowLock;
use Sloop\Database\Query\SelectSpec;

final class SelectSpecTest extends TestCase
{
    public function testEmptyClausesAreTheDefault(): void
    {
        $spec = new SelectSpec(from: 'users');

        $this->assertSame('users', $spec->from);
        $this->assertSame([], $spec->columns);
        $this->assertSame([], $spec->joins);
        $this->assertSame([], $spec->conditions);
        $this->assertSame([], $spec->orders);
        $this->assertNull($spec->limit);
        $this->assertNull($spec->offset);
        $this->assertNull($spec->lock);
    }

    public function testColumnsAreReindexedAsAList(): void
    {
        $spec = new SelectSpec(from: 'users', columns: [3 => 'id', 7 => 'name']);

        $this->assertSame(['id', 'name'], $spec->columns);
    }

    public function testConditionsAreReindexedAsAList(): void
    {
        $condition = new Condition('id', '=', 1);

        $spec = new SelectSpec(from: 'users', conditions: [5 => $condition]);

        $this->assertSame([$condition], $spec->conditions);
    }

    public function testJoinsAreReindexedAsAList(): void
    {
        $join = new Join(JoinType::Left, 'posts');

        $spec = new SelectSpec(from: 'users', joins: [9 => $join]);

        $this->assertSame([$join], $spec->joins);
    }

    public function testAJoinsArrayHoldingSomethingElseIsRefused(): void
    {
        try {
            new SelectSpec(from: 'users', joins: ['posts']);
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Joins must be a Join, got string at index 0.', $e->getMessage());

            return;
        }

        $this->fail('Expected an InvalidArgumentException, none was thrown.');
    }

    public function testOrdersAreReindexedAsAList(): void
    {
        $order = new Order('id');

        $spec = new SelectSpec(from: 'users', orders: [5 => $order]);

        $this->assertSame([$order], $spec->orders);
    }

    public function testRejectsAColumnThatIsNeitherStringNorExpression(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Columns must be a string or an Expression, got int at index 1.');

        new SelectSpec(from: 'users', columns: ['id', 42]);
    }

    public function testRejectsAConditionOfTheWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Conditions must be a WherePart, got string at index 0.');

        new SelectSpec(from: 'users', conditions: ['id = 1']);
    }

    public function testRejectsAnOrderOfTheWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Orders must be an Order, got string at index 0.');

        new SelectSpec(from: 'users', orders: ['id ASC']);
    }

    public function testRejectsANegativeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Limit must not be negative, got -1.');

        new SelectSpec(from: 'users', limit: -1);
    }

    public function testRejectsANegativeOffset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Offset must not be negative, got -5.');

        new SelectSpec(from: 'users', limit: 10, offset: -5);
    }

    public function testAcceptsAZeroLimit(): void
    {
        $this->assertSame(0, (new SelectSpec(from: 'users', limit: 0))->limit);
    }

    public function testAcceptsAZeroOffset(): void
    {
        $this->assertSame(0, new SelectSpec(from: 'users', limit: 10, offset: 0)->offset);
    }

    public function testReportsTheColumnPositionNotTheOriginalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Columns must be a string or an Expression, got int at index 1.');

        new SelectSpec(from: 'users', columns: [5 => 'id', 9 => 42]);
    }

    public function testReportsTheConditionPositionNotTheOriginalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Conditions must be a WherePart, got string at index 1.');

        new SelectSpec(from: 'users', conditions: [5 => new Condition('id', '=', 1), 9 => 'id = 1']);
    }

    public function testReportsTheJoinPositionNotTheOriginalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Joins must be a Join, got string at index 1.');

        new SelectSpec(from: 'users', joins: [5 => new Join(JoinType::Inner, 'posts'), 9 => 'posts']);
    }

    public function testReportsTheOrderPositionNotTheOriginalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Orders must be an Order, got string at index 1.');

        new SelectSpec(from: 'users', orders: [5 => new Order('id'), 9 => 'id ASC']);
    }

    public function testRejectsAnOffsetWithoutALimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('An offset needs a limit, because MySQL has no OFFSET without LIMIT.');

        new SelectSpec(from: 'users', offset: 20);
    }

    public function testHoldsTheLockItWasGiven(): void
    {
        $spec = new SelectSpec(from: 'users', lock: RowLock::UpdateSkipLocked);

        $this->assertSame(RowLock::UpdateSkipLocked, $spec->lock);
    }
}
