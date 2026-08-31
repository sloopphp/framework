<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\Assignment;
use Sloop\Database\Query\Condition;
use Sloop\Database\Query\Order;
use Sloop\Database\Query\UpdateSpec;

final class UpdateSpecTest extends TestCase
{
    public function testEmptyClausesAreTheDefault(): void
    {
        $spec = new UpdateSpec(table: 'users');

        $this->assertSame('users', $spec->table);
        $this->assertSame([], $spec->assignments);
        $this->assertSame([], $spec->conditions);
        $this->assertSame([], $spec->orders);
        $this->assertNull($spec->limit);
    }

    public function testAssignmentsAreReindexedAsAList(): void
    {
        $assignment = new Assignment('status', 'active');

        $spec = new UpdateSpec(table: 'users', assignments: ['status' => $assignment]);

        $this->assertSame([$assignment], $spec->assignments);
    }

    public function testConditionsAreReindexedAsAList(): void
    {
        $condition = new Condition('id', '=', 1);

        $spec = new UpdateSpec(table: 'users', conditions: [5 => $condition]);

        $this->assertSame([$condition], $spec->conditions);
    }

    public function testOrdersAreReindexedAsAList(): void
    {
        $order = new Order('id');

        $spec = new UpdateSpec(table: 'users', orders: [5 => $order]);

        $this->assertSame([$order], $spec->orders);
    }

    public function testRejectsAnAssignmentThatIsNotAnAssignment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Assignments must be an Assignment, got string at index 0.');

        new UpdateSpec(table: 'users', assignments: ['status = 1']);
    }

    public function testTheIndexAnAssignmentIsRefusedAtCountsFromTheStartRatherThanNamingTheKey(): void
    {
        // Assignments arrive keyed by column name, so the position has to be
        // counted rather than read off the key.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Assignments must be an Assignment, got string at index 0.');

        new UpdateSpec(table: 'users', assignments: ['status' => 'active']);
    }

    public function testRejectsAConditionThatIsNotAWherePart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Conditions must be a WherePart, got string at index 0.');

        new UpdateSpec(table: 'users', conditions: ['id = 1']);
    }

    public function testRejectsAnOrderThatIsNotAnOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Orders must be an Order, got string at index 0.');

        new UpdateSpec(table: 'users', orders: ['id DESC']);
    }

    public function testRejectsANegativeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Limit must not be negative, got -1.');

        new UpdateSpec(table: 'users', limit: -1);
    }
}
