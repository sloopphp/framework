<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One comparison's limit brought into the frame of a validation, kept for the next value in the same frame.
 *
 * Each comparison owns one, held by its check, so the kept result goes away
 * with the rule and a list of dates read in one validation brings its limit
 * into the frame once rather than once per element.
 *
 * @internal Built by TemporalRule for each comparison it declares.
 */
final class LimitInFrame
{
    /**
     * Limit the kept result was brought from.
     *
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $limit = null;

    /**
     * Name of the time zone the kept result was brought into.
     *
     * @var string|null
     */
    private ?string $zone = null;

    /**
     * The kept result.
     *
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $aligned = null;

    /**
     * Create an empty one.
     *
     * @param Closure(DateTimeImmutable, DateTimeZone): DateTimeImmutable $align Brings a limit into the frame of a zone
     */
    public function __construct(
        private readonly Closure $align,
    ) {
    }

    /**
     * The limit in the frame of the zone, brought there again only when the limit or the zone differs from last time.
     *
     * The limit is compared by identity: the Validator hands every value of
     * one validation the same current-time object, and a declared limit is
     * always the same object.
     *
     * @param  DateTimeImmutable        $limit Limit, or the current time
     * @param  DateTimeZone             $zone  Time zone of the validation
     * @return DateTimeImmutable
     * @throws InvalidArgumentException When the limit cannot be brought into the frame
     */
    public function in(DateTimeImmutable $limit, DateTimeZone $zone): DateTimeImmutable
    {
        $zoneName = $zone->getName();
        // The limit is only ever set together with the result; the last test
        // is what lets the result be returned as a DateTimeImmutable.
        if ($this->limit === $limit && $this->zone === $zoneName && $this->aligned !== null) {
            return $this->aligned;
        }

        $this->aligned = ($this->align)($limit, $zone);
        $this->limit   = $limit;
        $this->zone    = $zoneName;

        return $this->aligned;
    }
}
