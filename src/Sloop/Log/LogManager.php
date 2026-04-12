<?php

declare(strict_types=1);

namespace Sloop\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\HostnameProcessor;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\WebProcessor;
use RuntimeException;
use Sloop\Support\Arr;

/**
 * Channel factory for Monolog logger instances.
 *
 * Creates, caches, and provides access to named Monolog loggers.
 * Each channel name maps to a single Monolog Logger instance.
 */
final class LogManager
{
    /**
     * Default channel name.
     *
     * @var string
     */
    public readonly string $defaultChannel;

    /**
     * Cached logger instances keyed by channel name.
     *
     * @var array<string, Logger>
     */
    private array $channels = [];

    /**
     * Global processors applied to every channel.
     *
     * @var list<callable(LogRecord): LogRecord>
     */
    private array $globalProcessors = [];

    /**
     * Channel configurations keyed by channel name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $channelConfigs;

    /**
     * Default log level for auto-created channels without configuration.
     *
     * @var Level
     */
    private Level $defaultLevel;

    /**
     * Default stream for auto-created channels without configuration.
     *
     * @var string
     */
    private string $defaultStream;

    /**
     * Custom channel factory resolver.
     *
     * Invoked when a channel uses the `custom` driver. Receives the factory
     * class name and returns an instance implementing ChannelFactoryInterface.
     *
     * @var (callable(string): ChannelFactoryInterface)|null
     */
    private $customFactoryResolver;

    /**
     * Create a new log manager.
     *
     * @param string                                        $defaultChannel        Default channel name
     * @param array<string, array<string, mixed>>           $channels              Channel configurations from config/log.php
     * @param Level                                         $defaultLevel          Default log level for unconfigured channels
     * @param string                                        $defaultStream         Default output stream for unconfigured channels
     * @param (callable(string): ChannelFactoryInterface)|null $customFactoryResolver Resolver for custom driver factories
     * @return void
     */
    public function __construct(
        string $defaultChannel = 'app',
        array $channels = [],
        Level $defaultLevel = Level::Debug,
        string $defaultStream = 'php://stderr',
        ?callable $customFactoryResolver = null,
    ) {
        $this->defaultChannel        = $defaultChannel;
        $this->channelConfigs        = $channels;
        $this->defaultLevel          = $defaultLevel;
        $this->defaultStream         = $defaultStream;
        $this->customFactoryResolver = $customFactoryResolver;
    }

    /**
     * Get the logger for the given channel.
     *
     * Creates a new Monolog Logger based on the channel configuration,
     * or falls back to a default StreamHandler if the channel is not configured.
     *
     * @param  string|null $name Channel name (null = default channel)
     * @return Logger
     */
    public function channel(?string $name = null): Logger
    {
        $name ??= $this->defaultChannel;

        if (!isset($this->channels[$name])) {
            $this->channels[$name] = $this->createLogger($name);
        }

        return $this->channels[$name];
    }

    /**
     * Register a pre-configured Monolog Logger for a channel.
     *
     * Any processors already registered via pushProcessor() are applied
     * to the logger before it is cached.
     *
     * @param  string $name   Channel name
     * @param  Logger $logger Pre-configured Monolog Logger instance
     * @return void
     */
    public function addChannel(string $name, Logger $logger): void
    {
        foreach ($this->globalProcessors as $processor) {
            $logger->pushProcessor($processor);
        }

        $this->channels[$name] = $logger;
    }

    /**
     * Register a processor to apply to every current and future channel.
     *
     * Existing cached channels have the processor pushed immediately so it
     * applies to all subsequent log records.
     *
     * @param  callable(LogRecord): LogRecord $processor Processor callable
     * @return void
     */
    public function pushProcessor(callable $processor): void
    {
        $this->globalProcessors[] = $processor;

        foreach ($this->channels as $logger) {
            $logger->pushProcessor($processor);
        }
    }

    /**
     * Create a new Monolog Logger based on channel configuration.
     *
     * @param  string $name Channel name
     * @return Logger
     * @throws RuntimeException If the configuration is invalid
     */
    private function createLogger(string $name): Logger
    {
        $logger = $this->buildLogger($name);

        foreach ($this->resolveChannelProcessors($name) as $processor) {
            $logger->pushProcessor($processor);
        }

        if ($this->isAutoContextEnabled($name)) {
            foreach ($this->globalProcessors as $processor) {
                $logger->pushProcessor($processor);
            }
        }

        return $logger;
    }

    /**
     * Determine whether the framework context processors (pushed via
     * pushProcessor) should be applied to the given channel.
     *
     * Controlled by the `auto_context` boolean in channel configuration.
     * Defaults to true; set to false to opt out per channel (e.g. pure audit
     * logs that should not carry trace metadata).
     *
     * @param  string $name Channel name
     * @return bool
     */
    private function isAutoContextEnabled(string $name): bool
    {
        $config = $this->channelConfigs[$name] ?? null;
        if ($config === null) {
            return true;
        }

        return Arr::getBool($config, 'auto_context', true);
    }

