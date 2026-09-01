<?php

declare(strict_types=1);

namespace Sloop\Database\Config;

use Sloop\Database\CastMode;

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
     * @param bool                  $logBindings           Whether prepared-statement bindings appear in log context
     * @param bool                  $logAllQueries         Whether every query is logged at `debug` level
     * @param int|null              $slowQueryThresholdMs  Threshold (ms) above which SELECT queries log at `warning`
     * @param int|null              $queryTimeoutMs        Per-session query timeout in ms; null disables it.
     *                                                     Connection issues a single SET SESSION (max_execution_time
     *                                                     for MySQL, max_statement_time for MariaDB) lazily on the
     *                                                     first query after dialect detection
     * @param bool                  $persistent            Whether pool connections are opened with PDO::ATTR_PERSISTENT.
     *                                                     ConnectionManager forwards this flag to ConnectionFactory::make()
     *                                                     for primary and replica connections; probeReplicas() always
     *                                                     opens a non-persistent connection regardless of this value
     * @param string                $prefix                Prepended to every table name a query builder quotes; empty for none.
     *                                                     Pool-level rather than per server, because the primary and its
     *                                                     replicas hold the same tables
     * @param CastMode              $casts                 How far fetched values are converted away from the driver's types.
     *                                                     Pool-level because the return types of a read must not depend on
     *                                                     which server answered it
     * @param bool                  $strictMode            Whether a query builder refuses to run an UPDATE or DELETE that
     *                                                     carries no WHERE clause. Pool-level rather than per server,
     *                                                     because it guards the tables the pool holds rather than the
     *                                                     connection that reaches them
     */
    public function __construct(
        public string $name,
        public ValidatedConfig $primary,
        public array $replicas,
        public bool $healthCheck,
        public int $deadCacheTtlSeconds,
        public string $replicaSelector,
        public int $maxConnectionAttempts,
        public bool $logBindings,
        public bool $logAllQueries,
        public ?int $slowQueryThresholdMs,
        public ?int $queryTimeoutMs,
        public bool $persistent,
        public string $prefix,
        public CastMode $casts,
        public bool $strictMode,
    ) {
    }
}
