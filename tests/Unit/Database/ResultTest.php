<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Result;
use Sloop\Support\Collection;

final class ResultTest extends TestCase
{
    public function testCountReturnsNumberOfRows(): void
    {
        $result = new Result([
            ['id' => 1, 'name' => 'alice'],
            ['id' => 2, 'name' => 'bob'],
            ['id' => 3, 'name' => 'carol'],
        ]);

        $this->assertCount(3, $result);
    }

    public function testCountReturnsZeroForEmptyResult(): void
    {
        $this->assertCount(0, new Result([]));
    }

    public function testIteratesRowsInInsertionOrder(): void
    {
        $rows   = [
            ['id' => 1, 'name' => 'alice'],
            ['id' => 2, 'name' => 'bob'],
        ];
        $result = new Result($rows);

        $collected = [];
        foreach ($result as $row) {
            $collected[] = $row;
        }

        $this->assertSame($rows, $collected);
    }

    public function testIteratingEmptyResultYieldsNothing(): void
    {
        $collected = [];
        foreach (new Result([]) as $row) {
            $collected[] = $row;
        }

        $this->assertSame([], $collected);
    }

    public function testAsArrayReturnsSameRows(): void
    {
        $rows   = [['id' => 1, 'name' => 'alice']];
        $result = new Result($rows);

        $this->assertSame($rows, $result->asArray());
    }

    public function testAsArrayReturnsEmptyArrayForEmptyResult(): void
    {
        $this->assertSame([], new Result([])->asArray());
    }

    public function testCanBeIteratedMultipleTimes(): void
    {
        $rows   = [['id' => 1], ['id' => 2]];
        $result = new Result($rows);

        $first  = iterator_to_array($result);
        $second = iterator_to_array($result);

        $this->assertSame($rows, $first);
        $this->assertSame($rows, $second);
    }

    public function testIsEmptyReportsWhetherThereAreRows(): void
    {
        $this->assertTrue(new Result([])->isEmpty());
        $this->assertFalse(new Result([['id' => 1]])->isEmpty());
    }

    public function testFirstReturnsTheLeadingRow(): void
    {
        $result = new Result([['id' => 1], ['id' => 2]]);

        $this->assertSame(['id' => 1], $result->first());
    }

    public function testFirstReturnsNullForEmptyResult(): void
    {
        $this->assertNull(new Result([])->first());
    }

    public function testAsArrayByKeysRowsByTheGivenColumn(): void
    {
        $result = new Result([
            ['id' => 7, 'name' => 'alice'],
            ['id' => 9, 'name' => 'bob'],
        ]);

        $this->assertSame(
            [
                7 => ['id' => 7, 'name' => 'alice'],
                9 => ['id' => 9, 'name' => 'bob'],
            ],
            $result->asArrayBy('id'),
        );
    }

    public function testAsArrayByKeepsTheLastRowOnDuplicateKeys(): void
    {
        $result = new Result([
            ['id' => 1, 'name' => 'first'],
            ['id' => 1, 'name' => 'second'],
        ]);

        $this->assertSame([1 => ['id' => 1, 'name' => 'second']], $result->asArrayBy('id'));
    }

    public function testAsArrayByDropsTheKeyColumnWhenAsked(): void
    {
        $result = new Result([['id' => 7, 'name' => 'alice']]);

        $this->assertSame([7 => ['name' => 'alice']], $result->asArrayBy('id', removeKey: true));
    }

    public function testAsArrayByRejectsAColumnThatIsNotInTheResult(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column "missing" is not in the result set.');

        $result->asArrayBy('missing');
    }

    public function testAsArrayByRejectsANullKeyValue(): void
    {
        // PHP would turn a null key into '' and silently merge every such row
        // into one entry.
        $result = new Result([['id' => null, 'name' => 'alice']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Column "id" must hold an int or string to be used as a key, got null.',
        );

        $result->asArrayBy('id');
    }

    public function testAsArrayByRejectsAFloatKeyValue(): void
    {
        // A float key is truncated to int, so 1.5 and 1.9 would collide on 1.
        $result = new Result([['id' => 1.5, 'name' => 'alice']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Column "id" must hold an int or string to be used as a key, got float.',
        );

        $result->asArrayBy('id');
    }

    public function testAsMapPairsTwoColumns(): void
    {
        $result = new Result([
            ['id' => 1, 'email' => 'alice@example.com'],
            ['id' => 2, 'email' => 'bob@example.com'],
        ]);

        $this->assertSame(
            [1 => 'alice@example.com', 2 => 'bob@example.com'],
            $result->asMap('id', 'email'),
        );
    }

    public function testAsMapCarriesNullValuesThrough(): void
    {
        $result = new Result([['id' => 1, 'deleted_at' => null]]);

        $this->assertSame([1 => null], $result->asMap('id', 'deleted_at'));
    }

    public function testAsMapKeepsTheLastValueOnDuplicateKeys(): void
    {
        $result = new Result([
            ['id' => 1, 'email' => 'first@example.com'],
            ['id' => 1, 'email' => 'second@example.com'],
        ]);

        $this->assertSame([1 => 'second@example.com'], $result->asMap('id', 'email'));
    }

    public function testAsMapRejectsAValueColumnThatIsNotInTheResult(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column "email" is not in the result set.');

        $result->asMap('id', 'email');
    }

    public function testGroupByCollectsRowsSharingAColumnValue(): void
    {
        $result = new Result([
            ['status' => 'active', 'id' => 1],
            ['status' => 'banned', 'id' => 2],
            ['status' => 'active', 'id' => 3],
        ]);

        $this->assertSame(
            [
                'active' => [
                    ['status' => 'active', 'id' => 1],
                    ['status' => 'active', 'id' => 3],
                ],
                'banned' => [
                    ['status' => 'banned', 'id' => 2],
                ],
            ],
            $result->groupBy('status'),
        );
    }

    public function testGroupByRejectsAColumnThatIsNotInTheResult(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column "status" is not in the result set.');

        $result->groupBy('status');
    }

    public function testToCollectionHandsTheRowsToACollection(): void
    {
        $rows   = [['id' => 1, 'score' => 10], ['id' => 2, 'score' => 32]];
        $result = new Result($rows);

        $collection = $result->toCollection();

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame($rows, $collection->toArray());
        // Exercising a Collection-only operation proves the bridge produces a
        // usable Collection rather than something that merely holds the rows.
        $this->assertSame(42, $collection->sum('score'));
    }
}
