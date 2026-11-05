<?php

declare(strict_types=1);

namespace Sloop\Support;

use InvalidArgumentException;
use Sloop\Config\InvalidConfigException;

/**
 * Immutable environment variable accessor.
 *
 * Once immutable mode is enabled, the environment as it stood at that moment
 * is fixed for the process lifetime: a variable set later is not seen, and
 * one changed later keeps its earlier value. Use withEnv() for temporary
 * overrides in tests.
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
     * @param  string                   $key      Environment variable name
     * @param  string|null              $default  Default value if not set (incompatible with required: true)
     * @param  bool                     $required Throw exception if not set (incompatible with default)
     * @return string|null
     * @throws InvalidArgumentException If required and default are both specified
     * @throws InvalidConfigException   If required and the variable is not set
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

        // In immutable mode the cache holds every variable that was set when
        // the mode was enabled, so one missing from it is not set.
        $value = self::$immutable ? false : getenv($key);

        if ($value === false) {
            if ($required) {
                throw new InvalidConfigException(
                    'Required environment variable \'' . $key . '\' is not set.'
                );
            }

            return $default;
        }

        return $value;
    }

    /**
     * Enable immutable mode, fixing the environment as it stands now.
     *
     * Every variable is read once here; afterwards get() no longer reads the
     * process environment, so a variable set or changed later is not seen.
     * Calling this again while the mode is on keeps the values fixed the
     * first time. Application calls it at boot, before the config is read.
     *
     * @return void
     */
    public static function enableImmutable(): void
    {
        if (self::$immutable) {
            return;
        }

        // A value withEnv() is holding wins over the process environment. The
        // union keeps a variable named with digits under its own name.
        self::$cache     = self::$cache + getenv();
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
     * Original environment and cache state are restored after execution. When
     * the callback enables immutable mode (by booting an Application), the
     * overridden variables are fixed at their original values afterwards.
     *
     * @template T
     * @param  array<string, string|null> $variables Variables to set (null to unset)
     * @param  callable(): T              $callback  Code to execute with overrides
     * @return T
     */
    public static function withEnv(array $variables, callable $callback): mixed
    {
        $originalEnv   = [];
        $originalCache = [];
        $hadCache      = [];
        $wasImmutable  = self::$immutable;

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
                } elseif (!$wasImmutable && self::$immutable && $originalEnv[$key] !== false) {
                    // Immutable mode was enabled inside the callback and fixed
                    // the override; fix the value the process really had.
                    self::$cache[$key] = $originalEnv[$key];
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
