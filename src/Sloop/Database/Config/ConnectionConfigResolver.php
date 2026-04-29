<?php

declare(strict_types=1);

namespace Sloop\Database\Config;

use PDO;
use Pdo\Mysql as PdoMysql;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * Pure transformation: connection config array → ValidatedConfig / PoolConfig → DSN / PDO options.
 *
 * Separated from ConnectionManager so that config interpretation can be
 * unit-tested without instantiating PDO.
 */
final class ConnectionConfigResolver
{
    /**
     * Config keys recognized at the single-connection level (a primary or one replica entry).
     *
     * Pool-level keys (`read`, `health_check`, etc.) are listed separately in ALLOWED_POOL_KEYS.
     *
     * @var list<string>
     */
    private const array ALLOWED_KEYS = [
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'charset',
        'collation',
        'connect_timeout_seconds',
        'options',
    ];

    /**
     * Pool-level config keys: ALLOWED_KEYS plus pool-only keys.
     *
     * Pool-only keys MUST NOT appear inside `read[]` elements; they apply
     * to the pool as a whole, not per replica.
     *
     * @var list<string>
     */
    private const array ALLOWED_POOL_KEYS = [
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'charset',
        'collation',
        'connect_timeout_seconds',
        'options',
        'read',
        'health_check',
        'dead_cache_ttl_seconds',
        'replica_selector',
        'max_connection_attempts',
    ];

    /**
     * Required config keys; absence causes InvalidConfigException.
     *
     * @var list<string>
     */
    private const array REQUIRED_KEYS = ['driver', 'host', 'database'];

    /**
     * Accepted drivers (currently only MySQL/MariaDB via mysql driver).
     *
     * @var list<string>
     */
    private const array ALLOWED_DRIVERS = ['mysql'];

    /**
     * Accepted replica selection strategy identifiers.
     *
     * @var list<string>
     */
    private const array ALLOWED_REPLICA_SELECTORS = ['random'];

    /**
     * Default TCP connect timeout in seconds when config omits the key.
     *
     * @var int
     */
    private const int DEFAULT_CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * Default charset when config omits the key.
     *
     * @var string
     */
    private const string DEFAULT_CHARSET = 'utf8mb4';

    /**
     * Default health check ON when omitted.
     *
     * @var bool
     */
    private const bool DEFAULT_HEALTH_CHECK = true;

    /**
     * Default TTL for dead-cache entries when omitted.
     *
     * @var int
     */
    private const int DEFAULT_DEAD_CACHE_TTL_SECONDS = 300;

    /**
     * Default replica selector when omitted.
     *
     * @var string
     */
    private const string DEFAULT_REPLICA_SELECTOR = 'random';

    /**
     * Validate a single-connection config and return a typed ValidatedConfig.
     *
     * Public entry point for single-connection validation and the per-entry
     * primitive used internally by validatePool() for the primary and each replica.
     *
     * Accepts array-key keys (string|int) so that integer keys from
     * mistakenly written list-style config can be detected and rejected.
     *
     * @param  string                  $name   Connection name for error messages
     * @param  array<array-key, mixed> $config Raw config array (may contain non-string keys)
     * @return ValidatedConfig         Validated, typed view of the config
     * @throws InvalidConfigException  When any key is unknown, a required key is missing, or a value has the wrong type
     */
    public static function validate(string $name, array $config): ValidatedConfig
    {
        self::assertNoUnknownKeys($name, $config);
        self::assertRequiredKeysPresent($name, $config);

        return new ValidatedConfig(
            driver:                self::extractDriver($name, $config),
            host:                  self::extractRequiredString($name, $config, 'host'),
            port:                  self::extractOptionalInt($name, $config, 'port'),
            database:              self::extractRequiredString($name, $config, 'database'),
            username:              self::extractOptionalNullableString($name, $config, 'username'),
            password:              self::extractOptionalNullableString($name, $config, 'password'),
            charset:               self::extractOptionalIdentifier($name, $config, 'charset'),
            collation:             self::extractOptionalIdentifier($name, $config, 'collation'),
            connectTimeoutSeconds: self::extractOptionalInt($name, $config, 'connect_timeout_seconds'),
            options:               self::extractOptions($name, $config),
        );
    }

