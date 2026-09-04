<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\InsertSpec;

final class InsertSpecTest extends TestCase
{
    public function testEmptyClausesAreTheDefault(): void
    {
        $spec = new InsertSpec(table: 'users');

        $this->assertSame('users', $spec->table);
        $this->assertSame([], $spec->columns);
        $this->assertSame([], $spec->rows);
        $this->assertFalse($spec->ignore);
    }

    public function testColumnsAndRowsAreReindexedAsLists(): void
    {
        $spec = new InsertSpec(table: 'users', columns: [3 => 'name'], rows: [7 => [5 => 'alice']]);

        $this->assertSame(['name'], $spec->columns);
        $this->assertSame([['alice']], $spec->rows);
    }

    public function testRejectsAColumnThatIsNotAString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Columns must be a string, got int at index 0.');

        new InsertSpec(table: 'users', columns: [1]);
    }

    public function testTheIndexAColumnIsRefusedAtCountsFromTheStart(): void
    {
        // Columns may arrive under keys of the caller's choosing, so the
        // position has to be counted rather than read off the key.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Columns must be a string, got int at index 0.');

        new InsertSpec(table: 'users', columns: ['first' => 1]);
    }

    public function testTheIndexARowIsRefusedAtCountsFromTheStart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Rows must be an array of values, got string at index 0.');

        new InsertSpec(table: 'users', columns: ['name'], rows: ['first' => 'alice']);
    }

    public function testRejectsARowThatIsNotAnArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Rows must be an array of values, got string at index 0.');

        new InsertSpec(table: 'users', columns: ['name'], rows: ['alice']);
    }

    public function testRejectsAValueThatCannotBeWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A value must be a scalar, null or an Expression, got array in the row at index 0.',
        );

        new InsertSpec(table: 'users', columns: ['name'], rows: [[['alice']]]);
    }

    public function testRejectsARowThatDoesNotCarryOneValuePerColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Every row carries one value per column, so the row at index 1 must hold 2, got 1.',
        );

        new InsertSpec(
            table:   'users',
            columns: ['name', 'score'],
            rows:    [['alice', 10], ['bob']],
        );
    }

    public function testTheIgnoreFlagIsCarriedAsGiven(): void
    {
        $spec = new InsertSpec(table: 'users', columns: ['name'], rows: [['alice']], ignore: true);

        $this->assertTrue($spec->ignore);
    }
}
