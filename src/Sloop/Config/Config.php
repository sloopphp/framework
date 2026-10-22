<?php

declare(strict_types=1);

namespace Sloop\Config;

use LogicException;
use RuntimeException;
use Sloop\Support\Arr;

/**
 * Application configuration management.
 *
 * Loads PHP array files from a config directory, merges environment-specific
 * overrides, and provides dot-notation access. Immutable after loading.
 */
final class Config
{
    /**
     * Singleton instance for static access.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * All loaded configuration values.
     *
     * @var array<array-key, mixed>
     */
    private array $items = [];

    /**
     * Whether the configuration has been loaded and frozen.
     *
     * @var bool
     */
    private bool $frozen = false;

    /**
     * Load configuration files from the given directory.
     *
     * Reads all PHP files in the base config directory, then merges
     * environment-specific overrides from the subdirectory matching
     * the given environment name. An array with only integer keys in an
     * override replaces a base array that also has only integer keys (or a
     * missing or non-array value) instead of being appended to it. An array
     * whose shape differs from the base array's (integer keys only against
     * string keys, or the reverse) is rejected.
     *
     * @param  string                 $configPath  Absolute path to the config directory
     * @param  string|null            $environment Environment name (e.g. 'production', 'testing')
     * @return void
     * @throws InvalidConfigException If the config directory does not exist, a config file does not return an array, the environment name contains characters outside [A-Za-z0-9_-], or an environment override differs in shape from the base
     * @throws LogicException         If already loaded
     * @throws RuntimeException       If the config directory cannot be scanned
     */
    public static function load(string $configPath, ?string $environment = null): void
    {
        $instance = self::getInstance();

        // The environment name becomes a path segment under the config
        // directory whose PHP files get executed; reject anything that could
        // escape it (e.g. an APP_ENV of '../../evil' from a tainted process env).
        if ($environment !== null && preg_match('/\A[A-Za-z0-9_-]+\z/', $environment) !== 1) {
            throw new InvalidConfigException(
                'Invalid environment name: ' . $environment
                . ' (only [A-Za-z0-9_-] is allowed)'
            );
        }

        if ($instance->frozen) {
            throw new LogicException('Configuration has already been loaded.');
        }

        if (!is_dir($configPath)) {
            throw new InvalidConfigException(
                'Config directory does not exist: ' . $configPath
            );
        }

        $instance->items = self::loadDirectory($configPath);

        if ($environment !== null) {
            $envPath = $configPath . \DIRECTORY_SEPARATOR . $environment;

            if (is_dir($envPath)) {
                $envItems        = self::loadDirectory($envPath);
                $instance->items = self::mergeOverride($instance->items, $envItems, $environment);
            }
        }

        $instance->frozen = true;
    }

    /**
     * Get a configuration value using dot notation.
     *
     * Returns `mixed` because a config file may hold any value at any depth.
     * Prefer getString() / getInt() / getFloat() / getBool() so that the
     * calling code receives a concrete type instead of propagating `mixed`
     * into the application layer.
     *
     * @param  string $key     Dot-notated configuration key (e.g. 'database.host')
     * @param  mixed  $default Value to return if the key does not exist
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Arr::get(self::getInstance()->items, $key, $default);
    }

    /**
     * Get a string configuration value using dot notation.
     *
     * Returns the default if the key is missing or the value is not a string.
     * Strict: only actual string values are accepted (no implicit casting).
     *
     * @param  string $key     Dot-notated configuration key
     * @param  string $default Value to return if the key is missing or not a string
     * @return string
     */
    public static function getString(string $key, string $default = ''): string
    {
        return Arr::getString(self::getInstance()->items, $key, $default);
    }

    /**
     * Get an int configuration value using dot notation.
     *
     * Returns the default if the key is missing or the value is not an int.
     * Strict: numeric strings such as '3306' are not accepted, so a port
     * written as a quoted string in a config file falls back to the default
     * rather than being silently converted.
     *
     * @param  string $key     Dot-notated configuration key
     * @param  int    $default Value to return if the key is missing or not an int
     * @return int
     */
    public static function getInt(string $key, int $default = 0): int
    {
        return Arr::getInt(self::getInstance()->items, $key, $default);
    }

    /**
     * Get a float configuration value using dot notation.
     *
     * Returns the default if the key is missing or the value is neither float
     * nor int. Int values are promoted to float to match PHP's native type
     * promotion behavior.
     *
     * @param  string $key     Dot-notated configuration key
     * @param  float  $default Value to return if the key is missing or not numeric
     * @return float
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        return Arr::getFloat(self::getInstance()->items, $key, $default);
    }

    /**
     * Get a bool configuration value using dot notation.
     *
     * Returns the default if the key is missing or the value is not a bool.
     * Strict: truthy values such as 1 or 'true' are not accepted. Values that
     * come from environment variables should be normalized by Env before
     * being placed into a config file.
     *
     * @param  string $key     Dot-notated configuration key
     * @param  bool   $default Value to return if the key is missing or not a bool
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        return Arr::getBool(self::getInstance()->items, $key, $default);
    }

    /**
     * Check if a configuration key exists.
     *
     * @param  string $key Dot-notated configuration key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return Arr::has(self::getInstance()->items, $key);
    }

    /**
     * Get all configuration values.
     *
     * @return array<array-key, mixed>
     */
    public static function all(): array
    {
        return self::getInstance()->items;
    }