    /**
     * Validate a pool config (primary + optional replicas + pool-level behavior keys).
     *
     * Replica entries inherit primary's single-connection keys; each replica
     * entry MAY override `host` / `port` / etc. Pool-level keys (`read`,
     * `health_check`, `dead_cache_ttl_seconds`, `replica_selector`,
     * `max_connection_attempts`) MUST NOT appear inside `read[]` entries.
     *
     * @param  string                  $name   Pool name (the connections.<name> key)
     * @param  array<array-key, mixed> $config Raw pool config array
     * @return PoolConfig              Validated pool with primary + replicas + behavior settings
     * @throws InvalidConfigException  When any key is unknown, required key is missing, or a value has the wrong type
     */
    public static function validatePool(string $name, array $config): PoolConfig
    {
        self::assertNoUnknownPoolKeys($name, $config);

        $primarySingleConfig = self::extractSingleConnectionConfig($config);
        $primary             = self::validate($name, $primarySingleConfig);

        $replicas = self::extractReplicas($name, $config, $primarySingleConfig);

        $healthCheck  = self::extractOptionalHealthCheck($name, $config) ?? self::DEFAULT_HEALTH_CHECK;
        $deadCacheTtl = self::extractOptionalPositiveInt($name, $config, 'dead_cache_ttl_seconds') ?? self::DEFAULT_DEAD_CACHE_TTL_SECONDS;
        $selector     = self::extractOptionalReplicaSelector($name, $config) ?? self::DEFAULT_REPLICA_SELECTOR;
        $maxAttempts  = self::extractOptionalPositiveInt($name, $config, 'max_connection_attempts') ?? (\count($replicas) + 1);

        return new PoolConfig(
            name:                  $name,
            primary:               $primary,
            replicas:              $replicas,
            healthCheck:           $healthCheck,
            deadCacheTtlSeconds:   $deadCacheTtl,
            replicaSelector:       $selector,
            maxConnectionAttempts: $maxAttempts,
        );
    }

    /**
     * Build a PDO DSN string from a validated config.
     *
     * @param  ValidatedConfig $config Validated config (constructed via validate())
     * @return string                  DSN suitable for `new PDO($dsn, ...)`
     */
    public static function resolveDsn(ValidatedConfig $config): string
    {
        $dsn = 'mysql:host=' . $config->host;
        if ($config->port !== null) {
            $dsn .= ';port=' . $config->port;
        }
        $dsn .= ';dbname=' . $config->database;

        return $dsn;
    }

    /**
     * Build the PDO options array from a validated config.
     *
     * Caller-supplied `options` entries win over the defaults assembled here
     * (TCP timeout and INIT_COMMAND). Connection::open() then merges sloop's
     * own PDO defaults below everything.
     *
     * @param  ValidatedConfig    $config Validated config (constructed via validate())
     * @return array<int, mixed>          PDO attribute keys → values
     */
    public static function resolvePdoOptions(ValidatedConfig $config): array
    {
        $options = [
            PDO::ATTR_TIMEOUT           => $config->connectTimeoutSeconds ?? self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
            PdoMysql::ATTR_INIT_COMMAND => self::buildInitCommand($config),
        ];

        foreach ($config->options as $key => $value) {
            $options[$key] = $value;
        }

        return $options;
    }

    /**
     * Build the SET NAMES ... [COLLATE ...] init command.
     *
     * @param  ValidatedConfig $config Validated config
     * @return string
     */
    private static function buildInitCommand(ValidatedConfig $config): string
    {
        $charset = $config->charset ?? self::DEFAULT_CHARSET;
        $command = 'SET NAMES ' . $charset;
        if ($config->collation !== null) {
            $command .= ' COLLATE ' . $config->collation;
        }

        return $command;
    }

    /**
     * Reject any config key not in ALLOWED_KEYS (including non-string keys).
     *
     * @param  string                  $name   Connection name for error messages
     * @param  array<array-key, mixed> $config Raw config array
     * @return void
     * @throws InvalidConfigException When an unknown or non-string key is present
     */
    private static function assertNoUnknownKeys(string $name, array $config): void
    {
        foreach (array_keys($config) as $key) {
            if (!\is_string($key) || !\in_array($key, self::ALLOWED_KEYS, true)) {
                throw new InvalidConfigException(
                    'Connection [' . $name . ']: unsupported config key "' . $key . '".',
                );
            }
        }
    }

    /**
     * Reject when any of REQUIRED_KEYS is absent from the config.
     *
     * @param  string                  $name   Connection name for error messages
     * @param  array<array-key, mixed> $config Raw config array
     * @return void
     * @throws InvalidConfigException When a required key is missing
     */
    private static function assertRequiredKeysPresent(string $name, array $config): void
    {
        foreach (self::REQUIRED_KEYS as $required) {
            if (!\array_key_exists($required, $config)) {
                throw new InvalidConfigException(
                    'Connection [' . $name . ']: missing required config key "' . $required . '".',
                );
            }
        }
    }

