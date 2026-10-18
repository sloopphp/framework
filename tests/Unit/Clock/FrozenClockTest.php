<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Clock;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Sloop\Clock\FrozenClock;

final class FrozenClockTest extends TestCase
{
    public function testReadsTheTimeItWasGivenInItsTimeZone(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-18T09:30:00.250000', new DateTimeZone('Asia/Tokyo')));

        $this->assertSame('2026-10-18T09:30:00.250000+09:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('Asia/Tokyo', $clock->now()->getTimezone()->getName());
    }

    public function testIsNotMovedByTheCallerChangingAMutableTime(): void
    {
        $given = new DateTime('2026-10-18T09:30:00Z');
        $clock = new FrozenClock($given);
        $given->modify('+1 day');

        $this->assertSame('2026-10-18T09:30:00+00:00', $clock->now()->format('Y-m-d\TH:i:sP'));
    }
}
