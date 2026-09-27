<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Controller\Controller;
use Sloop\Http\Request\Request;

final class UploadController extends Controller
{
    public function store(Request $request): ResponseInterface
    {
        $file = $request->file('avatar');

        return $this->response([
            'name'     => $file?->getClientFilename(),
            'error'    => $file?->getError(),
            'contents' => $file !== null && $file->getError() === UPLOAD_ERR_OK ? (string) $file->getStream() : null,
            'title'    => $request->post('title'),
        ])->json();
    }
}
