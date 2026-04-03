<?php

declare(strict_types=1);

namespace Sloop\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable environment variable accessor.
 *
 * Values are cached on first access and remain immutable for the process lifetime.
 * Use withEnv() for temporary overrides in tests.
 */
final class Env
{
    /**
     * Cached environment variable values.
     *
     * @var array<string, string|false>
     */
    private static array $cache = [];

    /**
     * Whether immutable mode is enabled.
     *
     * @var bool
     */
    private static bool $immutable = false;

    /**
     * Get an environment variable value.
     *
     * @param string      $key      Environment variable name
     * @param string|null $default  Default value if not set (incompatible with required: true)
     * @param bool        $required Throw exception if not set (incompatible with default)
     * @return string|null
     * @throws InvalidArgumentException If required and default are both specified
     * @throws RuntimeException         If required and the variable is not set
     */
    public static function get(
        string $key,
        ?string $default = null,
        bool $required = false,
    ): ?string {
        if ($required && $default !== null) {
            throw new InvalidArgumentException(
                'Cannot specify both \'required: true\' and \'default\' for env var \'' . $key . '\'.'
            );
        }

        if (\array_key_exists($key, self::$cache)) {
            $value = self::$cache[$key];

            return $value === false ? $default : $value;
        }

        $value = getenv($key);

        if (self::$immutable && $value !== false) {
            self::$cache[$key] = $value;
        }

        if ($value === false) {
            if ($required) {
                throw new RuntimeException(
                    'Required environment variable \'' . $key . '\' is not set.'
                );
            }

            return $default;
        }

        return $value;
    }

    /**
     * Enable immutable mode. Once enabled, cached values cannot be changed.
     *
     * @return void
     */
    public static function enableImmutable(): void
    {
        self::$immutable = true;
    }

    /**
     * Check if immutable mode is enabled.
     *
     * @return bool
     */
    public static function isImmutable(): bool
    {
        return self::$immutable;
    }

    /**
     * Execute a callback with temporary environment variable overrides.
     *
     * Overrides bypass the immutable cache for the duration of the callback.
     * Original environment and cache state are restored after execution.
     *
     * @template T
     * @param array<string, string|null> $variables Variables to set (null to unset)
     * @param callable(): T              $callback  Code to execute with overrides
     * @return T
     * @throws \Throwable If the callback throws an exception
     */
    public static function withEnv(array $variables, callable $callback): mixed
    {
        $originalEnv   = [];
        $originalCache = [];
        $hadCache      = [];

        foreach ($variables as $key => $value) {
            $originalEnv[$key] = getenv($key);
            $hadCache[$key]    = \array_key_exists($key, self::$cache);

            if ($hadCache[$key]) {
                $originalCache[$key] = self::$cache[$key];
            }

            if ($value === null) {
                putenv($key);
                unset(self::$cache[$key]);
            } else {
                putenv($key . '=' . $value);
                self::$cache[$key] = $value;
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($variables as $key => $ignored) {
                if ($originalEnv[$key] === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $originalEnv[$key]);
                }

                if ($hadCache[$key]) {
                    self::$cache[$key] = $originalCache[$key];
                } else {
                    unset(self::$cache[$key]);
                }
            }
        }
    }

    /**
     * Reset all cached values and immutable state. Intended for testing only.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$cache     = [];
        self::$immutable = false;
    }
}
