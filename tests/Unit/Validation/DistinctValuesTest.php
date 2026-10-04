<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Sloop\Validation\DistinctValues;
use stdClass;

final class DistinctValuesTest extends TestCase
{
    public function testValuesOfDifferentTypesAreNotTheSame(): void
    {
        $this->assertFalse(DistinctValues::hasDuplicate([1, '1', 1.0, true, null, '', '0', 0, false]));
    }

    public function testZeroAndNegativeZeroAreTheSame(): void
    {
        $this->assertSame([0 => 0.0], DistinctValues::firstOccurrences([0.0, -0.0]));
    }

    public function testDifferentFloatsAreNotTheSame(): void
    {
        $this->assertFalse(DistinctValues::hasDuplicate([0.0, 1.0]));
    }

    public function testArraysHoldingFloatsAreComparedWithStrictEquality(): void
    {
        $this->assertTrue(DistinctValues::hasDuplicate([[0.0], [-0.0]]));
    }

    public function testNanIsNeverTheSameAsAnything(): void
    {
        $this->assertFalse(DistinctValues::hasDuplicate([NAN, NAN]));
        $this->assertFalse(DistinctValues::hasDuplicate([[NAN], [NAN]]));
    }

    public function testArraysAreTheSameOnlyWithTheSameKeysInTheSameOrder(): void
    {
        $this->assertTrue(DistinctValues::hasDuplicate([['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2]]));
        $this->assertFalse(DistinctValues::hasDuplicate([['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]]));
        $this->assertFalse(DistinctValues::hasDuplicate([[1, [2]], [1, ['2']]]));
        $this->assertFalse(DistinctValues::hasDuplicate([['ab'], ['a', 'b']]));
        $this->assertFalse(DistinctValues::hasDuplicate([['a' => 1], ['b' => 1]]));
        $this->assertFalse(DistinctValues::hasDuplicate([[[1], 2], [[1, 2]]]));
    }

    public function testObjectsAreTheSameOnlyWhenIdentical(): void
    {
        $object = new stdClass();

        $this->assertTrue(DistinctValues::hasDuplicate([$object, $object]));
        $this->assertFalse(DistinctValues::hasDuplicate([$object, new stdClass()]));
        $this->assertSame([0 => $object, 2 => 'x'], DistinctValues::firstOccurrences([$object, $object, 'x']));
        $this->assertTrue(DistinctValues::hasDuplicate([[$object], [$object]]));
    }

    public function testResourcesAreTheSameOnlyWhenIdentical(): void
    {
        $this->assertFalse(DistinctValues::hasDuplicate([STDIN, STDOUT]));
        $this->assertTrue(DistinctValues::hasDuplicate([STDIN, STDIN]));
    }

    public function testFirstOccurrencesKeepsTheKeysOfTheValuesKept(): void
    {
        $this->assertSame(['a' => 1, 'c' => 2], DistinctValues::firstOccurrences(['a' => 1, 'b' => 1, 'c' => 2, 'd' => 2]));
    }
}
