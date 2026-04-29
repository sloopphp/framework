<?php

declare(strict_types=1);

namespace Sloop\Database\Replica;

use Sloop\Database\Config\ValidatedConfig;

/**
 * Strategy for picking one replica out of a pool's surviving candidates.
 *
 * ConnectionManager filters out replicas already known to be dead via
 * DeadReplicaCache, then asks the selector to pick one of the survivors.
 * The selector is stateless across calls; ConnectionManager owns the
 * iteration and dead-list bookkeeping.
 */
interface ReplicaSelector
{
    /**
     * Pick one candidate and return its index, or null when no candidates remain.
     *
     * @param  list<ValidatedConfig> $candidates Surviving replica configs
     * @return int|null              Index into $candidates, or null when empty
     */
    public function pick(array $candidates): ?int;
}
