<?php

declare(strict_types=1);

namespace Sloop\Log;

use Sloop\Log\Exception\LogConfigException;

/**
 * Checks the log.channels config so that a malformed channel fails the boot.
 *
 * LogManager reads a channel only when it is first used, and falls back to
 * a default for a value of the wrong type. Application runs this check at
 * boot instead, so a mistyped key or value surfaces at deploy time.
 *
 * A custom channel is passed to its factory, which may read any key, so
 * only the entries LogManager itself reads (driver, factory, processors,
 * auto_context) are checked for it.
 *
 * @internal
 */
final class LogConfigValidator
{
    /**
     * Drivers LogManager builds a channel for.
     *
     * @var list<string>
     */
    public const array DRIVERS = ['stream', 'daily', 'custom'];

    /**
     * Level names LogManager accepts, matched without regard to case.
     *
     * @var list<string>
     */
    public const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Formatter names LogManager accepts.
     *
     * @var list<string>
     */
    public const array FORMATTERS = ['json', 'line'];

    /**
     * Built-in processor names LogManager accepts.
     *
     * @var list<string>
     */
    public const array PROCESSORS = ['web', 'introspection', 'memory_usage', 'memory_peak', 'hostname', 'process_id'];

    /**
     * Keys a stream or daily channel may contain, by driver.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED_KEYS = [
        'stream' => ['driver', 'level', 'formatter', 'processors', 'auto_context', 'stream'],
        'daily'  => ['driver', 'level', 'formatter', 'processors', 'auto_context', 'path', 'days'],
    ];

    /**
     * Check log.channels and return it typed for LogManager.
     *
     * A null value, for log.channels or for one channel, is treated as not
     * written, as elsewhere in the config: an environment file can drop a
     * channel by writing null for it.
     *
     * @param  mixed                               $channels Value of log.channels
     * @return array<string, array<string, mixed>>
     * @throws LogConfigException                  If log.channels is not an array of named channels, or a channel is malformed
     */
    public static function validateChannels(mixed $channels): array
    {
        if ($channels === null) {
            return [];
        }

        if (!\is_array($channels)) {
            throw new LogConfigException('log.channels must be an array of channel names mapped to settings.');
        }

        $validated = [];
        foreach ($channels as $name => $config) {
            if ($config === null) {
                continue;
            }

            if (!\is_string($name)) {
                throw new LogConfigException('log.channels has a channel without a name (key ' . $name . '); write the channel name as the key.');
            }

            $validated[$name] = self::validateChannel('log.channels.' . $name, $config);
        }

        return $validated;
    }

    /**
     * Check one channel and return it with string keys only.
     *
     * @param  string               $path   Config key of the channel, for messages
     * @param  mixed                $config Channel config
     * @return array<string, mixed>
     * @throws LogConfigException   If the channel is malformed
     */
    private static function validateChannel(string $path, mixed $config): array
    {
        if (!\is_array($config)) {
            throw new LogConfigException($path . ' must be an array of settings.');
        }

        $driver = $config['driver'] ?? 'stream';
        if (!\is_string($driver) || !\in_array($driver, self::DRIVERS, true)) {
            throw new LogConfigException($path . '.driver must be one of: ' . implode(', ', self::DRIVERS) . '.');
        }

        if ($driver !== 'custom') {
            self::assertKnownKeys($path, $driver, $config);
        }

        // A custom channel may hold keys of any kind for its factory; the
        // ones without a string name are left out, as LogManager types them.
        $typed = array_filter($config, \is_string(...), ARRAY_FILTER_USE_KEY);

        self::assertProcessors($path, $typed['processors'] ?? null);
        self::assertBool($path . '.auto_context', $typed['auto_context'] ?? null);

        if ($driver === 'custom') {
            self::assertRequiredString($path . '.factory', $typed['factory'] ?? null, 'the class name of a ChannelFactoryInterface implementation');
        } else {
            self::assertBuiltInEntries($path, $driver, $typed);
        }

        return $typed;
    }

