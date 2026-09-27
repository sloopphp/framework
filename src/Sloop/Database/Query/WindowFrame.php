<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * The rows a window function reads around the current one.
 *
 * Each end is a signed offset from the current row: a negative number counts
 * back, a positive one counts forward, zero is the current row itself, and
 * null reaches the edge of the partition on that side.
 *
 * The offsets are written into the SQL as numbers rather than bound, because
 * MariaDB refuses a placeholder in a frame bound.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class WindowFrame
{
    /**
     * Describe a frame, refusing one that starts after it ends.
     *
     * Both servers refuse such a frame (MySQL 3586, MariaDB 4014), so it is
     * refused here, at the line that wrote it.
     *
     * @param  FrameUnit                $unit  Whether the offsets count rows or measure along the sort term
     * @param  int|null                 $start Where the frame starts: negative back, positive forward, zero the current row, null the first row
     * @param  int|null                 $end   Where the frame ends: negative back, positive forward, zero the current row, null the last row
     * @throws InvalidArgumentException When the start comes after the end
     */
    public function __construct(
        public FrameUnit $unit,
        public ?int $start,
        public ?int $end,
    ) {
        if ($start !== null && $end !== null && $start > $end) {
            throw new InvalidArgumentException(
                'A frame has to start at or before where it ends; ' . self::offset($start)
                . ' comes after ' . self::offset($end) . '.',
            );
        }
    }

    /**
     * Whether either end is a distance from the current row rather than an edge or the row itself.
     *
     * @return bool True when an end is a non-zero number
     */
    public function hasOffset(): bool
    {
        return ($this->start !== null && $this->start !== 0) || ($this->end !== null && $this->end !== 0);
    }

    /**
     * Write one end of a frame as SQL.
     *
     * @param  int|null $offset Signed offset from the current row, or null for the edge
     * @param  bool     $start  Whether this is the start, which decides the direction of an unbounded end
     * @return string   The bound as SQL
     */
    public static function bound(?int $offset, bool $start): string
    {
        if ($offset === null) {
            return $start ? 'UNBOUNDED PRECEDING' : 'UNBOUNDED FOLLOWING';
        }

        return self::offset($offset);
    }

    /**
     * Write a signed offset from the current row as SQL.
     *
     * The magnitude of a negative offset is taken from its text rather than by
     * negating it, which would overflow for the most negative int.
     *
     * @param  int    $offset Signed offset from the current row
     * @return string The offset as SQL
     */
    private static function offset(int $offset): string
    {
        return match (true) {
            $offset === 0 => 'CURRENT ROW',
            $offset < 0   => substr((string) $offset, 1) . ' PRECEDING',
            default       => $offset . ' FOLLOWING',
        };
    }
}
