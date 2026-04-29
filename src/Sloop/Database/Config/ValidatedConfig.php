<?php

declare(strict_types=1);

namespace Sloop\Database\Config;

/**
 * Validated database connection configuration.
 *
 * Constructed only via ConnectionConfigResolver::validate(). Downstream
 * consumers (resolveDsn / resolvePdoOptions) accept this type so that
 * validated values are passed without runtime type checks. Carrying the
 * post-validation state in a typed object replaces ad-hoc array access
 * and assert() calls in the resolver.
 *
 * @internal Constructed by ConnectionConfigResolver only.
 */
final readonly class ValidatedConfig
{
    /**
     * Construct a fully validated single-connection definition.
     *
     * @param string            $driver                Database driver (currently only 'mysql')
     * @param string            $host                  Database host
     * @param int|null          $port                  Database port (null uses driver default)
     * @param string            $database              Database name
     * @param string|null       $username              Database user (null when not required)
     * @param string|null       $password              Database password (null when not required)
     * @param string|null       $charset               Connection charset (null lets the resolver apply its default)
     * @param string|null       $collation             Connection collation (null skips the COLLATE clause)
     * @param int|null          $connectTimeoutSeconds TCP connect timeout (null lets the resolver apply its default)
     * @param array<int, mixed> $options               Caller-supplied PDO attribute overrides (int keys)
     */
    public function __construct(
        public string $driver,
        public string $host,
        public ?int $port,
        public string $database,
        public ?string $username,
        public ?string $password,
        public ?string $charset,
        public ?string $collation,
        public ?int $connectTimeoutSeconds,
        public array $options,
    ) {
    }
}
