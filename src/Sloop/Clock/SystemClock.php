<?php

declare(strict_types=1);

namespace Sloop\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The clock of the machine the application runs on.
 *
 * The time zone is the PHP default as it stands when now() is called, so a
 * change of that default is seen by the next reading rather than fixed when
 * the clock is built.
 */
final readonly class SystemClock implements ClockInterface
{
    /**
     * The current time, in the PHP default time zone.
     *
     * @return DateTimeImmutable
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    }
}
