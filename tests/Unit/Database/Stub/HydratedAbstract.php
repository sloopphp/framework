<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

abstract class HydratedAbstract
{
    public function __construct(public int $id)
    {
    }
}
