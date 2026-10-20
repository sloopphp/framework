<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;

abstract class PrivateHelperBase extends ValidatedRequest
{
    private string $helper = 'x';

    public function helper(): string
    {
        return $this->helper;
    }
}
