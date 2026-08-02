<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Sloop\Error\DomainException;

final class ThrowingController
{
    public function domain(): ResponseInterface
    {
        throw new DomainException('Order already shipped');
    }

    public function generic(): ResponseInterface
    {
        throw new \RuntimeException('secret internal detail');
    }
}
