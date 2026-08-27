<?php

declare(strict_types=1);

namespace Sloop\Database;

use InvalidArgumentException;

/**
 * One page of rows, together with what it takes to reach the others.
 *
 * Select::paginate() reads the page and counts the matches, and hands both
 * over here. The counts are held; the page numbers around this one are worked
 * out on read, so that a caller cannot be given a next page that disagrees
 * with the total it was handed.
 *
 * The total is the number of rows the conditions matched when the count ran,
 * which is a moment after the page itself was read. Rows written in between
 * are in one number and not the other, so a total that implies a further page
 * is not a promise that the page has rows on it.
 *
 * The class is not readonly as a whole because a readonly class cannot hold
 * hooked properties; the four values it carries are readonly individually and
 * the four page numbers are virtual, so nothing here can be written to.
 */
final class Paginator
{
    /**
     * Hold one page and the size of the set it came from.
     *
     * @param  Result                   $items       Rows on this page
     * @param  int                      $total       Rows the conditions matched, counted after the page was read
     * @param  int                      $perPage     Most rows a page carries
     * @param  int                      $currentPage 1-based number of the page held here
     * @throws InvalidArgumentException When the total is negative, or a page size or number is below one
     */
    public function __construct(
        public readonly Result $items,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
    ) {
        if ($total < 0) {
            throw new InvalidArgumentException('Total must not be negative, got ' . $total . '.');
        }

        if ($perPage < 1) {
            throw new InvalidArgumentException('Rows per page must be at least 1, got ' . $perPage . '.');
        }

        if ($currentPage < 1) {
            throw new InvalidArgumentException('Page number must be at least 1, got ' . $currentPage . '.');
        }
    }

    /**
     * Number of the last page.
     *
     * An empty set still has one page, so that a caller stepping through pages
     * has somewhere to start rather than a range that begins above where it ends.
     *
     * @var int
     */
    public int $lastPage {
        get => (int) max(1, ceil($this->total / $this->perPage));
    }

    /**
     * Whether the total leaves a page after this one.
     *
     * A number past the last page answers false as well: nothing follows it
     * either, so a walk forward ends there rather than running on.
     *
     * @var bool
     */
    public bool $hasMorePages {
        get => $this->currentPage < $this->lastPage;
    }

    /**
     * Number of the page after this one, or null when none follows.
     *
     * Null rather than one past the last, so that stepping forward stops at
     * the end of the set instead of asking for a page with nothing on it.
     *
     * @var int|null
     */
    public ?int $nextPage {
        get => $this->hasMorePages ? $this->currentPage + 1 : null;
    }

    /**
     * Number of the page before this one, or null when this is the first.
     *
     * A page past the end still has one behind it, so that a caller handed a
     * number beyond the last page can step back rather than being stranded.
     *
     * @var int|null
     */
    public ?int $previousPage {
        get => $this->currentPage > 1 ? $this->currentPage - 1 : null;
    }
}
