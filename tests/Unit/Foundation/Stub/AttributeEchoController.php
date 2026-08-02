<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Controller\Controller;
use Sloop\Http\Request\Request;

final class AttributeEchoController extends Controller
{
    public function show(Request $request): ResponseInterface
    {
        $value = $request->psrRequest()->getAttribute('injected', 'absent');

        return $this->response(['injected' => $value])->json();
    }
}
