<?php

declare(strict_types=1);

namespace Sloop\Validation;

use InvalidArgumentException;

/**
 * Rules for a field whose validated value is a string.
 *
 * Accepts only strings of valid UTF-8; an int or any other type is a type
 * failure (no implicit conversion). Lengths are counted in grapheme clusters.
 *
 * @extends FieldRule<string>
 */
final class StringRule extends FieldRule
{
    /**
     * Use this value when the field is empty.
     *
     * The default is returned as is; the declared rules are not applied to it.
     *
     * @param  string          $value Value to use for an empty field
     * @return self
     * @throws \LogicException When the field is required or a default has already been declared
     */
    public function default(string $value): self
    {
        return $this->withDefault($value);
    }

    /**
     * Fail when the value has fewer characters than given.
     *
     * @param  int                      $min     Minimum length in grapheme clusters
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $min is negative or the message template is malformed
     */
    public function minLength(int $min, ?string $message = null): self
    {
        self::assertLength($min);

        return $this->withCheck('minLength', ['min' => $min], static fn (string $value): bool => self::length($value) >= $min, $message);
    }

    /**
     * Fail when the value has more characters than given.
     *
     * @param  int                      $max     Maximum length in grapheme clusters
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $max is negative or the message template is malformed
     */
    public function maxLength(int $max, ?string $message = null): self
    {
        self::assertLength($max);

        return $this->withCheck('maxLength', ['max' => $max], static fn (string $value): bool => self::length($value) <= $max, $message);
    }

    /**
     * Fail unless the value has exactly the given number of characters.
     *
     * @param  int                      $length  Required length in grapheme clusters
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $length is negative or the message template is malformed
     */
    public function exactLength(int $length, ?string $message = null): self
    {
        self::assertLength($length);

        return $this->withCheck('exactLength', ['length' => $length], static fn (string $value): bool => self::length($value) === $length, $message);
    }

    /**
     * Fail unless the value matches a regular expression.
     *
     * The pattern is used as given, delimiters and modifiers included. A match
     * that PCRE aborts (e.g. on the backtrack limit) counts as a failure.
     *
     * @param  string                   $pattern PCRE pattern with delimiters
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When the pattern does not compile or the message template is malformed
     */
    public function regex(string $pattern, ?string $message = null): self
    {
        self::assertPattern($pattern);

        return $this->withCheck('regex', ['pattern' => $pattern], static fn (string $value): bool => preg_match($pattern, $value) === 1, $message);
    }

    /**
     * Fail unless the value is one of the given strings.
     *
     * @param  list<string>             $values  Accepted values, compared with `===`
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $values is empty or the message template is malformed
     */
    public function in(array $values, ?string $message = null): self
    {
        self::assertNotEmpty($values, 'in');

        return $this->withCheck('in', ['values' => $values], static fn (string $value): bool => \in_array($value, $values, true), $message);
    }

    /**
     * Fail when the value is one of the given strings.
     *
     * @param  list<string>             $values  Rejected values, compared with `===`
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $values is empty or the message template is malformed
     */
    public function notIn(array $values, ?string $message = null): self
    {
        self::assertNotEmpty($values, 'notIn');

        return $this->withCheck('notIn', ['values' => $values], static fn (string $value): bool => !\in_array($value, $values, true), $message);
    }

    /**
     * Fail unless every character of the value belongs to one of the given sets.
     *
     * @param  list<Chars>              $sets    Allowed character sets; the value may mix characters from all of them
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $sets is empty or the message template is malformed
     */
    public function chars(array $sets, ?string $message = null): self
    {
        self::assertNotEmpty($sets, 'chars');

        $class = '';
        $names = [];
        foreach ($sets as $set) {
            $class  .= $set->pattern();
            $names[] = $set->value;
        }
        $pattern = '/\A[' . $class . ']+\z/u';

        return $this->withCheck('chars', ['chars' => $names], static fn (string $value): bool => preg_match($pattern, $value) === 1, $message);
    }

    /**
     * Fail unless the value is an email address (FILTER_VALIDATE_EMAIL).
     *
     * @param  bool                     $dns     Also require an MX record for the domain (a DNS lookup per value)
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function email(bool $dns = false, ?string $message = null): self
    {
        return $this->withCheck('email', [], static function (string $value) use ($dns): bool {
            if (filter_var($value, \FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }

            if (!$dns) {
                return true;
            }
            $at = strrpos($value, '@');

            return $at !== false && checkdnsrr(substr($value, $at + 1), 'MX');
        }, $message);
    }

    /**
     * Fail unless the value is a URL whose scheme is one of the given schemes.
     *
     * @param  list<string>             $schemes Accepted schemes, compared case-insensitively
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $schemes is empty or the message template is malformed
     */
    public function url(array $schemes = ['http', 'https'], ?string $message = null): self
    {
        self::assertNotEmpty($schemes, 'url');
        $accepted = array_map(strtolower(...), $schemes);

        return $this->withCheck('url', ['schemes' => $schemes], static function (string $value) use ($accepted): bool {
            if (filter_var($value, \FILTER_VALIDATE_URL) === false) {
                return false;
            }
            $scheme = parse_url($value, \PHP_URL_SCHEME);

            return \is_string($scheme) && \in_array(strtolower($scheme), $accepted, true);
        }, $message);
    }

    /**
     * Fail unless the value is an IPv4 or IPv6 address.
     *
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When the message template is malformed
     */
    public function ip(?string $message = null): self
    {
        return $this->withCheck('ip', [], static fn (string $value): bool => filter_var($value, \FILTER_VALIDATE_IP) !== false, $message);
    }

    /**
     * Accept a string of valid UTF-8.
     *
     * @param  mixed               $value Raw value
     * @return string|TypeMismatch
     */
    protected function coerce(mixed $value): string|TypeMismatch
    {
        return \is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : new TypeMismatch();
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'string';
    }

    /**
     * Length of a valid UTF-8 string in grapheme clusters.
     *
     * @param  string $value Valid UTF-8 string
     * @return int
     */
    private static function length(string $value): int
    {
        $length = grapheme_strlen($value);

        return \is_int($length) ? $length : 0;
    }

    /**
     * Reject a negative length bound.
     *
     * @param  int                      $length Length bound
     * @return void
     * @throws InvalidArgumentException When $length is negative
     */
    private static function assertLength(int $length): void
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Length must not be negative, got ' . $length . '.');
        }
    }

    /**
     * Reject an empty candidate list.
     *
     * @param  array<array-key, mixed>  $values Candidates
     * @param  string                   $rule   Rule name for the message
     * @return void
     * @throws InvalidArgumentException When $values is empty
     */
    private static function assertNotEmpty(array $values, string $rule): void
    {
        if ($values === []) {
            throw new InvalidArgumentException($rule . '() needs at least one value.');
        }
    }

    /**
     * Reject a pattern that PCRE cannot compile.
     *
     * @param  string                   $pattern PCRE pattern with delimiters
     * @return void
     * @throws InvalidArgumentException When the pattern does not compile
     */
    private static function assertPattern(string $pattern): void
    {
        set_error_handler(static function (int $errno, string $errstr) use ($pattern): never {
            throw new InvalidArgumentException('Invalid regular expression ' . $pattern . ': ' . $errstr);
        });
        try {
            $result = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new InvalidArgumentException('Invalid regular expression ' . $pattern . ': ' . preg_last_error_msg());
        }
    }
}
