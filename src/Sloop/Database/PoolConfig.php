<?php

declare(strict_types=1);

namespace Sloop\Database;

/**
 * Validated pool configuration: primary + replica list + pool-level behavior settings.
 *
 * Constructed only via ConnectionConfigResolver::validatePool(). Carries the
 * full read/write routing context for one connection pool, consumed by
 * ConnectionManager when routing reads and writes.
 *
 * @internal Constructed by ConnectionConfigResolver only.
 */
final readonly class PoolConfig
{
    /**
     * Construct a fully validated pool definition.
     *
     * @param string                $name                  Pool name (the connections.<name> key)
     * @param ValidatedConfig       $primary               Primary server configuration
     * @param list<ValidatedConfig> $replicas              Read replicas (empty when no `read` is configured)
     * @param bool                  $healthCheck           Whether to run `DO 1` after PDO connect
     * @param int                   $deadCacheTtlSeconds   TTL of dead-cache entries
     * @param string                $replicaSelector       Replica selection strategy identifier
     * @param int                   $maxConnectionAttempts Maximum connection attempts before giving up
     */
    public function __construct(
        public string $name,
        public ValidatedConfig $primary,
        public array $replicas,
        public bool $healthCheck,
        public int $deadCacheTtlSeconds,
        public string $replicaSelector,
        public int $maxConnectionAttempts,
    ) {
    }
}
