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
use Sloop\Error\DomainException;
use Sloop\Http\Middleware\CsrfMiddleware;
use Sloop\Tests\Support\ThrowsAssertions;

final class CsrfMiddlewareTest extends TestCase
{
    use ThrowsAssertions;

    /**
     * @param array<string, string|list<string>> $headers Response headers the handler sets
     */
    private function handler(array $headers = []): RequestHandlerInterface
    {
        return new readonly class ($headers) implements RequestHandlerInterface {
            /**
             * @param array<string, string|list<string>> $headers
             */
            public function __construct(private array $headers)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, $this->headers);
            }
        };
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function request(string $method, array $headers): ServerRequestInterface
    {
        return new ServerRequest($method, '/orders', $headers);
    }

    /**
     * @return array<string, array{array<string, string|list<string>>}>
     */
    public static function allowedProvider(): array
    {
        return [
            'same-origin'                               => [['Sec-Fetch-Site' => 'same-origin', 'Host' => 'example.org']],
            'none'                                      => [['Sec-Fetch-Site' => 'none']],
            'same-origin wins over a foreign Origin'    => [['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://attacker.example', 'Host' => 'example.org']],
            'Origin matching Host'                      => [['Origin' => 'https://example.org', 'Host' => 'example.org']],
            'Origin matching Host whatever its case'    => [['Origin' => 'https://EXAMPLE.org', 'Host' => 'Example.ORG']],
            'Origin and Host with the same port'        => [['Origin' => 'http://example.org:8080', 'Host' => 'example.org:8080']],
            'Origin over http matching Host'            => [['Origin' => 'http://example.org', 'Host' => 'example.org']],
            'Referer matching Host without Origin'      => [['Referer' => 'https://example.org/form?a=1', 'Host' => 'example.org']],
            'IPv6 Origin matching Host'                 => [['Origin' => 'http://[::1]:8080', 'Host' => '[::1]:8080']],
        ];
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    #[DataProvider('allowedProvider')]
    public function testPassesAnUnsafeRequestFromTheSameOrigin(array $headers): void
    {
        $response = new CsrfMiddleware()->process($this->request('POST', $headers), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{array<string, string|list<string>>}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'cross-site'                                 => [['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://attacker.example', 'Host' => 'example.org']],
            'same-site'                                  => [['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://app.example.org', 'Host' => 'api.example.org']],
            'cross-site even with an Origin matching Host' => [['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://example.org', 'Host' => 'example.org']],
            'a value the specification does not define'  => [['Sec-Fetch-Site' => 'Same-Origin', 'Host' => 'example.org']],
            'two Sec-Fetch-Site values'                  => [['Sec-Fetch-Site' => ['same-origin', 'cross-site'], 'Host' => 'example.org']],
            'no header at all'                           => [['Host' => 'example.org']],
            'Origin null'                                => [['Origin' => 'null', 'Host' => 'example.org']],
            'Origin null with a matching Referer'        => [['Origin' => 'null', 'Referer' => 'https://example.org/', 'Host' => 'example.org']],
            'Origin of another host'                     => [['Origin' => 'https://attacker.example', 'Host' => 'example.org']],
            'Origin with the host as a prefix'           => [['Origin' => 'https://example.org.attacker.example', 'Host' => 'example.org']],
            'Origin with another port'                   => [['Origin' => 'https://example.org:8443', 'Host' => 'example.org']],
            'Origin naming the default port'             => [['Origin' => 'https://example.org', 'Host' => 'example.org:443']],
            'two Origin values'                          => [['Origin' => ['https://example.org', 'https://attacker.example'], 'Host' => 'example.org']],
            'Origin without a scheme'                    => [['Origin' => '//example.org', 'Host' => 'example.org']],
            'Referer with the host as user info'         => [['Referer' => 'https://example.org@attacker.example/', 'Host' => 'example.org']],
            'Referer of another host'                    => [['Referer' => 'https://attacker.example/?https://example.org', 'Host' => 'example.org']],
            'no Host'                                    => [['Origin' => 'https://example.org']],
            'two Host values'                            => [['Origin' => 'https://example.org', 'Host' => ['example.org', 'attacker.example']]],
            'Origin with a path'                         => [['Origin' => 'https://example.org/', 'Host' => 'example.org']],
            'Origin with user info before the host'      => [['Origin' => 'https://attacker.example\@example.org', 'Host' => 'example.org']],
            'Referer with user info before the host'     => [['Referer' => 'https://attacker.example:443@example.org/', 'Host' => 'example.org']],
            'Host with user info'                        => [['Origin' => 'https://example.org', 'Host' => 'attacker.example@example.org']],
            'Host with a path'                           => [['Origin' => 'https://example.org', 'Host' => 'example.org/x']],
        ];
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    #[DataProvider('rejectedProvider')]
    public function testRejectsAnUnsafeRequestNotShownToComeFromTheSameOrigin(array $headers): void
    {
        $e = $this->assertThrows(
            DomainException::class,
            fn () => new CsrfMiddleware()->process($this->request('POST', $headers), $this->handler()),
        );

        $this->assertSame(403, $e->statusCode);
        $this->assertSame('The request was rejected as a cross-site request.', $e->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeMethodProvider(): array
    {
        return [
            'POST'   => ['POST'],
            'PUT'    => ['PUT'],
            'PATCH'  => ['PATCH'],
            'DELETE' => ['DELETE'],
            'other'  => ['PURGE'],
        ];
    }

    #[DataProvider('unsafeMethodProvider')]
    public function testChecksEveryMethodThatIsNotSafe(string $method): void
    {
        $this->assertThrows(
            DomainException::class,
            fn () => new CsrfMiddleware()->process($this->request($method, ['Sec-Fetch-Site' => 'cross-site']), $this->handler()),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeMethodProvider(): array
    {
        return [
            'GET'     => ['GET'],
            'HEAD'    => ['HEAD'],
            'OPTIONS' => ['OPTIONS'],
            'TRACE'   => ['TRACE'],
        ];
    }

    #[DataProvider('safeMethodProvider')]
    public function testPassesASafeMethodWithoutACheckOrVary(string $method): void
    {
        $response = new CsrfMiddleware()->process($this->request($method, ['Sec-Fetch-Site' => 'cross-site']), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Vary'));
    }

    public function testAddsSecFetchSiteAndOriginToVaryOnACheckedRequest(): void
    {
        $response = new CsrfMiddleware()->process(
            $this->request('POST', ['Sec-Fetch-Site' => 'same-origin']),
            $this->handler(['Vary' => 'Accept']),
        );

        $this->assertSame(['Accept', 'Sec-Fetch-Site', 'Origin'], $response->getHeader('Vary'));
    }

    public function testDoesNotRepeatAVaryNameTheResponseAlreadyLists(): void
    {
        $response = new CsrfMiddleware()->process(
            $this->request('POST', ['Sec-Fetch-Site' => 'same-origin']),
            $this->handler(['Vary' => 'Accept, ORIGIN']),
        );

        $this->assertSame(['Accept, ORIGIN', 'Sec-Fetch-Site'], $response->getHeader('Vary'));
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function configuredOriginAllowedProvider(): array
    {
        return [
            'Origin'                       => [['Origin' => 'https://example.org']],
            'Origin whatever its case'     => [['Origin' => 'HTTPS://Example.org']],
            'Referer'                      => [['Referer' => 'https://example.org/form']],
            'Origin with a rewritten Host' => [['Origin' => 'https://example.org', 'Host' => 'backend.internal:8080']],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('configuredOriginAllowedProvider')]
    public function testComparesWithTheConfiguredOriginInsteadOfHost(array $headers): void
    {
        $response = new CsrfMiddleware('https://example.org')->process($this->request('POST', $headers), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function configuredOriginRejectedProvider(): array
    {
        return [
            'another scheme'          => [['Origin' => 'http://example.org', 'Host' => 'example.org']],
            'Host matching instead'   => [['Origin' => 'https://backend.internal', 'Host' => 'backend.internal']],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('configuredOriginRejectedProvider')]
    public function testRejectsAnOriginOtherThanTheConfiguredOne(array $headers): void
    {
        $this->assertThrows(
            DomainException::class,
            fn () => new CsrfMiddleware('https://example.org')->process($this->request('POST', $headers), $this->handler()),
        );
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function trustedProvider(): array
    {
        return [
            'cross-site from a trusted origin'      => [['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://app.example']],
            'same-site from a trusted origin'       => [['Sec-Fetch-Site' => 'same-site', 'Origin' => 'https://app.example']],
            'trusted Origin without Sec-Fetch-Site' => [['Origin' => 'https://app.example', 'Host' => 'api.example.org']],
            'trusted Referer without Origin'        => [['Referer' => 'https://app.example/page', 'Host' => 'api.example.org']],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('trustedProvider')]
    public function testPassesARequestFromATrustedOrigin(array $headers): void
    {
        $response = new CsrfMiddleware(null, ['https://other.example', 'https://app.example'])
            ->process($this->request('POST', $headers), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function untrustedOriginProvider(): array
    {
        return [
            'another port'                            => ['https://app.example:8443'],
            'a port other than the trusted one'       => ['https://port.example:13'],
            'the port digits moved into the host'     => ['https://port.example1:2'],
            'another scheme'                          => ['http://app.example'],
            'the scheme letters moved into the host'  => ['http://sapp.example'],
            'another host'                            => ['https://attacker.example'],
        ];
    }

    #[DataProvider('untrustedOriginProvider')]
    public function testRejectsACrossSiteRequestFromAnOriginNotTrustedExactly(string $origin): void
    {
        $this->assertThrows(
            DomainException::class,
            fn () => new CsrfMiddleware(null, ['https://app.example', 'https://port.example:12'])
                ->process($this->request('POST', ['Sec-Fetch-Site' => 'cross-site', 'Origin' => $origin]), $this->handler()),
        );
    }

    public function testPassesATrustedOriginWithAPort(): void
    {
        $response = new CsrfMiddleware(null, ['https://port.example:12'])
            ->process($this->request('POST', ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://port.example:12']), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRejectsACrossSiteRequestWithoutOriginEvenWithTrustedOrigins(): void
    {
        $this->assertThrows(
            DomainException::class,
            fn () => new CsrfMiddleware(null, ['https://app.example'])
                ->process($this->request('POST', ['Sec-Fetch-Site' => 'cross-site', 'Referer' => 'https://app.example/']), $this->handler()),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function misshapenOriginProvider(): array
    {
        return [
            'null'           => ['null'],
            'no scheme'      => ['example.org'],
            'trailing slash' => ['https://example.org/'],
            'path'           => ['https://example.org/app'],
            'query'          => ['https://example.org?a'],
            'user info'      => ['https://user@example.org'],
            'empty'          => [''],
        ];
    }

    #[DataProvider('misshapenOriginProvider')]
    public function testRejectsAMisshapenConfiguredOrigin(string $origin): void
    {
        $e = $this->assertThrows(\InvalidArgumentException::class, static fn () => new CsrfMiddleware($origin));

        $this->assertSame(
            'The origin "' . $origin . '" must be written as scheme://host or scheme://host:port with http or https and no path.',
            $e->getMessage(),
        );
    }

    #[DataProvider('misshapenOriginProvider')]
    public function testRejectsAMisshapenTrustedOrigin(string $origin): void
    {
        $e = $this->assertThrows(
            \InvalidArgumentException::class,
            static fn () => new CsrfMiddleware(null, ['https://app.example', $origin]),
        );

        $this->assertSame('The origin "' . $origin . '" must be written as scheme://host or scheme://host:port and no path.', $e->getMessage());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsendableOriginProvider(): array
    {
        $host = ' must write its host as browsers send it: ASCII letters, digits and hyphens (punycode for an'
            . ' international name), a dotted IPv4 address or a compressed IPv6 address.';

        return [
            'international host'       => ['https://bücher.example', $host],
            'space in the host'        => ['https://exa mple.org', $host],
            'escaped host'             => ['https://exa%20mple.org', $host],
            'hyphen at a label end'    => ['https://example-.org', $host],
            'uncompressed IPv6'        => ['http://[0:0::1]:8080', $host],
            'IPv6 with a stray letter' => ['http://[::1x]', $host],
            'IPv4 in brackets'         => ['http://[192.0.2.1]', $host],
            'unclosed bracket'         => ['http://[::1:8080', $host],
            'short IPv4'               => ['http://127.1', $host],
            'IPv4 with a leading zero' => ['http://127.0.0.01', $host],
            'number as the last label' => ['https://example.123', $host],
            'default https'            => ['https://example.org:443', ' must leave out the default port 443.'],
            'default http'             => ['http://example.org:80', ' must leave out the default port 80.'],
        ];
    }

    #[DataProvider('unsendableOriginProvider')]
    public function testRejectsAConfiguredOriginBrowsersCannotSend(string $origin, string $reason): void
    {
        $e = $this->assertThrows(\InvalidArgumentException::class, static fn () => new CsrfMiddleware($origin));

        $this->assertSame('The origin "' . $origin . '"' . $reason, $e->getMessage());
    }

    #[DataProvider('unsendableOriginProvider')]
    public function testRejectsATrustedOriginBrowsersCannotSend(string $origin, string $reason): void
    {
        $e = $this->assertThrows(
            \InvalidArgumentException::class,
            static fn () => new CsrfMiddleware(null, ['https://app.example', $origin]),
        );

        $this->assertSame('The origin "' . $origin . '"' . $reason, $e->getMessage());
    }

    public function testRejectsAnOwnOriginWithASchemeOtherThanHttpOrHttps(): void
    {
        $e = $this->assertThrows(\InvalidArgumentException::class, static fn () => new CsrfMiddleware('capacitor://localhost'));

        $this->assertSame(
            'The origin "capacitor://localhost" must be written as scheme://host or scheme://host:port with http or https and no path.',
            $e->getMessage(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function canonicalTrustedOriginProvider(): array
    {
        return [
            'another scheme'                => ['capacitor://localhost', 'capacitor://localhost'],
            'another scheme with a port 443' => ['app://example.org:443', 'app://example.org:443'],
            'uppercase'                     => ['HTTPS://App.Example', 'https://app.example'],
            'punycode'                      => ['https://xn--bcher-kva.example', 'https://xn--bcher-kva.example'],
            'label starting with a digit'   => ['https://1password.example', 'https://1password.example'],
            'IPv4'                          => ['http://192.0.2.1:8080', 'http://192.0.2.1:8080'],
            'IPv6'                          => ['http://[2001:db8::1]', 'http://[2001:db8::1]'],
            'trailing dot'                  => ['https://example.org.', 'https://example.org.'],
        ];
    }

    #[DataProvider('canonicalTrustedOriginProvider')]
    public function testPassesACrossSiteRequestFromATrustedOriginWrittenAsBrowsersSendIt(string $trusted, string $sent): void
    {
        $response = new CsrfMiddleware(null, [$trusted])
            ->process($this->request('POST', ['Sec-Fetch-Site' => 'cross-site', 'Origin' => $sent]), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsAnOriginWithANonDefaultPort(): void
    {
        $response = new CsrfMiddleware('https://example.org:8443')
            ->process($this->request('POST', ['Origin' => 'https://example.org:8443']), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }
}
