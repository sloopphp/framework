<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

/**
 * A default that is not null, on a parameter that accepts null. This is what
 * separates "the column was not selected" from "the column came back NULL":
 * both look the same when the default is null.
 */
final readonly class HydratedNullable
{
    public function __construct(
        public int $id,
        public ?string $note = 'unset',
    ) {
    }
}
