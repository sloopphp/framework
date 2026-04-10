<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Controller\Controller;
use Sloop\Http\Request\Request;

final class HealthController extends Controller
{
    public function check(Request $request): ResponseInterface
    {
        return $this->response(['status' => 'ok', 'method' => $request->method()])->json();
    }
}
