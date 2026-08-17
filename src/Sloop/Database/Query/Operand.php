<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * What a comparison operator takes on its right-hand side.
 *
 * The distinction is what tells a bound value apart from a keyword: MySQL reads
 * what follows IS as one of NULL, TRUE, FALSE or UNKNOWN, so a placeholder
 * there is a syntax error rather than a comparison.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
enum Operand
{
    /**
     * A value, bound through a placeholder. Null is refused, because a
     * comparison against NULL evaluates to unknown rather than true and so
     * matches nothing however the data looks.
     */
    case Value;

    /**
     * A value, bound through a placeholder, and null is one of the values it
     * reads rather than a mistake.
     */
    case ValueOrNull;

    /**
     * A keyword written straight into the SQL: NULL, TRUE or FALSE.
     */
    case Keyword;
}
