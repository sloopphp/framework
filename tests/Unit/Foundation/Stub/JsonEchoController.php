<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Controller\Controller;
use Sloop\Http\Request\Request;

final class JsonEchoController extends Controller
{
    public function store(Request $request): ResponseInterface
    {
        return $this->response([
            'json'  => $request->json('name'),
            'post'  => $request->post('name'),
            'input' => $request->input('name'),
        ])->json();
    }
}
