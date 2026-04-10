<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Controller\Controller;
use Sloop\Http\Request\Request;

final class GreetController extends Controller
{
    public function index(Request $request): ResponseInterface
    {
        return $this->response(['message' => 'hello', 'method' => $request->method()])->json();
    }
}
