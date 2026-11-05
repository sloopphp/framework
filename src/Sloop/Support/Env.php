<?php

declare(strict_types=1);

namespace Sloop\Support;

use InvalidArgumentException;
use Sloop\Config\InvalidConfigException;

/**
 * Immutable environment variable accessor.
 *
 * Once immutable mode is enabled, each variable keeps the value it had when
 * the mode was enabled, or, for a variable the process environment did not
 * list then, the value (or absence) it had when first read. Use withEnv() for
 * temporary overrides in tests.
 */
final class Env
{
    /**
     * Cached environment variable values; false marks a variable fixed as not set.
     *
     * A variable named with digits only is keyed by an int, as PHP keys it.
     *
     * @var array<array-key, string|false>
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
        } else {
            $value = getenv($key);

            // A name enableImmutable() did not fix is fixed here, at its first
            // read, absence included.
            if (self::$immutable) {
                self::$cache[$key] = $value;
            }
        }

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
     * Every variable the process environment lists is read once here and keeps
     * that value. A name it does not list is read once, at its first get(),
     * and keeps that value or its absence. Calling this again while the mode
     * is on keeps the values fixed the first time. Application calls it at
     * boot, before the config is read.
     *
     * @return void
     */
    public static function enableImmutable(): void
    {
        if (self::$immutable) {
            return;
        }

        // getenv() without a name only supplies the names. Each value is read
        // with getenv($name), as get() reads it: under Apache's module that
        // prefers a SetEnv value over the process one, and under FPM a
        // parameter whose name PHP rewrote ('.' to '_', 'a[b]' to an array)
        // finds nothing under the rewritten name and is fixed as not set.
        $fixed = [];
        foreach (array_keys(self::processEnvironment()) as $name) {
            $fixed[$name] = getenv((string) $name);
        }

        // A value withEnv() is holding wins over the process environment. The
        // union keeps a variable named with digits under its own name.
        self::$cache     = self::$cache + $fixed;
        self::$immutable = true;
    }

    /**
     * The variables getenv() without a name lists.
     *
     * PHP keys a name made of digits only by an int, so the keys are not all
     * strings.
     *
     * @return array<array-key, string>
     */
    private static function processEnvironment(): array
    {
        return getenv();
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
     * the callback enables immutable mode (by booting an Application), an
     * overridden variable not fixed before is read again, at its next get(),
     * from the restored environment.
     *
     * @template T
     * @param  array<array-key, string|null> $variables Variables to set (null to unset); a name made of digits only arrives as an int key
     * @param  callable(): T                 $callback  Code to execute with overrides
     * @return T
     */
    public static function withEnv(array $variables, callable $callback): mixed
    {
        $originalEnv   = [];
        $originalCache = [];
        $hadCache      = [];

        // A name made of digits only arrives as an int key, which getenv() and
        // putenv() do not take under strict types; it is passed as a string.
        foreach ($variables as $key => $value) {
            $originalEnv[$key] = getenv((string) $key);
            $hadCache[$key]    = \array_key_exists($key, self::$cache);

            if ($hadCache[$key]) {
                $originalCache[$key] = self::$cache[$key];
            }

            if ($value === null) {
                putenv((string) $key);
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
                    putenv((string) $key);
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