    /**
     * Build the Monolog Logger from channel configuration without processors.
     *
     * @param  string $name Channel name
     * @return Logger
     * @throws RuntimeException If the configuration is invalid
     */
    private function buildLogger(string $name): Logger
    {
        $config = $this->channelConfigs[$name] ?? null;

        if ($config === null) {
            return new Logger($name, [
                new StreamHandler($this->defaultStream, $this->defaultLevel),
            ]);
        }

        $driver = Arr::getString($config, 'driver', 'stream');

        if ($driver === 'custom') {
            return $this->createCustomLogger($name, $config);
        }

        $handler = $this->createHandler($driver, $config);
        $this->applyFormatter($handler, $config);

        return new Logger($name, [$handler]);
    }

    /**
     * Resolve per-channel processors from channel configuration.
     *
     * Supports the built-in Monolog processors by name (`web`, `introspection`,
     * `memory_usage`, `memory_peak`, `hostname`, `process_id`).
     *
     * @param  string $name Channel name
     * @return list<callable(LogRecord): LogRecord>
     */
    private function resolveChannelProcessors(string $name): array
    {
        $config = $this->channelConfigs[$name] ?? null;
        if ($config === null) {
            return [];
        }

        $names = Arr::stringList($config, 'processors');
        if ($names === []) {
            return [];
        }

        $processors = [];
        foreach ($names as $processorName) {
            $processors[] = $this->resolveNamedProcessor($processorName);
        }

        return $processors;
    }

    /**
     * Instantiate a Monolog built-in processor by name.
     *
     * @param  string $name Processor name
     * @return callable(LogRecord): LogRecord
     * @throws RuntimeException If the name is not recognized
     */
    private function resolveNamedProcessor(string $name): callable
    {
        return match ($name) {
            'web'           => new WebProcessor(),
            'introspection' => new IntrospectionProcessor(),
            'memory_usage'  => new MemoryUsageProcessor(),
            'memory_peak'   => new MemoryPeakUsageProcessor(),
            'hostname'      => new HostnameProcessor(),
            'process_id'    => new ProcessIdProcessor(),
            default         => throw new RuntimeException('Unknown log processor: ' . $name),
        };
    }

    /**
     * Create a handler for a built-in driver.
     *
     * @param  string               $driver Driver name (`stream`, `daily`)
     * @param  array<string, mixed> $config Channel configuration
     * @return HandlerInterface
     * @throws RuntimeException If the driver is unknown or required config is missing
     */
    private function createHandler(string $driver, array $config): HandlerInterface
    {
        $level = $this->resolveLevel(Arr::getString($config, 'level', 'debug'));

        return match ($driver) {
            'stream' => new StreamHandler(
                Arr::getString($config, 'stream', $this->defaultStream),
                $level,
            ),
            'daily' => new RotatingFileHandler(
                $this->requireString($config, 'path', 'daily'),
                Arr::getInt($config, 'days'),
                $level,
            ),
            default => throw new RuntimeException('Unknown log driver: ' . $driver),
        };
    }

    /**
     * Apply a formatter to the handler based on channel configuration.
     *
     * @param  HandlerInterface     $handler Handler to apply the formatter to
     * @param  array<string, mixed> $config  Channel configuration
     * @return void
     */
    private function applyFormatter(HandlerInterface $handler, array $config): void
    {
        $formatter = Arr::getString($config, 'formatter');
        if ($formatter === '') {
            return;
        }

        $instance = match ($formatter) {
            'json'  => new JsonFormatter(),
            'line'  => new LineFormatter(),
            default => throw new RuntimeException('Unknown log formatter: ' . $formatter),
        };

        if (method_exists($handler, 'setFormatter')) {
            $handler->setFormatter($instance);
        }
    }

    /**
     * Create a logger using a custom channel factory.
     *
     * @param  string               $name   Channel name
     * @param  array<string, mixed> $config Channel configuration
     * @return Logger
     * @throws RuntimeException If the factory is missing or invalid
     */
    private function createCustomLogger(string $name, array $config): Logger
    {
        $factoryClass = $this->requireString($config, 'factory', 'custom');

        if ($this->customFactoryResolver === null) {
            throw new RuntimeException(
                'Custom log driver requires a factory resolver. Channel: ' . $name,
            );
        }

        $factory = ($this->customFactoryResolver)($factoryClass);

        return $factory->create($name, $config);
    }

    /**
     * Resolve a PSR-3 level string to a Monolog Level.
     *
     * @param  string $level Level name (`debug`, `info`, `warning`, etc.)
     * @return Level
     * @throws RuntimeException If the level name is not recognized
     */
    private function resolveLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug'     => Level::Debug,
            'info'      => Level::Info,
            'notice'    => Level::Notice,
            'warning'   => Level::Warning,
            'error'     => Level::Error,
            'critical'  => Level::Critical,
            'alert'     => Level::Alert,
            'emergency' => Level::Emergency,
            default     => throw new RuntimeException('Unknown log level: ' . $level),
        };
    }

    /**
     * Require a string value from channel configuration.
     *
     * @param  array<string, mixed> $config Channel configuration
     * @param  string               $key    Configuration key
     * @param  string               $driver Driver name for error context
     * @return string
     * @throws RuntimeException If the key is missing or empty
     */
    private function requireString(array $config, string $key, string $driver): string
    {
        $value = Arr::getString($config, $key);
        if ($value === '') {
            throw new RuntimeException('Log driver "' . $driver . '" requires "' . $key . '" configuration.');
        }

        return $value;
    }
}
