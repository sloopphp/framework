<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Middleware;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Http\Middleware\CorsMiddleware;
use Sloop\Tests\Support\ThrowsAssertions;

final class CorsMiddlewareTest extends TestCase
{
    use ThrowsAssertions;

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
     * @param list<string> $allowedOrigins
     * @param list<string> $allowedMethods
     */
    private function createCors(
        array $allowedOrigins = ['https://example.com'],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'],
        bool $allowCredentials = false,
    ): CorsMiddleware {
        return new CorsMiddleware(
            allowedOrigins: $allowedOrigins,
            allowedMethods: $allowedMethods,
            allowedHeaders: ['Content-Type', 'Authorization'],
            maxAge: 86400,
            allowCredentials: $allowCredentials,
        );
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
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testPreflightIncludesVaryOrigin(): void
    {
        $cors     = $this->createCors();
        $request  = $this->createRequest(method: 'OPTIONS', origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testVaryOriginIsAppendedToExistingVaryHeader(): void
    {
        $cors    = $this->createCors();
        $request = $this->createRequest(origin: 'https://example.com');

        // Downstream handler that already sets a Vary header
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200)->withHeader('Vary', 'Accept-Encoding');
            }
        };

        $response = $cors->process($request, $handler);
        $values   = $response->getHeader('Vary');

        $this->assertContains('Accept-Encoding', $values);
        $this->assertContains('Origin', $values);
    }

