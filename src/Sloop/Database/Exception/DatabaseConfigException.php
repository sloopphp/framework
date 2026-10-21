<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

use Sloop\Config\InvalidConfigException;

/**
 * Thrown when a database connection config is malformed or unsupported.
 *
 * Covers missing required keys, unsupported drivers, unknown keys, and
 * undefined connection names. Distinct from DatabaseConnectionException,
 * which signals runtime connect failures (host unreachable, auth failure,
 * etc.), and not a DatabaseException, so catching those does not hide a
 * config mistake.
 */
final class DatabaseConfigException extends InvalidConfigException
{
}
