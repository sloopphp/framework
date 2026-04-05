<?php

declare(strict_types=1);

namespace Sloop\Log;

use InvalidArgumentException;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

/**
 * Application logger with static and instance access.
 *
 * Wraps LogManager to provide PSR-3 compatible instance access
 * via channel selection. Use `Log::channel('name')` to obtain a logger.
 */
final class Log implements LoggerInterface
{
    /**
     * Singleton instance for static access.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Log manager that handles channel resolution.
     *
     * @var LogManager|null
     */
    private ?LogManager $manager = null;

    /**
     * Current channel name (null = default).
     *
     * @var string|null
     */
    private ?string $channelName = null;

    /**
     * Initialize the logger with a LogManager.
     *
     * @param LogManager $manager Log manager instance
     * @return void
     */
    public static function init(LogManager $manager): void
    {
        $instance          = self::getInstance();
        $instance->manager = $manager;
    }

    /**
     * Get a logger instance for the given channel.
     *
     * @param string|null $name Channel name (null = default channel)
     * @return self Log instance bound to the given channel
     */
    public static function channel(?string $name = null): self
    {
        $log              = new self();
        $log->manager     = self::getInstance()->manager;
        $log->channelName = $name;

        return $log;
    }

    /**
     * Get the underlying Monolog Logger for the current channel.
     *
     * @return Logger
     * @throws RuntimeException If the logger has not been initialized
     */
    public static function monolog(): Logger
    {
        return self::getInstance()->resolveLogger();
    }

    /**
     * Log an emergency message.
     *
     * @param string|Stringable $message Log message
     * @param array<mixed>      $context Context data
     * @return void
     */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->resolveLogger()->emergency($message, $context);
    }

    /**
     * Log an alert message.
     *
     * @param string|Stringable $message Log message
     * @param array<mixed>      $context Context data
     * @return void
     */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->resolveLogger()->alert($message, $context);
    }

    /**
     * Log a critical message.
     *
     * @param string|Stringable $message Log message
     * @param array<mixed>      $context Context data
     * @return void
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->resolveLogger()->critical($message, $context);
    }

    /**
     * Log an error message.
     *
     * @param string|Stringable $message Log message
     * @param array<mixed>      $context Context data
     * @return void
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->resolveLogger()->error($message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string|Stringable $message Log message
     * @param array<mixed>      $context Context data
     * @return void
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->resolveLogger()->warning($message, $context);
    }

    /**
     * Log a notice message.
     *
     * @param string|Stringable $message Log message
     * @param array<mixed>      $context Context data
     * @return void
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->resolveLogger()->notice($message, $context);
    }

    /**
     * Log an informational message.
     *
     * @param string|Stringable $message Log message
     * @param array<mixed>      $context Context data
     * @return void
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->resolveLogger()->info($message, $context);
    }

    /**
     * Log a debug message.
     *
     * @param string|Stringable $message Log message
     * @param array<mixed>      $context Context data
     * @return void
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->resolveLogger()->debug($message, $context);
    }

    /**
     * Log a message at the given level.
     *
     * @param mixed             $level   PSR-3 log level
     * @param string|Stringable $message Log message
     * @param array<mixed>      $context Context data
     * @return void
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->resolveLogger()->log(self::toMonologLevel($level), $message, $context);
    }

    /**
     * Convert a PSR-3 log level to a Monolog Level.
     *
     * @param mixed $level PSR-3 log level (string or Level)
     * @return Level
     * @throws InvalidArgumentException If the level is not a valid PSR-3 level
     */
    private static function toMonologLevel(mixed $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        return match ($level) {
            'emergency' => Level::Emergency,
            'alert'     => Level::Alert,
            'critical'  => Level::Critical,
            'error'     => Level::Error,
            'warning'   => Level::Warning,
            'notice'    => Level::Notice,
            'info'      => Level::Info,
            'debug'     => Level::Debug,
            default     => throw new InvalidArgumentException(
                'Invalid log level: ' . (\is_string($level) ? $level : \get_debug_type($level)),
            ),
        };
    }

    /**
     * Reset the singleton instance.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Get or create the singleton instance.
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
     * Resolve the Monolog Logger for the current channel.
     *
     * @return Logger
     * @throws RuntimeException If the logger has not been initialized
     */
    private function resolveLogger(): Logger
    {
        if ($this->manager === null) {
            throw new RuntimeException('Logger has not been initialized. Call Log::init() first.');
        }

        return $this->manager->channel($this->channelName);
    }
}
