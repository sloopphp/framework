<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * How one condition joins to the condition before it.
 *
 * Backed by the SQL keyword so that a grammar can write the case straight out.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
enum Conjunction: string
{
    case And = 'AND';
    case Or  = 'OR';
}
