<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation\Stub;

enum Priority: int
{
    case Low      = 1;
    case High     = 10;
    case Negative = -5;
}
