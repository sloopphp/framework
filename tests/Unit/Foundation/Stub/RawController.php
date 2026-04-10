<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Sloop\Http\Request\Request;

final class RawController
{
    /**
     * @param  Request $request HTTP request
     * @return array<string, string>
     */
    public function data(Request $request): array
    {
        return ['key' => 'value', 'method' => $request->method()];
    }
}
