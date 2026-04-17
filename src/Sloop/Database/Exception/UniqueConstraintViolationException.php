<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

/**
 * Thrown on unique key / primary key duplicate entry.
 *
 * MySQL error code 1062 (SQLSTATE 23000).
 */
class UniqueConstraintViolationException extends ConstraintViolationException
{
}