    /**
     * Extract the driver value, ensuring it is a string in ALLOWED_DRIVERS.
     *
     * @param  string                  $name   Connection name for error messages
     * @param  array<array-key, mixed> $config Raw config array
     * @return string
     * @throws InvalidConfigException When driver is not a string or not in ALLOWED_DRIVERS
     */
    private static function extractDriver(string $name, array $config): string
    {
        $driver = self::extractRequiredString($name, $config, 'driver');
        if (!\in_array($driver, self::ALLOWED_DRIVERS, true)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: unsupported driver "' . $driver . '". Only \'mysql\' is supported.',
            );
        }

        return $driver;
    }

    /**
     * Extract a required string-typed config value.
     *
     * @param  string                  $name   Connection name for error messages
     * @param  array<array-key, mixed> $config Config array
     * @param  string                  $key    Key to extract
     * @return string
     * @throws InvalidConfigException When the value is not a string
     */
    private static function extractRequiredString(string $name, array $config, string $key): string
    {
        $value = $config[$key];
        if (!\is_string($value)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "' . $key . '" must be a string.',
            );
        }

        return $value;
    }

    /**
     * Extract an optional integer-typed config value, returning null when absent.
     *
     * @param  string                  $name   Connection name for error messages
     * @param  array<array-key, mixed> $config Config array
     * @param  string                  $key    Key to extract
     * @return int|null
     * @throws InvalidConfigException When the value is present but not an int
     */
    private static function extractOptionalInt(string $name, array $config, string $key): ?int
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if (!\is_int($value)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "' . $key . '" must be an integer.',
            );
        }

        return $value;
    }

    /**
     * Extract an optional nullable-string config value.
     *
     * @param  string                  $name   Connection name for error messages
     * @param  array<array-key, mixed> $config Config array
     * @param  string                  $key    Key to extract
     * @return string|null
     * @throws InvalidConfigException When the value is present but not null and not a string
     */
    private static function extractOptionalNullableString(string $name, array $config, string $key): ?string
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if ($value !== null && !\is_string($value)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "' . $key . '" must be a string or null.',
            );
        }

        return $value;
    }

    /**
     * Extract an optional identifier-string (charset/collation) restricted to alphanumeric + underscore.
     *
     * Restricting to ASCII alphanumeric + underscore is sufficient for MySQL
     * charset / collation names and prevents injection via INIT_COMMAND.
     *
     * @param  string                  $name   Connection name for error messages
     * @param  array<array-key, mixed> $config Config array
     * @param  string                  $key    Key to extract
     * @return string|null
     * @throws InvalidConfigException When the value is present but not a valid identifier
     */
    private static function extractOptionalIdentifier(string $name, array $config, string $key): ?string
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if (!\is_string($value)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "' . $key . '" must be a string.',
            );
        }

        if (preg_match('/^[a-zA-Z0-9_]+$/', $value) !== 1) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "' . $key . '" must contain only alphanumeric and underscore characters, got "' . $value . '".',
            );
        }

        return $value;
    }

    /**
     * Extract the optional `options` array, returning [] when absent.
     *
     * The `options` key is the only one that requires an int-keyed array
     * (PDO::ATTR_* constants), so the check is specialized rather than
     * parameterized by key name.
     *
     * @param  string                  $name   Connection name for error messages
     * @param  array<array-key, mixed> $config Config array
     * @return array<int, mixed>       Caller-supplied PDO attribute overrides
     * @throws InvalidConfigException When `options` is present but not an int-keyed array
     */
    private static function extractOptions(string $name, array $config): array
    {
        if (!\array_key_exists('options', $config)) {
            return [];
        }

        $value = $config['options'];
        if (!\is_array($value)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "options" must be an array.',
            );
        }

        $extracted = [];
        foreach ($value as $key => $element) {
            if (!\is_int($key)) {
                throw new InvalidConfigException(
                    'Connection [' . $name . ']: config key "options" must be an array with integer (PDO::ATTR_*) keys.',
                );
            }
            $extracted[$key] = $element;
        }

        return $extracted;
    }

    /**
     * Reject any pool config key not in ALLOWED_POOL_KEYS (including non-string keys).
     *
     * @param  string                  $name   Pool name for error messages
     * @param  array<array-key, mixed> $config Raw pool config array
     * @return void
     * @throws InvalidConfigException When an unknown or non-string key is present at the pool level
     */
    private static function assertNoUnknownPoolKeys(string $name, array $config): void
    {
        foreach (array_keys($config) as $key) {
            if (!\is_string($key) || !\in_array($key, self::ALLOWED_POOL_KEYS, true)) {
                throw new InvalidConfigException(
                    'Connection [' . $name . ']: unsupported config key "' . $key . '".',
                );
            }
        }
    }

    /**
     * Extract only the single-connection keys from a pool config (drops pool-only keys).
     *
     * Used to feed the primary entry into validate() and to merge into each replica.
     *
     * @param  array<array-key, mixed> $poolConfig Raw pool config array
     * @return array<string, mixed>                Subset containing only single-connection keys present in $poolConfig
     */
    private static function extractSingleConnectionConfig(array $poolConfig): array
    {
        $result = [];
        foreach (self::ALLOWED_KEYS as $key) {
            if (\array_key_exists($key, $poolConfig)) {
                $result[$key] = $poolConfig[$key];
            }
        }

        return $result;
    }

    /**
     * Validate the `read` array and produce a list of ValidatedConfig replicas.
     *
     * Each replica entry inherits the primary's single-connection keys, with
     * the replica's explicit keys overriding. Pool-only keys (e.g. `health_check`)
     * appearing inside a replica entry are rejected so typos surface immediately.
     *
     * @param  string                  $name                 Pool name for error messages
     * @param  array<array-key, mixed> $poolConfig           Raw pool config array
     * @param  array<string, mixed>    $primarySingleConfig  Primary's single-connection key subset (used for inheritance)
     * @return list<ValidatedConfig>                         Validated replicas in declaration order (empty when `read` is absent)
     * @throws InvalidConfigException                        When `read` is malformed or any replica entry is invalid
     */
    private static function extractReplicas(string $name, array $poolConfig, array $primarySingleConfig): array
    {
        if (!\array_key_exists('read', $poolConfig)) {
            return [];
        }

        $read = $poolConfig['read'];
        if (!\is_array($read)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "read" must be an array.',
            );
        }

        $replicas = [];
        foreach ($read as $index => $replicaOverride) {
            if (!\is_int($index)) {
                throw new InvalidConfigException(
                    'Connection [' . $name . ']: "read" must be a list with integer keys, got string key "' . $index . '".',
                );
            }
            if (!\is_array($replicaOverride)) {
                throw new InvalidConfigException(
                    'Connection [' . $name . ']: "read[' . $index . ']" must be an array.',
                );
            }

            foreach (array_keys($replicaOverride) as $replicaKey) {
                if (!\is_string($replicaKey) || !\in_array($replicaKey, self::ALLOWED_KEYS, true)) {
                    throw new InvalidConfigException(
                        'Connection [' . $name . ']: "read[' . $index . ']" has unsupported key "' . $replicaKey . '". Pool-level keys must be set on the pool itself, not inside read[].',
                    );
                }
            }

            $merged     = array_merge($primarySingleConfig, $replicaOverride);
            $replicas[] = self::validate($name . '.read[' . $index . ']', $merged);
        }

        return $replicas;
    }

    /**
     * Extract the optional `health_check` bool, returning null when absent.
     *
     * @param  string                  $name   Pool name for error messages
     * @param  array<array-key, mixed> $config Pool config array
     * @return bool|null
     * @throws InvalidConfigException When the value is present but not a bool
     */
    private static function extractOptionalHealthCheck(string $name, array $config): ?bool
    {
        if (!\array_key_exists('health_check', $config)) {
            return null;
        }

        $value = $config['health_check'];
        if (!\is_bool($value)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "health_check" must be a boolean.',
            );
        }

        return $value;
    }

    /**
     * Extract an optional replica selector string, restricted to ALLOWED_REPLICA_SELECTORS.
     *
     * @param  string                  $name   Pool name for error messages
     * @param  array<array-key, mixed> $config Pool config array
     * @return string|null
     * @throws InvalidConfigException When the value is present but not in ALLOWED_REPLICA_SELECTORS
     */
    private static function extractOptionalReplicaSelector(string $name, array $config): ?string
    {
        if (!\array_key_exists('replica_selector', $config)) {
            return null;
        }

        $value = $config['replica_selector'];
        if (!\is_string($value)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "replica_selector" must be a string.',
            );
        }
        if (!\in_array($value, self::ALLOWED_REPLICA_SELECTORS, true)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: unsupported replica_selector "' . $value . '". Only \'random\' is supported.',
            );
        }

        return $value;
    }

    /**
     * Extract an optional positive-integer config value (>= 1), returning null when absent.
     *
     * @param  string                  $name   Pool name for error messages
     * @param  array<array-key, mixed> $config Pool config array
     * @param  string                  $key    Key to extract
     * @return int|null
     * @throws InvalidConfigException When the value is present but not an integer or is less than 1
     */
    private static function extractOptionalPositiveInt(string $name, array $config, string $key): ?int
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if (!\is_int($value)) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "' . $key . '" must be an integer.',
            );
        }
        if ($value < 1) {
            throw new InvalidConfigException(
                'Connection [' . $name . ']: config key "' . $key . '" must be >= 1, got ' . $value . '.',
            );
        }

        return $value;
    }
}
