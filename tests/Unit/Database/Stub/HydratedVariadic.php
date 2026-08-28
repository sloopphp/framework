<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

final class HydratedVariadic
{
    /** @var list<int|float|string|null> */
    public array $columns;

    public function __construct(int|float|string|null ...$columns)
    {
        $this->columns = array_values($columns);
    }
}