    public function testVaryOriginIsNotDuplicatedWhenListedInCommaSeparatedHeader(): void
    {
        // A single `Vary: Accept-Encoding, Origin` header is the common wire
        // form, and splitting it yields " Origin" with a leading space. The
        // duplicate check must normalize whitespace, otherwise Origin is
        // appended a second time.
        $cors    = $this->createCors();
        $request = $this->createRequest(origin: 'https://example.com');

        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200)->withHeader('Vary', 'Accept-Encoding, Origin');
            }
        };

        $response = $cors->process($request, $handler);

        $this->assertSame(['Accept-Encoding, Origin'], $response->getHeader('Vary'));
    }

    public function testNoVaryForDisallowedOrigin(): void
    {
        $cors     = $this->createCors();
        $request  = $this->createRequest(origin: 'https://evil.example');
        $response = $cors->process($request, $this->createHandler());

        // Disallowed origins pass through without any CORS processing
        $this->assertFalse($response->hasHeader('Vary'));
    }

    public function testNoVaryWhenNoOriginHeader(): void
    {
        $cors     = $this->createCors();
        $request  = $this->createRequest();
        $response = $cors->process($request, $this->createHandler());

        $this->assertFalse($response->hasHeader('Vary'));
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
        // With a wildcard config the literal `*` is sent (never a reflected
        // origin), and the response no longer varies by Origin.
        $cors     = $this->createCors(allowedOrigins: ['*']);
        $request  = $this->createRequest(origin: 'https://any.example');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Vary'));
    }

    public function testVaryOriginIsNotDuplicatedWhenAppliedTwice(): void
    {
        // Registering the middleware both globally and per-route runs
        // addCorsHeaders twice; the second pass must not append another Origin.
        $cors    = $this->createCors();
        $request = $this->createRequest(origin: 'https://example.com');
        $once    = $cors->process($request, $this->createHandler());
        $twice   = $cors->process($request, new readonly class ($once) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        });

        $this->assertSame(['Origin'], $twice->getHeader('Vary'));
    }

    public function testWildcardWithCredentialsIsRejectedAtConstruction(): void
    {
        // The Fetch spec forbids `Access-Control-Allow-Origin: *` with
        // credentials; reflecting the origin instead would grant any site
        // credentialed cross-origin reads, so the config is rejected outright.
        $e = $this->assertThrows(\InvalidArgumentException::class, fn () => $this->createCors(allowedOrigins: ['*'], allowCredentials: true));

        $this->assertSame('The origin "*" must not be allowed together with credentials.', $e->getMessage());
    }

    public function testCredentialsHeaderWhenEnabled(): void
    {
        $cors     = $this->createCors(allowCredentials: true);
        $request  = $this->createRequest(origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testNoCredentialsHeaderWhenDisabled(): void
    {
        $cors     = $this->createCors(allowCredentials: false);
        $request  = $this->createRequest(origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testMultipleAllowedOrigins(): void
    {
        $cors = $this->createCors(allowedOrigins: ['https://a.example.com', 'https://b.example.com']);

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
        $cors     = $this->createCors(allowCredentials: true);
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
        // are accepted and the literal `*` is sent instead of the request origin.
        $cors     = $this->createCors(allowedOrigins: ['https://a.example.com', '*']);
        $request  = $this->createRequest(origin: 'https://random.example');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testEmptyAllowedMethodsResultsInEmptyHeader(): void
    {
        // Edge case: empty methods array → implode(', ', []) → empty string header.
        $cors     = $this->createCors(allowedMethods: []);
        $request  = $this->createRequest(origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testMatchesAnAllowedOriginWithoutRegardToCase(): void
    {
        $cors     = $this->createCors(allowedOrigins: ['https://Example.COM']);
        $request  = $this->createRequest(origin: 'https://example.com');
        $response = $cors->process($request, $this->createHandler());

        $this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testAcceptsAnOriginWithAnotherSchemeAndPort(): void
    {
        $cors     = $this->createCors(allowedOrigins: ['capacitor://localhost', 'http://localhost:5173']);
        $response = $cors->process($this->createRequest(origin: 'http://localhost:5173'), $this->createHandler());

        $this->assertSame('http://localhost:5173', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testAcceptsAZeroMaxAge(): void
    {
        $cors     = new CorsMiddleware(allowedOrigins: ['https://example.com'], maxAge: 0);
        $response = $cors->process($this->createRequest(method: 'OPTIONS', origin: 'https://example.com'), $this->createHandler());

        $this->assertSame('0', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    /**
     * @return array<string, array{\Closure(): CorsMiddleware, string}>
     */
    public static function rejectedArgumentProvider(): array
    {
        return [
            'origin with a path'         => [static fn (): CorsMiddleware => new CorsMiddleware(allowedOrigins: ['https://example.com/']), 'The origin "https://example.com/" must be written as scheme://host or scheme://host:port and no path.'],
            'origin with a default port' => [static fn (): CorsMiddleware => new CorsMiddleware(allowedOrigins: ['https://example.com:443']), 'The origin "https://example.com:443" must leave out the default port 443.'],
            'null origin'                => [static fn (): CorsMiddleware => new CorsMiddleware(allowedOrigins: ['null']), 'The origin "null" must be written as scheme://host or scheme://host:port and no path.'],
            'negative max age'           => [static fn (): CorsMiddleware => new CorsMiddleware(maxAge: -1), 'The max age must be 0 or more seconds; -1 was given.'],
            'method with a space'        => [static fn (): CorsMiddleware => new CorsMiddleware(allowedMethods: ['GET POST']), 'The method "GET POST" must be an HTTP token (letters, digits and !#$%&\'*+-.^_`|~).'],
            'header with a line break'   => [static fn (): CorsMiddleware => new CorsMiddleware(allowedHeaders: ["X-A\r\nX-B: 1"]), "The header \"X-A\r\nX-B: 1\" must be an HTTP token (letters, digits and !#\$%&'*+-.^_`|~)."],
            'empty header'               => [static fn (): CorsMiddleware => new CorsMiddleware(allowedHeaders: ['']), 'The header "" must be an HTTP token (letters, digits and !#$%&\'*+-.^_`|~).'],
        ];
    }

    /**
     * @param \Closure(): CorsMiddleware $create
     */
    #[DataProvider('rejectedArgumentProvider')]
    public function testRejectsAnArgumentThatWouldNeverTakeEffect(\Closure $create, string $message): void
    {
        $e = $this->assertThrows(\InvalidArgumentException::class, $create);

        $this->assertSame($message, $e->getMessage());
    }

    public function testRejectsAListValueThatIsNotAString(): void
    {
        // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
        $e = $this->assertThrows(\InvalidArgumentException::class, static fn (): CorsMiddleware => new CorsMiddleware(allowedHeaders: ['X-A', 1]));

        $this->assertSame('allowedHeaders must hold only strings; int was given.', $e->getMessage());
    }
}
