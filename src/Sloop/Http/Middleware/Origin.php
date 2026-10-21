<?php

declare(strict_types=1);

namespace Sloop\Http\Middleware;

/**
 * Parsing of the origins the CSRF and CORS middleware compare.
 *
 * @internal
 */
final class Origin
{
    /**
     * Default port for each scheme an origin may use.
     *
     * @var array<string, int>
     */
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * URL parts an Origin header has.
     *
     * @var list<string>
     */
    public const array PARTS = ['scheme', 'host', 'port'];

    /**
     * Parse an origin given in the configuration, checking that a browser can send it.
     *
     * @param  string                                              $origin Origin as written
     * @param  bool                                                $web    Whether the scheme must be http or https (the application's own origin)
     * @return array{scheme: string, host: string, port: int|null}
     * @throws \InvalidArgumentException                           If it is not scheme://host[:port], the scheme is not http or https when required, the host is not written as browsers send it, or it names the scheme's default port
     */
    public static function configured(string $origin, bool $web): array
    {
        $parsed = self::parse($origin, self::PARTS);
        if ($parsed === null || $parsed['scheme'] === '' || ($web && !isset(self::DEFAULT_PORTS[$parsed['scheme']]))) {
            throw new \InvalidArgumentException(
                'The origin "' . $origin . '" must be written as scheme://host or scheme://host:port'
                    . ($web ? ' with http or https' : '') . ' and no path.',
            );
        }

        if (!self::isCanonicalHost($parsed['host'])) {
            throw new \InvalidArgumentException(
                'The origin "' . $origin . '" must write its host as browsers send it: ASCII letters, digits and'
                    . ' hyphens (punycode for an international name), a dotted IPv4 address or a compressed IPv6 address.',
            );
        }

        // Browsers leave the default port out of the Origin header, so an
        // origin naming it would never match.
        if ($parsed['port'] !== null && $parsed['port'] === (self::DEFAULT_PORTS[$parsed['scheme']] ?? null)) {
            throw new \InvalidArgumentException(
                'The origin "' . $origin . '" must leave out the default port ' . $parsed['port'] . '.',
            );
        }

        return $parsed;
    }

    /**
     * Split a URL into its lowercased scheme and host and its port.
     *
     * A URL with a part outside the given ones (user info in an Origin, a
     * path in a Host header) is not something a browser sends, so it gives null.
     *
     * @param  string                                                   $url   URL, or //host[:port] for a Host header
     * @param  list<string>                                             $parts URL parts allowed besides the host
     * @return array{scheme: string, host: string, port: int|null}|null Null when it has no host or has another part
     */
    public static function parse(string $url, array $parts): ?array
    {
        $found = parse_url($url);
        if (
            $found === false
            || !isset($found['host'])
            || $found['host'] === ''
            || array_diff(array_keys($found), $parts) !== []
        ) {
            return null;
        }

        return [
            'scheme' => strtolower($found['scheme'] ?? ''),
            'host'   => strtolower($found['host']),
            'port'   => $found['port'] ?? null,
        ];
    }

    /**
     * Write a parsed origin back as scheme://host[:port].
     *
     * @param  array{scheme: string, host: string, port: int|null} $origin Parsed origin
     * @return string
     */
    public static function serialize(array $origin): string
    {
        return $origin['scheme'] . '://' . $origin['host'] . ($origin['port'] === null ? '' : ':' . $origin['port']);
    }

    /**
     * Check that a lowercased host is written the way browsers serialize it in an Origin header.
     *
     * @param  string $host Lowercased host
     * @return bool
     */
    private static function isCanonicalHost(string $host): bool
    {
        if (str_starts_with($host, '[')) {
            $address = substr($host, 1, -1);
            $packed  = str_ends_with($host, ']') ? inet_pton($address) : false;

            return $packed !== false && \strlen($packed) === 16 && inet_ntop($packed) === $address;
        }

        // Browsers read a host whose last label is a number (decimal, or 0x and
        // hex digits) as an IPv4 address and write it back as four decimal parts.
        if (preg_match('/(?:^|\.)(?:\d+|0x[0-9a-f]*)\.?$/', $host) === 1) {
            $packed = inet_pton($host);

            return $packed !== false && \strlen($packed) === 4 && inet_ntop($packed) === $host;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.?$/D', $host) === 1;
    }
}
