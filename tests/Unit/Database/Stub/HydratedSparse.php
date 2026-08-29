<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

/**
 * Two optional parameters in a row, so a result set can leave the middle one
 * out. Passing the arguments positionally would shift rank into label.
 */
final readonly class HydratedSparse
{
    public function __construct(
        public int $id,
        public string $label = 'none',
        public ?int $rank = null,
    ) {
    }
}