    /**
     * Check that a stream or daily channel has only the keys its driver reads.
     *
     * @param  string                  $path   Config key of the channel, for messages
     * @param  string                  $driver stream or daily
     * @param  array<array-key, mixed> $config Channel config
     * @return void
     * @throws LogConfigException      If the channel has another key
     */
    private static function assertKnownKeys(string $path, string $driver, array $config): void
    {
        foreach (array_keys($config) as $key) {
            if (!\in_array($key, self::ALLOWED_KEYS[$driver], true)) {
                throw new LogConfigException($path . ' has an unsupported key "' . $key . '".');
            }
        }
    }

    /**
     * Check the entries a stream or daily channel reads.
     *
     * @param  string               $path   Config key of the channel, for messages
     * @param  string               $driver stream or daily
     * @param  array<string, mixed> $config Channel config
     * @return void
     * @throws LogConfigException   If an entry is malformed, or a daily channel has no path
     */
    private static function assertBuiltInEntries(string $path, string $driver, array $config): void
    {
        self::assertName($path . '.level', $config['level'] ?? null, self::LEVELS, true);
        self::assertFormatter($path . '.formatter', $config['formatter'] ?? null);

        if ($driver === 'stream') {
            $stream = $config['stream'] ?? null;
            if ($stream !== null) {
                self::assertRequiredString($path . '.stream', $stream, 'a stream path or URL');
            }

            return;
        }

        self::assertRequiredString($path . '.path', $config['path'] ?? null, 'the file the daily driver writes to');
        $days = $config['days'] ?? null;
        if ($days !== null && !\is_int($days)) {
            throw new LogConfigException($path . '.days must be an integer.');
        }
    }

    /**
     * Check that a written entry is one of the given names.
     *
     * @param  string             $path       Config key, for messages
     * @param  mixed              $value      Entry value (null when not written)
     * @param  list<string>       $names      Accepted names
     * @param  bool               $ignoreCase Whether the name is matched without regard to case
     * @return void
     * @throws LogConfigException If the entry is written and is not one of the names
     */
    private static function assertName(string $path, mixed $value, array $names, bool $ignoreCase): void
    {
        if ($value === null) {
            return;
        }

        if (!\is_string($value) || !\in_array($ignoreCase ? strtolower($value) : $value, $names, true)) {
            throw new LogConfigException($path . ' must be one of: ' . implode(', ', $names) . '.');
        }
    }

    /**
     * Check a written formatter entry.
     *
     * An empty string means no formatter, as LogManager reads it.
     *
     * @param  string             $path  Config key, for messages
     * @param  mixed              $value Entry value (null when not written)
     * @return void
     * @throws LogConfigException If the entry names no formatter LogManager knows
     */
    private static function assertFormatter(string $path, mixed $value): void
    {
        if ($value === '') {
            return;
        }

        self::assertName($path, $value, self::FORMATTERS, false);
    }

    /**
     * Check a written processors entry.
     *
     * @param  string             $path  Config key of the channel, for messages
     * @param  mixed              $value Entry value (null when not written)
     * @return void
     * @throws LogConfigException If the entry is not a list of known processor names
     */
    private static function assertProcessors(string $path, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (!\is_array($value)) {
            throw new LogConfigException($path . '.processors must be a list of processor names.');
        }

        foreach ($value as $name) {
            if (!\is_string($name) || !\in_array($name, self::PROCESSORS, true)) {
                throw new LogConfigException($path . '.processors must contain only: ' . implode(', ', self::PROCESSORS) . '.');
            }
        }
    }

    /**
     * Check that a written entry is a boolean.
     *
     * @param  string             $path  Config key, for messages
     * @param  mixed              $value Entry value (null when not written)
     * @return void
     * @throws LogConfigException If the entry is written and is not a boolean
     */
    private static function assertBool(string $path, mixed $value): void
    {
        if ($value !== null && !\is_bool($value)) {
            throw new LogConfigException($path . ' must be true or false.');
        }
    }

    /**
     * Check that an entry the driver needs is a non-empty string.
     *
     * @param  string             $path        Config key, for messages
     * @param  mixed              $value       Entry value (null when not written)
     * @param  string             $description What the entry holds, for messages
     * @return void
     * @throws LogConfigException If the entry is not a non-empty string
     */
    private static function assertRequiredString(string $path, mixed $value, string $description): void
    {
        if (!\is_string($value) || $value === '') {
            throw new LogConfigException($path . ' must be ' . $description . '.');
        }
    }
}
