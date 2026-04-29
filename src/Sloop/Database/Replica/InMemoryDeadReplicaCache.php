<?php

declare(strict_types=1);

namespace Sloop\Database\Replica;

use Closure;

/**
 * Per-request DeadReplicaCache for environments without ext-apcu.
 *
 * Entries live only as long as the current PHP process / request, so dead
 * marks do not propagate between FPM workers. Acceptable as a degraded
 * fallback because dead detection still takes effect within the same
 * request once the first failure is observed; ApcuDeadReplicaCache should
 * be preferred in production for cross-request sharing.
 */
final class InMemoryDeadReplicaCache implements DeadReplicaCache
{
    /**
     * Dead-mark expiry timestamps keyed by cache key.
     *
     * @var array<string, int>
     */
    private array $entries = [];

    /**
     * Returns the current unix timestamp; injected for testability.
     *
     * @var Closure(): int
     */
    private Closure $clock;

    /**
     * Build a per-request cache. Pass a clock to make expiry behaviour deterministic in tests.
     *
     * @param (Closure(): int)|null $clock Returns current unix timestamp; defaults to time(...)
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Per-request lookup; expired entries are evicted lazily on read.
     *
     * @param  string $host Replica host
     * @param  int    $port Replica port
     * @param  string $pool Pool name
     * @return bool
     */
    public function isDead(string $host, int $port, string $pool): bool
    {
        return $this->isLiveEntry(DeadReplicaCacheKeys::server($host, $port))
            || $this->isLiveEntry(DeadReplicaCacheKeys::pool($host, $port, $pool));
    }

    /**
     * Stores the expiry (`now + ttl`) in the per-request entries table under the shared key.
     *
     * @param  string $host       Replica host
     * @param  int    $port       Replica port
     * @param  int    $ttlSeconds How long the dead mark should remain
     * @return void
     */
    public function markServerDead(string $host, int $port, int $ttlSeconds): void
    {
        $this->entries[DeadReplicaCacheKeys::server($host, $port)] = ($this->clock)() + $ttlSeconds;
    }

    /**
     * Stores the expiry (`now + ttl`) in the per-request entries table under the pool-specific key.
     *
     * @param  string $host       Replica host
     * @param  int    $port       Replica port
     * @param  string $pool       Pool name
     * @param  int    $ttlSeconds How long the dead mark should remain
     * @return void
     */
    public function markPoolDead(string $host, int $port, string $pool, int $ttlSeconds): void
    {
        $this->entries[DeadReplicaCacheKeys::pool($host, $port, $pool)] = ($this->clock)() + $ttlSeconds;
    }

    /**
     * Whether the entry exists and has not expired; expired entries are evicted.
     *
     * @param  string $key Cache key under inspection
     * @return bool
     */
    private function isLiveEntry(string $key): bool
    {
        if (!isset($this->entries[$key])) {
            return false;
        }

        if ($this->entries[$key] <= ($this->clock)()) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }
}
