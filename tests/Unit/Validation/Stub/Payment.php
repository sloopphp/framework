<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation\Stub;

enum Payment: string
{
    case Cash     = 'cash';
    case Card     = 'card';
    case Transfer = 'transfer';
}
