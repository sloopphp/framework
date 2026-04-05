<?php

declare(strict_types=1);

namespace Sloop\Log;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

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
     * Default log level for auto-created channels.
     *
     * @var Level
     */
    private Level $defaultLevel;

    /**
     * Default stream for auto-created channels.
     *
     * @var string
     */
    private string $defaultStream;

    /**
     * Create a new log manager.
     *
     * @param string $defaultChannel Default channel name
     * @param Level  $defaultLevel   Default log level for auto-created channels
     * @param string $defaultStream  Default output stream for auto-created channels
     * @return void
     */
    public function __construct(
        string $defaultChannel = 'app',
        Level $defaultLevel = Level::Debug,
        string $defaultStream = 'php://stderr',
    ) {
        $this->defaultChannel = $defaultChannel;
        $this->defaultLevel   = $defaultLevel;
        $this->defaultStream  = $defaultStream;
    }

    /**
     * Get the logger for the given channel.
     *
     * Creates a new Monolog Logger with a StreamHandler if the channel
     * has not been resolved before.
     *
     * @param string|null $name Channel name (null = default channel)
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
     * @param string $name   Channel name
     * @param Logger $logger Pre-configured Monolog Logger instance
     * @return void
     */
    public function addChannel(string $name, Logger $logger): void
    {
        $this->channels[$name] = $logger;
    }

    /**
     * Create a new Monolog Logger with a default StreamHandler.
     *
     * @param string $name Channel name
     * @return Logger
     */
    private function createLogger(string $name): Logger
    {
        return new Logger($name, [
            new StreamHandler($this->defaultStream, $this->defaultLevel),
        ]);
    }
}
