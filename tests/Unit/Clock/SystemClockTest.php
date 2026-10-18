<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Sloop\Clock\SystemClock;

final class SystemClockTest extends TestCase
{
    private string $previousTimeZone;

    protected function setUp(): void
    {
        $this->previousTimeZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimeZone);
    }

    public function testReadsTheCurrentTime(): void
    {
        $before = new DateTimeImmutable();
        $now    = new SystemClock()->now();
        $after  = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual($after, $now);
    }

    public function testReadsThePhpDefaultTimeZoneWhenItIsRead(): void
    {
        $clock = new SystemClock();
        date_default_timezone_set('Asia/Tokyo');

        $this->assertSame('Asia/Tokyo', $clock->now()->getTimezone()->getName());
    }
}
