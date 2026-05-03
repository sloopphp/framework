<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Replica\ReplicaSelector;

/**
 * Test double that always picks the first surviving candidate.
 *
 * RandomReplicaSelector relies on `array_rand`, which is non-deterministic
 * and would make replica routing tests flaky. ConnectionManager removes
 * the failed candidate from the list after each failure, so picking index 0
 * on every call effectively iterates the replica list in declaration order.
 */
final class FixedReplicaSelector implements ReplicaSelector
{
    /**
     * Always return 0 (the head of the surviving list).
     *
     * @param  non-empty-list<ValidatedConfig> $candidates Surviving replica configs (must be non-empty)
     * @return int                                         Valid index into $candidates (always 0)
     */
    public function pick(array $candidates): int
    {
        return 0;
    }
}
