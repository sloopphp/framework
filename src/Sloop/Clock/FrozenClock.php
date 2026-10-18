<?php

declare(strict_types=1);

namespace Sloop\Clock;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Clock\ClockInterface;

/**
 * A clock that always reads the time it was given, for tests.
 *
 * The time zone of that time is the one now() reports, so a test can fix the
 * zone along with the instant.
 */
final readonly class FrozenClock implements ClockInterface
{
    /**
     * The time every reading returns.
     *
     * @var DateTimeImmutable
     */
    private DateTimeImmutable $now;

    /**
     * Create the clock.
     *
     * @param DateTimeInterface $now Time every reading returns, in the time zone it carries
     */
    public function __construct(DateTimeInterface $now)
    {
        $this->now = DateTimeImmutable::createFromInterface($now);
    }

    /**
     * The time this clock was given.
     *
     * @return DateTimeImmutable
     */
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
