<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * Which end of a parenthesised group a boundary stands at.
 *
 * Backed by the character a grammar writes, so that the two ends cannot be
 * spelled inconsistently.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
enum GroupEdge: string
{
    case Open  = '(';
    case Close = ')';
}
