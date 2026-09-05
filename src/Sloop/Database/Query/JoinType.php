<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * Which rows a join keeps when the ON clause finds no match.
 *
 * Backed by the keyword a grammar writes, so the three kinds cannot be spelled
 * inconsistently. INNER is spelled as the bare keyword because that is what
 * MySQL reads a join without a qualifier as, and writing it out adds nothing.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
enum JoinType: string
{
    case Inner = 'JOIN';
    case Left  = 'LEFT JOIN';
    case Right = 'RIGHT JOIN';
}
