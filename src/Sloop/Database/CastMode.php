<?php

declare(strict_types=1);

namespace Sloop\Database;

/**
 * How far the framework converts fetched values away from the driver's types.
 *
 * PDO hands back int, float, string and null once emulated prepares and
 * stringified fetches are both off, which is what a result carries by default.
 * Anything past that — a date as an object, a one-bit integer as a bool, an
 * exact number that does not fit a PHP int — is a choice a project makes once,
 * because the return types of every read change with it.
 *
 * The presets are cumulative: each one converts what the preset before it
 * converts, plus its own. They are opt-in through the `casts` config key, and
 * Off is what a pool uses when the key is omitted.
 *
 * The cost is paid per statement, not per row: reading the column types costs
 * one call to PDOStatement::getColumnMeta() per column, and Off skips it.
 */
enum CastMode
{
    /** Values stay as the driver returned them. */
    case Off;

    /** DATE, DATETIME and TIMESTAMP become DateTimeImmutable. */
    case Datetime;

    /** Datetime, plus TINYINT(1) becoming bool. */
    case Aggressive;

    /**
     * Aggressive, plus BIGINT UNSIGNED and DECIMAL becoming BcMath\Number.
     *
     * Needs ext-bcmath. A BIGINT UNSIGNED above PHP_INT_MAX silently loses
     * precision as an int, which is the accident this preset exists to stop.
     */
    case Full;
}
