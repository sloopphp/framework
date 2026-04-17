<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

/**
 * Thrown on foreign key constraint failure.
 *
 * MySQL error code 1451 (parent row delete/update) or 1452 (child row insert/update).
 */
class ForeignKeyViolationException extends ConstraintViolationException
{
}
