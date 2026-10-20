<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Middleware;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Http\Middleware\SecurityHeadersMiddleware;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    /**
     * @param array<string, string> $headers Headers the handler sets
     */
    private function handlerReturning(array $headers = []): RequestHandlerInterface
    {
        return new readonly class ($headers) implements RequestHandlerInterface {
            /**
             * @param array<string, string> $headers
             */
            public function __construct(private array $headers)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response(200);
                foreach ($this->headers as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }

                return $response;
            }
        };
    }

    /**
     * @param  array<array-key, mixed> $overrides
     * @param  array<string, string>   $handlerHeaders
     * @return array<array<string>>
     */
    private function headersOf(array $overrides, array $handlerHeaders = []): array
    {
        $response = new SecurityHeadersMiddleware($overrides)
            ->process(new ServerRequest('GET', '/'), $this->handlerReturning($handlerHeaders));

        return $response->getHeaders();
    }

    public function testAddsTheDefaultHeadersWithoutOverrides(): void
    {
        $this->assertSame(
            [
                'X-Content-Type-Options'  => ['nosniff'],
                'X-Frame-Options'         => ['DENY'],
                'Content-Security-Policy' => ["frame-ancestors 'none'"],
            ],
            $this->headersOf([]),
        );
    }

    public function testKeepsAHeaderTheHandlerAlreadySetWhateverItsCase(): void
    {
        $headers = $this->headersOf([], ['x-frame-options' => 'SAMEORIGIN', 'Content-Security-Policy' => "frame-ancestors 'self'"]);

        $this->assertSame(['SAMEORIGIN'], $headers['x-frame-options']);
        $this->assertArrayNotHasKey('X-Frame-Options', $headers);
        $this->assertSame(["frame-ancestors 'self'"], $headers['Content-Security-Policy']);
        $this->assertSame(['nosniff'], $headers['X-Content-Type-Options']);
    }

    public function testAnOverrideReplacesTheDefaultOfTheSameNameWhateverItsCase(): void
    {
        $headers = $this->headersOf(['x-frame-options' => 'SAMEORIGIN']);

        $this->assertSame(['SAMEORIGIN'], $headers['x-frame-options']);
        $this->assertArrayNotHasKey('X-Frame-Options', $headers);
        $this->assertCount(3, $headers);
    }

    public function testNullRemovesADefaultWhateverItsCase(): void
    {
        $headers = $this->headersOf(['content-security-policy' => null, 'X-FRAME-OPTIONS' => null]);

        $this->assertSame(['X-Content-Type-Options'], array_keys($headers));
    }

    public function testNullForAHeaderWithoutADefaultAddsNothing(): void
    {
        $this->assertCount(3, $this->headersOf(['Permissions-Policy' => null]));
    }

    public function testSendsAConfiguredHeaderTheSameOverHttpAndHttps(): void
    {
        $middleware = new SecurityHeadersMiddleware(['Strict-Transport-Security' => 'max-age=31536000']);

        foreach (['http://example.com/', 'https://example.com/'] as $uri) {
            $response = $middleware->process(new ServerRequest('GET', $uri), $this->handlerReturning());

            $this->assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'), $uri);
        }
    }

    public function testAddsAHeaderThatHasNoDefault(): void
    {
        $headers = $this->headersOf(['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains']);

        $this->assertSame(['max-age=31536000; includeSubDomains'], $headers['Strict-Transport-Security']);
        $this->assertCount(4, $headers);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidOverrideProvider(): array
    {
        return [
            'list instead of a map'     => [['nosniff'], 'Headers must be keyed by header name; got the integer key 0.'],
            'empty value'               => [['X-Frame-Options' => ''], 'The value of the header "X-Frame-Options" must be a non-blank string or null.'],
            'value not a string'        => [['X-Frame-Options' => 1], 'The value of the header "X-Frame-Options" must be a non-blank string or null.'],
            'blank value'               => [['X-Frame-Options' => " \t "], 'The value of the header "X-Frame-Options" must be a non-blank string or null.'],
            'value with a line break'   => [['X-Test' => "a\r\nSet-Cookie: x=1"], 'The value of the header "X-Test" must not contain a line break.'],
            'trailing line feed'        => [['X-Frame-Options' => "DENY\n"], 'The value of the header "X-Frame-Options" must not contain a line break.'],
            'value with a control character' => [['X-Test' => "a\x01"], 'The header "X-Test" has an invalid name or value: Header values must be RFC 7230 compatible strings'],
            'name with a space'         => [['X Test' => 'a'], 'The header "X Test" has an invalid name or value: Header name must be an RFC 7230 compatible string'],
            'invalid name given with null' => [['X Test' => null], 'The header "X Test" has an invalid name or value: Header name must be an RFC 7230 compatible string'],
            'empty name given with null'   => [['' => null], 'The header "" has an invalid name or value: Header name must be an RFC 7230 compatible string'],
            'name differing only in case' => [['X-Test' => 'a', 'x-test' => 'b'], 'The header "x-test" is given twice with different case ("X-Test").'],
            'null then a different case' => [['X-Test' => null, 'x-test' => 'b'], 'The header "x-test" is given twice with different case ("X-Test").'],
        ];
    }

    /**
     * @param array<array-key, mixed> $overrides
     */
    #[DataProvider('invalidOverrideProvider')]
    public function testRejectsAnInvalidOverride(array $overrides, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new SecurityHeadersMiddleware($overrides);
    }
}
