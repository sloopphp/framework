<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * How one condition joins to the condition before it.
 *
 * Backed by the SQL keyword so that a grammar can write the case straight out.
 */
enum Conjunction: string
{
    case And = 'AND';
    case Or  = 'OR';
}
