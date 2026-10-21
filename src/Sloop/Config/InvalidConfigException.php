<?php

declare(strict_types=1);

namespace Sloop\Config;

use RuntimeException;

/**
 * Thrown when a setting the application reads at runtime is missing or malformed.
 *
 * Settings come from the config files, their per-environment overrides and
 * environment variables, so a mistake in them shows only once the
 * application runs where it is deployed; that is why this extends
 * RuntimeException. Catch this class to handle every kind of config error at
 * once. The subclasses name the area the setting belongs to; Config::load()
 * and Env::get() throw this class itself.
 */
class InvalidConfigException extends RuntimeException
{
}
