<?php

declare(strict_types=1);

namespace Sloop\Log\Exception;

use Sloop\Config\InvalidConfigException;

/**
 * Thrown when a log channel config is malformed.
 *
 * Covers a log config that is not an array of settings or has an unknown
 * key, an unknown driver, formatter, level or processor name, a value of the
 * wrong type, a driver missing a setting it requires (such as the path of a
 * daily file), log.default naming an undefined channel, and a custom channel
 * whose factory class cannot be resolved or does not implement
 * ChannelFactoryInterface.
 * Application checks the config when it boots; the factory of a custom
 * channel is resolved, and a LogManager created directly checks a channel,
 * when the channel is first used.
 */
final class LogConfigException extends InvalidConfigException
{
}
