<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\Condition;
use Sloop\Database\Query\DeleteSpec;
use Sloop\Database\Query\Order;

final class DeleteSpecTest extends TestCase
{
    public function testEmptyClausesAreTheDefault(): void
    {
        $spec = new DeleteSpec(from: 'users');

        $this->assertSame('users', $spec->from);
        $this->assertSame([], $spec->conditions);
        $this->assertSame([], $spec->orders);
        $this->assertNull($spec->limit);
    }

    public function testConditionsAreReindexedAsAList(): void
    {
        $condition = new Condition('id', '=', 1);

        $spec = new DeleteSpec(from: 'users', conditions: [5 => $condition]);

        $this->assertSame([$condition], $spec->conditions);
    }

    public function testOrdersAreReindexedAsAList(): void
    {
        $order = new Order('id');

        $spec = new DeleteSpec(from: 'users', orders: [5 => $order]);

        $this->assertSame([$order], $spec->orders);
    }

    public function testRejectsAConditionThatIsNotAWherePart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Conditions must be a WherePart, got string at index 0.');

        new DeleteSpec(from: 'users', conditions: ['id = 1']);
    }

    public function testRejectsAnOrderThatIsNotAnOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Orders must be an Order, got string at index 0.');

        new DeleteSpec(from: 'users', orders: ['id DESC']);
    }

    public function testRejectsANegativeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Limit must not be negative, got -1.');

        new DeleteSpec(from: 'users', limit: -1);
    }
}
