<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

/**
 * Base exception for integrity constraint violations (SQLSTATE 23xxx).
 *
 * Subclassed by UniqueConstraintViolationException and ForeignKeyViolationException.
 * Can also be caught directly for generic constraint errors.
 */
class ConstraintViolationException extends QueryException
{
}
