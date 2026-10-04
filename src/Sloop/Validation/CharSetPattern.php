<?php

declare(strict_types=1);

namespace Sloop\Validation;

use InvalidArgumentException;

/**
 * Rewrites the pattern of an application CharSet into a form that is safe to join.
 *
 * StringRule joins every set into one PCRE character class, so a pattern that
 * PCRE reads differently from how it looks (an escape that runs into the next
 * set, a bracket that closes the class) would change what the whole class
 * matches. Rather than predict how PCRE reads each escape, the pattern is
 * parsed with a small grammar and written back as `\x{...}` code points,
 * ranges between them, and `\p{...}` / `\P{...}` properties. Anything the
 * grammar does not accept throws.
 *
 * The grammar: a character other than `\ [ ] ^ - /`; `\` followed by an ASCII
 * symbol; `\x{hex}`; `\p{name}` or `\P{name}`; and `X-Y` between two of the
 * character forms.
 *
 * @internal Used by StringRule.
 */
final class CharSetPattern
{
    /**
     * Characters that must be escaped to stand for themselves.
     *
     * @var list<string>
     */
    private const array RESERVED = ['\\', '[', ']', '^', '-', '/'];

    /**
     * One token: a braced `\x` or property, a backslash with the character after it, or one character.
     *
     * @var string
     */
    private const string TOKEN = '/\\\\x\\{[^}]*\\}|\\\\[pP]\\{[^}]*\\}|\\\\.?|./su';

    /**
     * Parse a pattern and write it back in the joined form.
     *
     * @param  string                   $pattern Pattern returned by CharSet::pattern()
     * @param  string                   $prefix  Start of the exception message, naming the rule and the set
     * @return string                   Character class contents made of `\x{...}`, ranges and properties
     * @throws InvalidArgumentException When the pattern is empty, not valid UTF-8, or outside the grammar
     */
    public static function normalize(string $pattern, string $prefix): string
    {
        if ($pattern === '') {
            throw new InvalidArgumentException($prefix . 'its pattern is empty.');
        }
        if (!mb_check_encoding($pattern, 'UTF-8')) {
            throw new InvalidArgumentException($prefix . 'its pattern is not valid UTF-8.');
        }
        preg_match_all(self::TOKEN, $pattern, $matches);

        $parts = [];
        $held  = null;
        $from  = null;
        foreach ($matches[0] as $token) {
            if ($token === '-' && $held !== null) {
                $from = $held;
                $held = null;

                continue;
            }
            $atom = self::atom($token, $prefix);
            if ($from !== null) {
                $parts[] = self::range($from, $atom, $prefix);
                $from    = null;

                continue;
            }
            $parts[]       = self::release($held);
            [$held, $part] = \is_int($atom) ? [$atom, ''] : [null, $atom];
            $parts[]       = $part;
        }
        if ($from !== null) {
            throw new InvalidArgumentException($prefix . 'its pattern has an unescaped -.');
        }
        $parts[] = self::release($held);

        $joined = implode('', $parts);
        self::assertCompiles($joined, $prefix);

        return $joined;
    }

    /**
     * Read one token as a code point or a property written back.
     *
     * @param  string                   $token  One token of the pattern
     * @param  string                   $prefix Start of the exception message
     * @return int|string               The code point, or the property as `\p{...}` / `\P{...}`
     * @throws InvalidArgumentException When the token is outside the grammar
     */
    private static function atom(string $token, string $prefix): int|string
    {
        if (!str_starts_with($token, '\\')) {
            if (\in_array($token, self::RESERVED, true)) {
                throw new InvalidArgumentException($prefix . 'its pattern has an unescaped ' . $token . '.');
            }

            return mb_ord($token, 'UTF-8');
        }
        if (mb_strlen($token, 'UTF-8') > 2) {
            return self::braced($token, $prefix);
        }

        $escaped = substr($token, 1);
        if ($escaped === 'x' || $escaped === 'p' || $escaped === 'P') {
            throw new InvalidArgumentException($prefix . 'its pattern has \\' . $escaped . ' without {...}.');
        }
        if (preg_match('/\A[!-\/:-@\[-`{-~]\z/', $escaped) !== 1) {
            throw new InvalidArgumentException($prefix . 'its pattern has an escape other than \x{...}, \p{...}, \P{...} or a backslash before an ASCII symbol.');
        }

        return \ord($escaped[0]);
    }

    /**
     * Read a braced `\x{...}`, `\p{...}` or `\P{...}` token.
     *
     * @param  string                   $token  The token, braces included
     * @param  string                   $prefix Start of the exception message
     * @return int|string               The code point for `\x`, the property as written for `\p` / `\P`
     * @throws InvalidArgumentException When the braces hold something the form does not take
     */
    private static function braced(string $token, string $prefix): int|string
    {
        $inside = substr($token, 3, -1);
        if ($token[1] !== 'x') {
            if (preg_match('/\A[A-Za-z0-9_=]+\z/', $inside) !== 1) {
                throw new InvalidArgumentException($prefix . 'its pattern has a property name outside letters, digits, _ and =.');
            }

            return $token;
        }

        if (preg_match('/\A[0-9A-Fa-f]{1,6}\z/', $inside) !== 1) {
            throw new InvalidArgumentException($prefix . 'its pattern has \x{...} without one to six hex digits.');
        }
        $codePoint = (int) hexdec($inside);
        if ($codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            throw new InvalidArgumentException($prefix . 'its pattern has \x{' . $inside . '}, which is not a Unicode scalar value.');
        }

        return $codePoint;
    }

    /**
     * A range between the code point before the hyphen and the atom after it, written back.
     *
     * @param  int                      $low    Code point before the hyphen
     * @param  int|string               $high   Atom after the hyphen
     * @param  string                   $prefix Start of the exception message
     * @return string
     * @throws InvalidArgumentException When the range ends in a property or runs backwards
     */
    private static function range(int $low, int|string $high, string $prefix): string
    {
        if (!\is_int($high)) {
            throw new InvalidArgumentException($prefix . 'its pattern has a range that ends in a property.');
        }
        if ($high < $low) {
            throw new InvalidArgumentException($prefix . 'its pattern has a range that runs backwards.');
        }

        return self::release($low) . '-' . self::release($high);
    }

    /**
     * A held code point written as `\x{...}`, or nothing when none is held.
     *
     * @param  int|null $codePoint Unicode scalar value
     * @return string
     */
    private static function release(?int $codePoint): string
    {
        return $codePoint === null ? '' : '\x{' . dechex($codePoint) . '}';
    }

    /**
     * Reject a property PCRE does not know.
     *
     * @param  string                   $class  Character class contents written back
     * @param  string                   $prefix Start of the exception message
     * @return void
     * @throws InvalidArgumentException When the contents do not compile as a character class
     */
    private static function assertCompiles(string $class, string $prefix): void
    {
        set_error_handler(static function (int $errno, string $errstr) use ($prefix): never {
            throw new InvalidArgumentException($prefix . 'its pattern does not compile as a character class (' . $errstr . ').');
        });
        try {
            preg_match('/[' . $class . ']/u', '');
        } finally {
            restore_error_handler();
        }
    }
}
