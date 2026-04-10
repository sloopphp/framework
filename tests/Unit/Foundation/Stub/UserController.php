<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Controller\Controller;
use Sloop\Http\Request\Request;

final class UserController extends Controller
{
    public function find(Request $request, string $id): ResponseInterface
    {
        return $this->response(['id' => $id, 'method' => $request->method()])->json();
    }
}
