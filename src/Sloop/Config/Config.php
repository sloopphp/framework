<?php

declare(strict_types=1);

namespace Sloop\Config;

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
     * the given environment name.
     *
     * @param string $configPath  Absolute path to the config directory
     * @param string $environment Environment name (e.g. 'production', 'testing')
     * @return void
     * @throws RuntimeException If the config directory does not exist
     * @throws RuntimeException If already loaded
     */
    public static function load(string $configPath, string $environment = ''): void
    {
        $instance = self::getInstance();

        if ($instance->frozen) {
            throw new RuntimeException('Configuration has already been loaded.');
        }

        if (!is_dir($configPath)) {
            throw new RuntimeException(
                'Config directory does not exist: ' . $configPath
            );
        }

        $instance->items = self::loadDirectory($configPath);

        if ($environment !== '') {
            $envPath = $configPath . \DIRECTORY_SEPARATOR . $environment;

            if (is_dir($envPath)) {
                $envItems        = self::loadDirectory($envPath);
                $instance->items = Arr::merge($instance->items, $envItems);
            }
        }

        $instance->frozen = true;
    }

    /**
     * Get a configuration value using dot notation.
     *
     * @param string $key     Dot-notated configuration key (e.g. 'database.host')
     * @param mixed  $default Value to return if the key does not exist
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Arr::get(self::getInstance()->items, $key, $default);
    }

    /**
     * Check if a configuration key exists.
     *
     * @param string $key Dot-notated configuration key
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
     * @param array<string, mixed> $overrides Key-value pairs to override (dot notation supported)
     * @param callable(): T        $callback  Code to execute with overrides
     * @return T
     * @throws \Throwable If the callback throws an exception
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
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Load all PHP files from a directory as configuration arrays.
     *
     * Each file is expected to return an array. The filename (without extension)
     * becomes the top-level key.
     *
     * @param string $directory Absolute path to the directory
     * @return array<string, mixed>
     */
    private static function loadDirectory(string $directory): array
    {
        $items = [];
        $files = glob($directory . \DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            return $items;
        }

        foreach ($files as $file) {
            $key   = basename($file, '.php');
            $value = require $file;

            if (\is_array($value)) {
                $items[$key] = $value;
            }
        }

        return $items;
    }
}
