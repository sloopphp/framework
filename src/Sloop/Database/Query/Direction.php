<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * Sort direction of one ORDER BY term.
 *
 * Backed by the SQL keyword so that a grammar can write the case straight out.
 */
enum Direction: string
{
    case Ascending  = 'ASC';
    case Descending = 'DESC';
}
