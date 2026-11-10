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
 * once. The subclasses name the area of a setting that the framework checks
 * while setting that area up. Config and Env read every setting without
 * knowing its area, so a mistake they find is reported with this class
 * itself, as is a mistake in a setting without an area of its own.
 */
class InvalidConfigException extends RuntimeException
{
}
