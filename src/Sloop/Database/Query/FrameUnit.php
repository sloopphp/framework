<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * How the offsets of a window frame are counted.
 *
 * Backed by the SQL keyword so that a grammar can write the case straight out.
 * GROUPS is not here: neither MySQL nor MariaDB implements it.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
enum FrameUnit: string
{
    case Rows  = 'ROWS';
    case Range = 'RANGE';
}
