<?php

declare(strict_types=1);

namespace Sloop\Tests\Support;

/**
 * Test helper: a callable clock with a publicly mutable unix timestamp.
 *
 * Designed for code that takes a `Closure(): int` clock injection point.
 * Pass `$clock(...)` (first-class callable syntax) to inject; advance the
 * timestamp freely between calls via `$clock->now = ...`. The same instance
 * stays bound to the production code under test, so subsequent reads observe
 * the new time without re-injecting.
 */
final class MutableClock
{
    /**
     * Construct a clock starting at the given timestamp.
     *
     * @param int $now Initial unix timestamp (defaults to a fixed value so tests start from a known point)
     */
    public function __construct(public int $now = 1000)
    {
    }

    /**
     * Return the current timestamp; lets the instance be passed as a `Closure(): int` via `$clock(...)`.
     *
     * @return int Current unix timestamp
     */
    public function __invoke(): int
    {
        return $this->now;
    }
}
