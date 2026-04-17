<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Request\Request;

/**
 * Stub controller whose actions return either a ResponseInterface or a plain value.
 */
final class ResponseDiController
{
    /** @noinspection PhpUnused, PhpUnusedParameterInspection */
    public function returnsResponse(Request $request): ResponseInterface
    {
        return new Response(201, [], 'created');
    }

    /**
     * @return array<string, bool>
     *
     * @noinspection PhpUnused, PhpUnusedParameterInspection
     */
    public function returnsArray(Request $request): array
    {
        return ['ok' => true];
    }
}
