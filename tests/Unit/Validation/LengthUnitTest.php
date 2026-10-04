<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\LengthUnit;

final class LengthUnitTest extends TestCase
{
    use ThrowsAssertions;

    public function testGraphemesThrowsWhenIcuCannotCountTheValue(): void
    {
        $e = $this->assertThrows(RuntimeException::class, static fn () => LengthUnit::Graphemes->length("\xff"));

        $this->assertSame('Could not count the grapheme clusters of the value.', $e->getMessage());
    }
}
