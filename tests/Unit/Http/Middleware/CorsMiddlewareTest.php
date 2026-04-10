<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Middleware;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Http\Middleware\CorsMiddleware;

final class CorsMiddlewareTest extends TestCase
{
    private function createRequest(string $method = 'GET', string $origin = ''): ServerRequestInterface
    {
        $request = new ServerRequest($method, new Uri('/api/users'));
        if ($origin !== '') {
            $request = $request->withHeader('Origin', $origin);
        }

        return $request;
    }

    private function createHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    /**
     * @param  array<string, mixed> $config Config overrides
     */
    private function createCors(array $config = []): CorsMiddleware
    {
        $defaults = [
            'allowed_origins'   => ['https://example.com'],
            'allowed_methods'   => ['GET', 'POST', 'PUT', 'DELETE'],
            'allowed_headers'   => ['Content-Type', 'Authorization'],
            'max_age'           => 86400,
            'allow_credentials' => false,
        ];

        return new CorsMiddleware(array_merge($defaults, $config));
    }

    public function testAddsCorHeadersForAllowedOrigin(): void
    {
        $cors     = $this->createCors();
        $request  = $this->createRequest(origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, PUT, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testNoHeadersForDisallowedOrigin(): void
    {
        $cors     = $this->createCors();
        $request  = $this->createRequest(origin: 'https://evil.example');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testNoHeadersWhenNoOrigin(): void
    {
        $cors     = $this->createCors();
        $request  = $this->createRequest();
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testPreflightReturns204(): void
    {
        $cors     = $this->createCors();
        $request  = $this->createRequest(method: 'OPTIONS', origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testPreflightForDisallowedOriginPassesThrough(): void
    {
        $cors     = $this->createCors();
        $request  = $this->createRequest(method: 'OPTIONS', origin: 'https://evil.example');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testWildcardOriginAllowsAll(): void
    {
        $cors     = $this->createCors(['allowed_origins' => ['*']]);
        $request  = $this->createRequest(origin: 'https://any.example');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame('https://any.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCredentialsHeaderWhenEnabled(): void
    {
        $cors     = $this->createCors(['allow_credentials' => true]);
        $request  = $this->createRequest(origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testNoCredentialsHeaderWhenDisabled(): void
    {
        $cors     = $this->createCors(['allow_credentials' => false]);
        $request  = $this->createRequest(origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testMultipleAllowedOrigins(): void
    {
        $cors = $this->createCors(['allowed_origins' => ['https://a.example.com', 'https://b.example.com']]);

        $responseA = $cors->process(
            $this->createRequest(origin: 'https://a.example.com'),
            $this->createHandler(),
        );
        $responseB = $cors->process(
            $this->createRequest(origin: 'https://b.example.com'),
            $this->createHandler(),
        );
        $responseC = $cors->process(
            $this->createRequest(origin: 'https://c.example.com'),
            $this->createHandler(),
        );

        $this->assertSame('https://a.example.com', $responseA->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('https://b.example.com', $responseB->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertFalse($responseC->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testDefaultConfigRejectsAllOrigins(): void
    {
        $cors     = new CorsMiddleware();
        $request  = $this->createRequest(origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    // ---------------------------------------------------------------
    // Malformed config fallback — defensive against invalid types
    // ---------------------------------------------------------------

    public function testNonArrayAllowedMethodsFallsBackToDefault(): void
    {
        $cors     = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
            'allowed_methods' => 'GET', // invalid: string instead of array
        ]);
        $response = $cors->process(
            $this->createRequest(origin: 'https://example.com'),
            $this->createHandler(),
        );

        $this->assertSame('GET, POST, PUT, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testNonArrayAllowedHeadersFallsBackToDefault(): void
    {
        $cors     = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
            'allowed_headers' => 42, // invalid: int instead of array
        ]);
        $response = $cors->process(
            $this->createRequest(origin: 'https://example.com'),
            $this->createHandler(),
        );

        $this->assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testNonIntMaxAgeFallsBackToDefault(): void
    {
        $cors     = $this->createCors(['max_age' => 'not-a-number']);
        $response = $cors->process(
            $this->createRequest(method: 'OPTIONS', origin: 'https://example.com'),
            $this->createHandler(),
        );

        $this->assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testTruthyNonBoolAllowCredentialsIsCastToBool(): void
    {
        $cors     = $this->createCors(['allow_credentials' => 1]);
        $response = $cors->process(
            $this->createRequest(origin: 'https://example.com'),
            $this->createHandler(),
        );

        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testFalsyNonBoolAllowCredentialsIsCastToFalse(): void
    {
        $cors     = $this->createCors(['allow_credentials' => 0]);
        $response = $cors->process(
            $this->createRequest(origin: 'https://example.com'),
            $this->createHandler(),
        );

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testNonArrayAllowedOriginsFallsBackToDefault(): void
    {
        // Default is [] (no origins allowed). When a non-array value is passed,
        // it falls back to [] and rejects all origins — even the one matching
        // the passed string value.
        $cors     = new CorsMiddleware([
            'allowed_origins' => 'https://example.com', // invalid: string instead of array
        ]);
        $response = $cors->process(
            $this->createRequest(origin: 'https://example.com'),
            $this->createHandler(),
        );

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    // ---------------------------------------------------------------
    // Non-OPTIONS HTTP methods — actual request flow
    // ---------------------------------------------------------------

    public function testProcessAppliesCorsHeadersForPostMethod(): void
    {
        $cors     = $this->createCors();
        $request  = $this->createRequest(method: 'POST', origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, PUT, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    // ---------------------------------------------------------------
    // Preflight + credentials interaction
    // ---------------------------------------------------------------

    public function testPreflightIncludesCredentialsHeaderWhenEnabled(): void
    {
        $cors     = $this->createCors(['allow_credentials' => true]);
        $request  = $this->createRequest(method: 'OPTIONS', origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    // ---------------------------------------------------------------
    // Origin matching edge cases
    // ---------------------------------------------------------------

    public function testWildcardCoexistsWithSpecificOrigins(): void
    {
        // When `*` is in the allowed list (regardless of position), all origins
        // are accepted, even those not explicitly listed.
        $cors     = $this->createCors(['allowed_origins' => ['https://a.example.com', '*']]);
        $request  = $this->createRequest(origin: 'https://random.example');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame('https://random.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testEmptyAllowedMethodsResultsInEmptyHeader(): void
    {
        // Edge case: empty methods array → implode(', ', []) → empty string header.
        // Could happen via explicit [] config or all-non-string filtering by Arr::stringList.
        $cors     = $this->createCors(['allowed_methods' => []]);
        $request  = $this->createRequest(origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }
}
