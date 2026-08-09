<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Controller\Controller;
use Sloop\Http\Request\Request;

final class UnicodeSlashController extends Controller
{
    public function show(Request $request): ResponseInterface
    {
        return $this->response([
            'label'  => 'テスト',
            'path'   => 'a/b',
            'method' => $request->method(),
        ])->json();
    }
}
