<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Sloop\Database\Result;

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

    public function testToArrayReturnsSameRows(): void
    {
        $rows   = [['id' => 1, 'name' => 'alice']];
        $result = new Result($rows);

        $this->assertSame($rows, $result->toArray());
    }

    public function testToArrayReturnsEmptyArrayForEmptyResult(): void
    {
        $this->assertSame([], (new Result([]))->toArray());
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
}