    /**
     * Execute a callback with temporary configuration overrides.
     *
     * Overrides bypass the frozen state for the duration of the callback.
     * Original configuration is restored after execution.
     *
     * @template T
     * @param  array<string, mixed> $overrides Key-value pairs to override (dot notation supported)
     * @param  callable(): T        $callback  Code to execute with overrides
     * @return T
     */
    public static function withConfig(array $overrides, callable $callback): mixed
    {
        $instance = self::getInstance();
        $original = $instance->items;

        try {
            foreach ($overrides as $key => $value) {
                $instance->items = Arr::set($instance->items, $key, $value);
            }

            return $callback();
        } finally {
            $instance->items = $original;
        }
    }

    /**
     * Check if the configuration has been loaded.
     *
     * @return bool
     */
    public static function isLoaded(): bool
    {
        return self::getInstance()->frozen;
    }

    /**
     * Reset all configuration state. Intended for testing only.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Get the singleton instance.
     *
     * @return self
     */
    private static function getInstance(): self
    {
        self::$instance ??= new self();

        return self::$instance;
    }

    /**
     * Merge environment overrides into the base configuration.
     *
     * Arrays with string keys merge recursively. An array whose keys are all
     * integers (a list, a list with gaps such as array_filter() leaves, or a
     * map of PDO attributes) replaces such an array, or a missing or
     * non-array value, as a whole and keeps its keys, so the base entries do
     * not stay. An array over an array of the other shape (integer keys only
     * over one with a string key, the empty array included, or the reverse)
     * would mix the two, so it is rejected; an empty base array takes either
     * shape. Integer keys inside an array with string keys are appended.
     *
     * @param  array<array-key, mixed> $base        Base configuration
     * @param  array<array-key, mixed> $override    Environment overrides
     * @param  string                  $environment Environment name, for the exception message
     * @param  string                  $prefix      Dot-notated key of $base, empty at the top
     * @return array<array-key, mixed>
     * @throws InvalidConfigException  If an override array and the array it falls on differ in shape
     */
    private static function mergeOverride(array $base, array $override, string $environment, string $prefix = ''): array
    {
        foreach ($override as $key => $value) {
            $current = $base[$key] ?? null;

            if (\is_int($key)) {
                $base[] = $value;
            } elseif (!\is_array($value) || !\is_array($current) || $current === []) {
                $base[$key] = $value;
            } elseif (self::hasOnlyIntKeys($value) !== self::hasOnlyIntKeys($current)) {
                throw new InvalidConfigException(
                    'Config "' . $prefix . $key . '" in environment "' . $environment . '" is '
                    . (self::hasOnlyIntKeys($value) ? 'a list' : 'an array with string keys')
                    . ' but the base value is ' . (self::hasOnlyIntKeys($current) ? 'a list' : 'an array with string keys')
                    . '; write the same shape as the base.',
                );
            } elseif (self::hasOnlyIntKeys($value)) {
                $base[$key] = $value;
            } else {
                $base[$key] = self::mergeOverride($current, $value, $environment, $prefix . $key . '.');
            }
        }

        return $base;
    }

    /**
     * Check whether every key of the array is an integer (true for an empty array).
     *
     * @param  array<array-key, mixed> $array Array to check
     * @return bool
     */
    private static function hasOnlyIntKeys(array $array): bool
    {
        foreach (array_keys($array) as $key) {
            if (!\is_int($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Load all PHP files from a directory as configuration arrays.
     *
     * Each file is expected to return an array. The filename (without extension)
     * becomes the top-level key.
     *
     * @param  string                 $directory Absolute path to the directory
     * @return array<string, mixed>
     * @throws RuntimeException       If the directory cannot be scanned
     * @throws InvalidConfigException If a config file does not return an array
     */
    private static function loadDirectory(string $directory): array
    {
        $items = [];
        $files = glob($directory . \DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            throw new RuntimeException(
                'Failed to scan config directory: ' . $directory
            );
        }

        foreach ($files as $file) {
            $key   = basename($file, '.php');
            $value = require $file;

            if (!\is_array($value)) {
                throw new InvalidConfigException(
                    'Config file must return an array, got ' . get_debug_type($value) . ': ' . $file
                );
            }

            $items[$key] = $value;
        }

        return $items;
    }
}
