<?php

declare(strict_types=1);

namespace Sloop\Database;

/**
 * What one column's values are converted into after they are fetched.
 *
 * Sits between a CastMode and the values: the mode decides which columns get
 * an entry, this decides what happens to the ones that do. Columns without an
 * entry keep the type the driver gave them.
 *
 * @internal Part of the seam between Connection and CastMode.
 */
enum ColumnCast
{
    /** A date or timestamp becoming a DateTimeImmutable. */
    case Datetime;

    /** A one-bit-wide integer becoming a bool. */
    case Boolean;
}
