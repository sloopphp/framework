<?php

declare(strict_types=1);

namespace Sloop\Log\Exception;

use Sloop\Config\InvalidConfigException;

/**
 * Thrown when a log channel config is malformed.
 *
 * Covers an unknown driver, formatter, level or processor name, a driver
 * missing a setting it requires (such as the path of a daily file), and a
 * custom channel whose factory class cannot be resolved or does not
 * implement ChannelFactoryInterface.
 * The error surfaces when the channel is first used.
 */
final class LogConfigException extends InvalidConfigException
{
}
