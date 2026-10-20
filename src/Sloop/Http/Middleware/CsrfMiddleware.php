<?php

declare(strict_types=1);

namespace Sloop\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Error\DomainException;
use Sloop\Http\HttpStatus;

/**
 * Middleware that rejects cross-site requests with an unsafe method (CSRF protection).
 *
 * The request headers browsers set decide: Sec-Fetch-Site first, then Origin,
 * then Referer when Origin is missing. A request with none of them is rejected,
 * so the middleware belongs on routes that browsers call with cookies, not on
 * routes for clients that send no such headers. GET, HEAD, OPTIONS and TRACE
 * pass without a check.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * Methods RFC 9110 defines as safe; they pass without a check.
     *
     * @var list<string>
     */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * Default port for each scheme an origin may use.
     *
     * @var array<string, int>
     */
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * Origin of the application, or null to compare with the Host header.
     *
     * @var array{scheme: string, host: string, port: int|null}|null
     */
    private ?array $origin;

    /**
     * Origins allowed to send cross-site requests, keyed by their serialization.
     *
     * @var array<string, true>
     */
    private array $trustedOrigins;

    /**
     * Create the middleware.
     *
     * Each origin is written as scheme://host or scheme://host:port with the
     * scheme http or https and without the default port, the way browsers send
     * the Origin header.
     *
     * @param  string|null               $origin         Origin of the application; null compares the host and port of the Host header instead
     * @param  list<string>              $trustedOrigins Origins allowed to send cross-site requests
     * @throws \InvalidArgumentException If an origin is not written as above
     */
    public function __construct(?string $origin = null, array $trustedOrigins = [])
    {
        $this->origin = $origin === null ? null : self::configuredOrigin($origin);

        $trusted = [];
        foreach ($trustedOrigins as $trustedOrigin) {
            $trusted[self::serialize(self::configuredOrigin($trustedOrigin))] = true;
        }

        $this->trustedOrigins = $trusted;
    }

    /**
     * Reject a cross-site request with an unsafe method, otherwise pass it on.
     *
     * The response of a checked request varies by Sec-Fetch-Site and Origin,
     * so both are added to its Vary header.
     *
     * @param  ServerRequestInterface  $request Incoming request
     * @param  RequestHandlerInterface $handler Next handler
     * @return ResponseInterface
     * @throws DomainException         With status 403 when the request is not shown to come from the application or a trusted origin
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (\in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        if (!$this->isAllowed($request)) {
            // Nothing the client sent is put in the message.
            throw new DomainException('The request was rejected as a cross-site request.', HttpStatus::Forbidden);
        }

        return $this->withVary($handler->handle($request));
    }

    /**
     * Decide whether a request with an unsafe method comes from the application or a trusted origin.
     *
     * @param  ServerRequestInterface $request Incoming request
     * @return bool
     */
    private function isAllowed(ServerRequestInterface $request): bool
    {
        $site = $request->getHeader('Sec-Fetch-Site');
        if ($site !== []) {
            return match ($site) {
                ['same-origin'], ['none']  => true,
                ['same-site'], ['cross-site'] => $this->isTrusted(self::singleOrigin($request, 'Origin')),
                // Two values or one the specification does not define.
                default => false,
            };
        }

        $source = $request->hasHeader('Origin')
            ? self::singleOrigin($request, 'Origin')
            : self::singleOrigin($request, 'Referer');

        return $this->isTrusted($source) || $this->isOwnOrigin($request, $source);
    }

    /**
     * Check whether the origin is one of the trusted origins.
     *
     * @param  array{scheme: string, host: string, port: int|null}|null $source Origin the request comes from
     * @return bool
     */
    private function isTrusted(?array $source): bool
    {
        return $source !== null && isset($this->trustedOrigins[self::serialize($source)]);
    }

    /**
     * Check whether the origin is the application's own.
     *
     * Without a configured origin the host and port are compared with the
     * Host header; the request does not tell the scheme.
     *
     * @param  ServerRequestInterface                                   $request Incoming request
     * @param  array{scheme: string, host: string, port: int|null}|null $source  Origin the request comes from
     * @return bool
     */
    private function isOwnOrigin(ServerRequestInterface $request, ?array $source): bool
    {
        if ($source === null) {
            return false;
        }

        if ($this->origin !== null) {
            return $source === $this->origin;
        }

        $host = $request->getHeader('Host');
        if (\count($host) !== 1) {
            return false;
        }

        $target = self::parse('//' . $host[0]);

        return $target !== null && $target['host'] === $source['host'] && $target['port'] === $source['port'];
    }

    /**
     * Read the origin of a header that must appear once.
     *
     * @param  ServerRequestInterface                                   $request Incoming request
     * @param  string                                                   $name    Header name (Origin or Referer)
     * @return array{scheme: string, host: string, port: int|null}|null Null when the header is missing, repeated, "null" or has no scheme and host
     */
    private static function singleOrigin(ServerRequestInterface $request, string $name): ?array
    {
        $values = $request->getHeader($name);
        if (\count($values) !== 1) {
            return null;
        }

        $origin = self::parse($values[0]);

        return $origin === null || $origin['scheme'] === '' ? null : $origin;
    }

    /**
     * Split a URL into its lowercased scheme and host and its port.
     *
     * @param  string                                                   $url URL, or //host[:port] for a Host header
     * @return array{scheme: string, host: string, port: int|null}|null Null when it has no host
     */
    private static function parse(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        return [
            'scheme' => strtolower($parts['scheme'] ?? ''),
            'host'   => strtolower($parts['host']),
            'port'   => $parts['port'] ?? null,
        ];
    }

    /**
     * Parse an origin given in the configuration, checking that a browser can send it.
     *
     * @param  string                                              $origin Origin as written
     * @return array{scheme: string, host: string, port: int|null}
     * @throws \InvalidArgumentException                           If it is not scheme://host[:port] with http or https, or names the scheme's default port
     */
    private static function configuredOrigin(string $origin): array
    {
        $parts  = parse_url($origin);
        $parsed = self::parse($origin);

        if (
            $parts === false
            || $parsed === null
            || !isset(self::DEFAULT_PORTS[$parsed['scheme']])
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
        ) {
            throw new \InvalidArgumentException(
                'The origin "' . $origin . '" must be written as scheme://host or scheme://host:port with http or https and no path.',
            );
        }

        // Browsers leave the default port out of the Origin header, so an
        // origin naming it would never match.
        if ($parsed['port'] === self::DEFAULT_PORTS[$parsed['scheme']]) {
            throw new \InvalidArgumentException(
                'The origin "' . $origin . '" must leave out the default port ' . $parsed['port'] . '.',
            );
        }

        return $parsed;
    }

    /**
     * Write a parsed origin back as scheme://host[:port].
     *
     * @param  array{scheme: string, host: string, port: int|null} $origin Parsed origin
     * @return string
     */
    private static function serialize(array $origin): string
    {
        return $origin['scheme'] . '://' . $origin['host'] . ($origin['port'] === null ? '' : ':' . $origin['port']);
    }

    /**
     * Add Sec-Fetch-Site and Origin to the Vary header unless it already lists them.
     *
     * @param  ResponseInterface $response Response of the checked request
     * @return ResponseInterface
     */
    private function withVary(ResponseInterface $response): ResponseInterface
    {
        $listed = array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', implode(',', $response->getHeader('Vary'))),
        );

        foreach (['Sec-Fetch-Site', 'Origin'] as $name) {
            if (!\in_array(strtolower($name), $listed, true)) {
                $response = $response->withAddedHeader('Vary', $name);
            }
        }

        return $response;
    }
}
