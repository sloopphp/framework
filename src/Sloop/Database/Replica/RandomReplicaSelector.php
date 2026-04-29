<?php

declare(strict_types=1);

namespace Sloop\Database\Replica;

use Sloop\Database\Config\ValidatedConfig;

/**
 * Selects a replica uniformly at random.
 *
 * The only selector shipped in v0.1; the past production implementation
 * also relied on random selection without observed need for round-robin
 * or weighted strategies. Future selectors (e.g. RoundRobin / Weighted)
 * may be added behind the same interface in subsequent minor releases.
 */
final class RandomReplicaSelector implements ReplicaSelector
{
    /**
     * Pick one candidate uniformly at random and return its index.
     *
     * @param  list<ValidatedConfig> $candidates Surviving replica configs
     * @return int|null              Index into $candidates, or null when empty
     */
    public function pick(array $candidates): ?int
    {
        if ($candidates === []) {
            return null;
        }

        return (int) array_rand($candidates);
    }
}
