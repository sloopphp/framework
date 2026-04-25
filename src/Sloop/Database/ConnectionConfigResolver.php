<?php

declare(strict_types=1);

namespace Sloop\Database;

use PDO;
use Pdo\Mysql as PdoMysql;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * Pure transformation: connection config array → ValidatedConfig → DSN / PDO options.
 *
 * Separated from ConnectionManager so that config interpretation can be
 * unit-tested without instantiating PDO, and so that later sub-phases
 * (replica resolution in sub C, etc.) share the same logic.
 */
final class ConnectionConfigResolver
{
    /**
     * Config keys recognized by sub B. Later sub-phases extend this list
     * (see Phase 5-1 sub-phase breakdown in docs/v0.1-plan.md).
     *
     * Unknown keys are rejected by validate() to prevent silently
     * ineffective configuration.
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
     * Required config keys; absence causes InvalidConfigException.
     *
     * @var list<string>
     */
    private const array REQUIRED_KEYS = ['driver', 'host', 'database'];

    /**
     * Drivers accepted by sub B (v0.1 only supports MySQL/MariaDB via mysql driver).
     *
     * @var list<string>
     */
    private const array ALLOWED_DRIVERS = ['mysql'];

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
     * Validate a connection config and return a typed ValidatedConfig.
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
}
