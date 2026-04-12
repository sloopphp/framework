<?php

declare(strict_types=1);

namespace Sloop\Log;

use Monolog\Logger;

/**
 * Factory contract for creating custom log channels.
 *
 * Implement this interface to register custom log drivers (syslog, fluentd,
 * Slack, etc.) through the `custom` driver in the log configuration.
 */
interface ChannelFactoryInterface
{
    /**
     * Create a Monolog Logger for the given channel.
     *
     * @param  string               $name   Channel name
     * @param  array<string, mixed> $config Channel configuration from config/log.php
     * @return Logger
     */
    public function create(string $name, array $config): Logger;
}
