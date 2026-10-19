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
                'Referrer-Policy'         => ['no-referrer'],
            ],
            $this->headersOf([]),
        );
    }

    public function testKeepsAHeaderTheHandlerAlreadySetWhateverItsCase(): void
    {
        $headers = $this->headersOf([], ['x-frame-options' => 'SAMEORIGIN', 'Referrer-Policy' => 'same-origin']);

        $this->assertSame(['SAMEORIGIN'], $headers['x-frame-options']);
        $this->assertArrayNotHasKey('X-Frame-Options', $headers);
        $this->assertSame(['same-origin'], $headers['Referrer-Policy']);
        $this->assertSame(['nosniff'], $headers['X-Content-Type-Options']);
    }

    public function testAnOverrideReplacesTheDefaultOfTheSameNameWhateverItsCase(): void
    {
        $headers = $this->headersOf(['x-frame-options' => 'SAMEORIGIN']);

        $this->assertSame(['SAMEORIGIN'], $headers['x-frame-options']);
        $this->assertArrayNotHasKey('X-Frame-Options', $headers);
        $this->assertCount(4, $headers);
    }

    public function testNullRemovesADefaultWhateverItsCase(): void
    {
        $headers = $this->headersOf(['content-security-policy' => null, 'Referrer-Policy' => null]);

        $this->assertSame(['X-Content-Type-Options', 'X-Frame-Options'], array_keys($headers));
    }

    public function testNullForAHeaderWithoutADefaultAddsNothing(): void
    {
        $this->assertCount(4, $this->headersOf(['Permissions-Policy' => null]));
    }

    public function testAddsAHeaderThatHasNoDefault(): void
    {
        $headers = $this->headersOf(['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains']);

        $this->assertSame(['max-age=31536000; includeSubDomains'], $headers['Strict-Transport-Security']);
        $this->assertCount(5, $headers);
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidOverrideProvider(): array
    {
        return [
            'list instead of a map'     => [['nosniff'], 'Headers must be keyed by header name; got the integer key 0.'],
            'empty value'               => [['X-Frame-Options' => ''], 'The value of the header "X-Frame-Options" must be a non-empty string or null.'],
            'value not a string'        => [['X-Frame-Options' => 1], 'The value of the header "X-Frame-Options" must be a non-empty string or null.'],
            'value with a line break'   => [['X-Test' => "a\r\nSet-Cookie: x=1"], 'The header "X-Test" has an invalid name or value: '],
            'name with a space'         => [['X Test' => 'a'], 'The header "X Test" has an invalid name or value: '],
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
