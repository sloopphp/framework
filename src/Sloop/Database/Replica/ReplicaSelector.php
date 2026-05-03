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
     * Pick one candidate and return its index.
     *
     * Callers must ensure $candidates is non-empty; ConnectionManager filters
     * out dead replicas first and only invokes pick() while at least one
     * survivor remains, so this contract is checkable at the call site.
     *
     * @param  non-empty-list<ValidatedConfig> $candidates Surviving replica configs (must be non-empty)
     * @return int                                         Valid index into $candidates
     */
    public function pick(array $candidates): int;
}
