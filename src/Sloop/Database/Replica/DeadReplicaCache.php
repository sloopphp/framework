<?php

declare(strict_types=1);

namespace Sloop\Database\Replica;

/**
 * Negative cache for replicas that recently failed to connect.
 *
 * Two-level keying isolates server-wide outages (shared key, all pools)
 * from pool-specific failures (per-pool key, e.g. auth failure for one
 * pool's credentials). ConnectionManager calls isDead() before attempting
 * a replica and one of markServerDead() / markPoolDead() after a failure.
 *
 * Implementations:
 * - ApcuDeadReplicaCache: shared across requests via APCu, used in production.
 * - InMemoryDeadReplicaCache: per-request fallback when ext-apcu is missing.
 */
interface DeadReplicaCache
{
    /**
     * Whether the given replica is currently marked dead.
     *
     * Returns true if either the shared server-level entry
     * (`sloop.db.dead.{host}:{port}`) or the pool-level entry
     * (`sloop.db.dead.{host}:{port}.{pool}`) is live.
     *
     * @param  string $host Replica host
     * @param  int    $port Replica port
     * @param  string $pool Pool name (the connections.<name> key)
     * @return bool
     */
    public function isDead(string $host, int $port, string $pool): bool;

    /**
     * Mark the replica's host:port dead at the server level (affects every pool).
     *
     * Called when the failure looks server-wide (TCP refused / timeout,
     * `DO 1` health-check failure).
     *
     * @param  string $host       Replica host
     * @param  int    $port       Replica port
     * @param  int    $ttlSeconds How long the dead mark should remain
     * @return void
     */
    public function markServerDead(string $host, int $port, int $ttlSeconds): void;

    /**
     * Mark the replica's host:port dead only for this specific pool.
     *
     * Called when the failure is pool-specific (auth failure SQLSTATE 28000)
     * so that other pools can keep using the same physical replica.
     *
     * @param  string $host       Replica host
     * @param  int    $port       Replica port
     * @param  string $pool       Pool name
     * @param  int    $ttlSeconds How long the dead mark should remain
     * @return void
     */
    public function markPoolDead(string $host, int $port, string $pool, int $ttlSeconds): void;
}
