<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * One statement whose rows are added to those of the statement before it.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class Union
{
    /**
     * Hold a statement and how its rows join the ones before it.
     *
     * @param SubQuery $query Statement whose rows are added
     * @param bool     $all   Whether rows already read are kept rather than dropped as duplicates
     */
    public function __construct(
        public SubQuery $query,
        public bool $all,
    ) {
    }
}
