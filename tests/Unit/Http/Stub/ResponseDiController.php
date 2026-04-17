<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Request\Request;

/**
 * Stub controller whose actions return either a ResponseInterface or a plain value.
 *
 * Used to verify that RouteRequestHandler returns ResponseInterface results
 * unchanged and routes non-response returns through the response formatter.
 */
final class ResponseDiController
{
    /**
     * Return a pre-built PSR-7 response that should be passed through unchanged.
     *
     * @param  Request $request Sloop request (unused; DI shape check only)
     * @return ResponseInterface Pre-built PSR-7 response
     *
     * @noinspection PhpUnused, PhpUnusedParameterInspection
     */
    public function returnsResponse(Request $request): ResponseInterface
    {
        return new Response(201, [], 'created');
    }

    /**
     * Return a plain array that must be wrapped by the response formatter.
     *
     * @param  Request $request Sloop request (unused; DI shape check only)
     * @return array<string, bool> Arbitrary payload for the formatter
     *
     * @noinspection PhpUnused, PhpUnusedParameterInspection
     */
    public function returnsArray(Request $request): array
    {
        return ['ok' => true];
    }
}
