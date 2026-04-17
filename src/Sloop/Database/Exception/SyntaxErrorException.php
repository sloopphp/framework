<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

/**
 * Thrown on SQL syntax errors.
 *
 * SQLSTATE 42000 / MySQL error code 1064.
 * Indicates a bug in generated SQL — should not occur in normal operation.
 */
final class SyntaxErrorException extends QueryException
{
}
