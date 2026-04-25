<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

use LogicException;

/**
 * Thrown when a database connection config is malformed or unsupported.
 *
 * Indicates a programmer error in config files: missing required keys,
 * unsupported drivers, unknown keys, or undefined connection names.
 * Distinct from DatabaseConnectionException, which signals runtime
 * connect failures (host unreachable, auth failure, etc.).
 */
final class InvalidConfigException extends LogicException
{
}
