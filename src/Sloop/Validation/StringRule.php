<?php

declare(strict_types=1);

namespace Sloop\Validation;

use Generator;
use InvalidArgumentException;
use Normalizer;
use RuntimeException;

/**
 * Rules for a field whose validated value is a string.
 *
 * Accepts only strings of valid UTF-8; an int or any other type is a type
 * failure (no implicit conversion). Lengths are counted in grapheme clusters
 * unless a rule is given another LengthUnit.
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
     * Fail when the value is shorter than given.
     *
     * @param  int                      $min     Minimum length
     * @param  string|null              $message Message for this rule only
     * @param  LengthUnit               $unit    Unit the length is counted in
     * @return self
     * @throws InvalidArgumentException When $min is negative or the message template is malformed
     */
    public function minLength(int $min, ?string $message = null, LengthUnit $unit = LengthUnit::Graphemes): self
    {
        self::assertLength($min);

        return $this->withCheck('minLength', ['min' => $min, 'unit' => $unit->value], static fn (string $value): bool => $unit->length($value) >= $min, $message);
    }

    /**
     * Fail when the value is longer than given.
     *
     * @param  int                      $max     Maximum length
     * @param  string|null              $message Message for this rule only
     * @param  LengthUnit               $unit    Unit the length is counted in
     * @return self
     * @throws InvalidArgumentException When $max is negative or the message template is malformed
     */
    public function maxLength(int $max, ?string $message = null, LengthUnit $unit = LengthUnit::Graphemes): self
    {
        self::assertLength($max);

        return $this->withCheck('maxLength', ['max' => $max, 'unit' => $unit->value], static fn (string $value): bool => $unit->length($value) <= $max, $message);
    }

    /**
     * Fail unless the value has exactly the given length.
     *
     * @param  int                      $length  Required length
     * @param  string|null              $message Message for this rule only
     * @param  LengthUnit               $unit    Unit the length is counted in
     * @return self
     * @throws InvalidArgumentException When $length is negative or the message template is malformed
     */
    public function exactLength(int $length, ?string $message = null, LengthUnit $unit = LengthUnit::Graphemes): self
    {
        self::assertLength($length);

        return $this->withCheck('exactLength', ['length' => $length, 'unit' => $unit->value], static fn (string $value): bool => $unit->length($value) === $length, $message);
    }

    /**
     * Fail unless the length of the value is a multiple of the given size.
     *
     * @param  int                      $size    Block size, at least 1
     * @param  string|null              $message Message for this rule only
     * @param  LengthUnit               $unit    Unit the length is counted in
     * @return self
     * @throws InvalidArgumentException When $size is less than 1 or the message template is malformed
     */
    public function blockSize(int $size, ?string $message = null, LengthUnit $unit = LengthUnit::Graphemes): self
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Block size must be at least 1, got ' . $size . '.');
        }

        return $this->withCheck('blockSize', ['size' => $size, 'unit' => $unit->value], static fn (string $value): bool => $unit->length($value) % $size === 0, $message);
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
     * Fail when the value contains any of the given strings.
     *
     * A needle matches the value as given or its NFC form. Its NFC form is
     * looked for in both, and the needle as given in the value as given:
     * a decomposed `café` contains `café`, `admin` followed by a combining
     * tilde (which NFC turns into `admiñ`) still contains `admin`, and a
     * needle `e` + U+0301 is found in `e` + U+0301 + U+0323 even though NFC
     * reorders those marks. A precomposed `é` does not contain `e`. The value
     * itself is kept as given. With $ignoreCase, upper and lower case are
     * compared one character to one (`ÉLAN` contains `élan`, `SS` does not
     * contain `ß`); full-width and half-width characters stay different
     * (`ＰＡＳＳ` does not contain `pass`).
     *
     * @param  list<string>             $needles    Strings the value must not contain
     * @param  bool                     $ignoreCase Treat upper and lower case as the same
     * @param  string|null              $message    Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $needles is empty, a needle is empty or not valid UTF-8, or the message template is malformed
     * @throws \TypeError               When a needle is not a string
     */
    public function notContains(array $needles, bool $ignoreCase = false, ?string $message = null): self
    {
        self::assertNotEmpty($needles, 'notContains');
        $prepared = [];
        foreach ($needles as $needle) {
            if ($needle === '') {
                throw new InvalidArgumentException('notContains() needs at least one character in each value.');
            }
            if (!mb_check_encoding($needle, 'UTF-8')) {
                throw new InvalidArgumentException('notContains() needs values in valid UTF-8.');
            }
            $prepared[] = self::comparisonForms($needle, $ignoreCase);
        }

        return $this->withCheck('notContains', ['values' => $needles], static function (string $value) use ($prepared, $ignoreCase): bool {
            // NFC composes a combining mark into the last letter of a needle
            // (`admin` + U+0303 becomes `admiñ`) and reorders marks (e + U+0301
            // + U+0323 becomes U+1EB9 + U+0301), so the value as given is
            // searched too. Each form is built once, not once per needle.
            [$raw, $normalized] = self::comparisonForms($value, $ignoreCase);
            foreach ($prepared as [$needleRaw, $needleNormalized]) {
                if (str_contains($raw, $needleNormalized) || str_contains($normalized, $needleNormalized) || str_contains($raw, $needleRaw)) {
                    return false;
                }
            }

            return true;
        }, $message);
    }

    /**
     * Fail unless every character of the value belongs to one of the given sets.
     *
     * A string in $sets lists characters to allow, split into grapheme
     * clusters: a character of the value passes when it equals one of them
     * (both compared in NFC, so a decomposed `é` matches a listed `é`) or when
     * every code point of it is in one of the CharSets (Chars cases or an
     * application's own; see CharSet for what a pattern may hold).
     *
     * @param  list<CharSet|string>     $sets    Allowed character sets and listed characters; the value may mix characters from all of them
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $sets is empty, a listed string is empty or not valid UTF-8, a CharSet pattern is unsafe to join (see CharSet), or the message template is malformed
     * @throws RuntimeException         When ICU cannot split a listed string into grapheme clusters
     */
    public function chars(array $sets, ?string $message = null): self
    {
        self::assertNotEmpty($sets, 'chars');
        [$class, $names, $listed] = self::readSets($sets, 'chars');
        $clusters                 = [];
        foreach ($listed as $string) {
            $clusters += self::clusterSet($string);
        }

        $pattern = '/\A[' . $class . ']+\z/u';
        if ($clusters === []) {
            $passes = static fn (string $value): bool => preg_match($pattern, $value) === 1;
        } else {
            $passes = static fn (string $value): bool => self::eachClusterAllowed($value, $class === '' ? null : $pattern, $clusters);
        }

        return $this->withCheck('chars', ['chars' => $names, 'listed' => $listed], $passes, $message);
    }

    /**
     * Fail when any character of the value belongs to one of the given sets.
     *
     * A CharSet matches any code point of the value. A string in $sets
     * lists characters to reject, split into grapheme clusters. A listed
     * character of one code point before or after NFC (a decomposed `é`
     * counts) matches that code point anywhere in the value or in its NFC
     * form, inside a grapheme cluster too: a listed `>` rejects `>` followed
     * by U+0338, which NFC turns into `≯` (not `≯` itself, which holds no
     * `>`), a listed `l` rejects `l̃` (l followed by a combining tilde), and a
     * listed "\n" rejects a CR LF pair. A listed character of more code points
     * matches only a whole grapheme cluster: an emoji with a skin tone, or a
     * listed "\r\n", which then rejects the pair but not a lone CR or LF (list
     * "\r" and "\n" apart, or use Chars::Newlines). Chars::Emoji used here
     * also rejects `©` `™` `®` and the zero width joiner (see Chars::Emoji).
     *
     * @param  list<CharSet|string>     $sets    Rejected character sets and listed characters
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $sets is empty, a listed string is empty or not valid UTF-8, a CharSet pattern is unsafe to join (see CharSet), or the message template is malformed
     * @throws RuntimeException         When ICU cannot split a listed string into grapheme clusters
     */
    public function notChars(array $sets, ?string $message = null): self
    {
        self::assertNotEmpty($sets, 'notChars');
        [$class, $names, $listed] = self::readSets($sets, 'notChars');
        [$codePoints, $clusters]  = self::splitListed($listed);
        $pattern                  = '/[' . $class . ']/u';
        $listedPattern            = '/[' . $codePoints . ']/u';

        return $this->withCheck('notChars', ['chars' => $names, 'listed' => $listed], static function (string $value) use ($pattern, $class, $listedPattern, $codePoints, $clusters): bool {
            if ($class !== '' && preg_match($pattern, $value) !== 0) {
                return false;
            }
            // Both forms: NFC composes some listed characters away (`>` and
            // U+0338 become `≯`) and composes others into place (a decomposed
            // `é`), so either form alone lets a listed character through.
            if ($codePoints !== '' && (preg_match($listedPattern, $value) !== 0 || preg_match($listedPattern, self::nfc($value)) !== 0)) {
                return false;
            }

            foreach ($clusters === [] ? [] : self::clusters($value) as $cluster) {
                if (isset($clusters[self::nfc($cluster)])) {
                    return false;
                }
            }

            return true;
        }, $message);
    }

    /**
     * Fail unless the value has characters from at least the given number of sets.
     *
     * Each element of $sets is one kind: a CharSet counts when any code
     * point of the value is in it, and a string counts once when any grapheme
     * cluster of the value equals one of its characters (compared in NFC).
     * Overlapping sets count independently: with [Chars::Alpha,
     * Chars::Uppercase], `ABC` uses both.
     *
     * @param  int                      $min     Minimum number of kinds the value must use
     * @param  list<CharSet|string>     $sets    The kinds to count
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $sets is empty or names a set twice, $min is below 1 or above the number of sets, a listed string is empty or not valid UTF-8, a CharSet pattern is unsafe to join (see CharSet), or the message template is malformed
     * @throws RuntimeException         When ICU cannot split a listed string into grapheme clusters
     */
    public function minCharClasses(int $min, array $sets, ?string $message = null): self
    {
        self::assertNotEmpty($sets, 'minCharClasses');
        if ($min < 1) {
            throw new InvalidArgumentException('minCharClasses() needs a minimum of at least 1, got ' . $min . '.');
        }
        if ($min > \count($sets)) {
            throw new InvalidArgumentException('minCharClasses() needs a minimum of at most ' . \count($sets) . ' (the number of sets given), got ' . $min . '.');
        }
        [, $names, $listed, $setPatterns] = self::readSets($sets, 'minCharClasses');
        if (\count(array_unique($names)) !== \count($names) || \count(array_unique($listed)) !== \count($listed)) {
            throw new InvalidArgumentException('minCharClasses() needs each set at most once.');
        }

        $patterns    = array_map(static fn (string $pattern): string => '/[' . $pattern . ']/u', $setPatterns);
        $listedKinds = array_map(self::clusterSet(...), $listed);

        return $this->withCheck('minCharClasses', ['min' => $min, 'chars' => $names, 'listed' => $listed], static function (string $value) use ($min, $patterns, $listedKinds): bool {
            $used = self::countListedKinds($value, $listedKinds);
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    ++$used;
                }
            }

            return $used >= $min;
        }, $message);
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
     * Split character sets into a character class and the listed characters.
     *
     * @param  list<CharSet|string>                                    $sets Character sets and listed characters
     * @param  string                                                  $rule Rule name for the message
     * @return array{string, list<string>, list<string>, list<string>} Joined class, set names, listed strings, and each set's pattern
     * @throws InvalidArgumentException                                When a listed string is empty or not valid UTF-8, or a CharSet pattern is unsafe to join (see CharSet)
     */
    private static function readSets(array $sets, string $rule): array
    {
        $class    = '';
        $names    = [];
        $listed   = [];
        $patterns = [];
        foreach ($sets as $set) {
            if ($set instanceof CharSet) {
                // Read each method once: an application set is code the rule
                // does not control, and what was checked must be what is used.
                $name       = $set->name();
                $pattern    = $set instanceof Chars ? $set->pattern() : self::applicationPattern($set->pattern(), $name, $rule);
                $class     .= $pattern;
                $names[]    = $name;
                $patterns[] = $pattern;

                continue;
            }
            if ($set === '') {
                throw new InvalidArgumentException($rule . '() needs at least one character in each listed string.');
            }
            if (!mb_check_encoding($set, 'UTF-8')) {
                throw new InvalidArgumentException($rule . '() needs listed characters in valid UTF-8.');
            }
            $listed[] = $set;
        }

        return [$class, $names, $listed, $patterns];
    }

    /**
     * The pattern of an application CharSet, written back in a form that is safe to join.
     *
     * @param  string                   $pattern Pattern returned by CharSet::pattern()
     * @param  string                   $name    Name returned by CharSet::name()
     * @param  string                   $rule    Rule name for the message
     * @return string
     * @throws InvalidArgumentException When the name is empty, or the pattern is outside the grammar CharSet describes
     */
    private static function applicationPattern(string $pattern, string $name, string $rule): string
    {
        if ($name === '') {
            throw new InvalidArgumentException($rule . '() cannot use a CharSet whose name is empty.');
        }

        return CharSetPattern::normalize($pattern, $rule . '() cannot use the CharSet ' . $name . ': ');
    }

    /**
     * Split listed characters into single code points and grapheme clusters.
     *
     * A form of one code point, before or after NFC, goes to the code points,
     * and the NFC form is also kept as a grapheme cluster.
     *
     * @param  list<string>                          $listed Listed strings of valid UTF-8
     * @return array{string, array<array-key, true>} Character class contents for the single code points, and every listed cluster in NFC as set keys
     * @throws RuntimeException                      When ICU cannot split a listed string into grapheme clusters
     */
    private static function splitListed(array $listed): array
    {
        $codePoints = '';
        $clusters   = [];
        foreach ($listed as $string) {
            foreach (self::clusters($string) as $cluster) {
                $nfc = self::nfc($cluster);
                // One code point before or after NFC: U+0344 is one code point that
                // NFC splits in two, and a decomposed `é` is two that NFC joins.
                // The NFC form also matches as a whole cluster, so U+0344 still
                // rejects a bare U+0308 U+0301 (a single code point there is
                // already caught by the code points).
                $single = array_filter([$cluster, $nfc], static fn (string $form): bool => mb_strlen($form, 'UTF-8') === 1);
                foreach ($single as $form) {
                    $codePoints .= '\\x{' . dechex(mb_ord($form, 'UTF-8')) . '}';
                }
                $clusters[$nfc] = true;
            }
        }

        return [$codePoints, $clusters];
    }

    /**
     * Whether every grapheme cluster of the value is listed or made only of allowed code points.
     *
     * @param  string                 $value    Valid UTF-8 string
     * @param  string|null            $pattern  Pattern matching a cluster made only of allowed code points, or null when no Chars case was given
     * @param  array<array-key, true> $clusters Listed grapheme clusters in NFC (a digit becomes an int key)
     * @return bool
     * @throws RuntimeException       When ICU cannot split the value
     */
    private static function eachClusterAllowed(string $value, ?string $pattern, array $clusters): bool
    {
        foreach (self::clusters($value) as $cluster) {
            if (isset($clusters[self::nfc($cluster)])) {
                continue;
            }
            if ($pattern === null || preg_match($pattern, $cluster) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many of the listed kinds have a grapheme cluster in the value.
     *
     * @param  string                       $value Valid UTF-8 string
     * @param  list<array<array-key, true>> $kinds Each kind's grapheme clusters in NFC
     * @return int
     * @throws RuntimeException             When ICU cannot split the value
     */
    private static function countListedKinds(string $value, array $kinds): int
    {
        if ($kinds === []) {
            return 0;
        }

        $found = [];
        foreach (self::clusters($value) as $cluster) {
            $key = self::nfc($cluster);
            foreach ($kinds as $i => $kind) {
                if (isset($kind[$key])) {
                    $found[$i] = true;
                }
            }
        }

        return \count($found);
    }

    /**
     * The grapheme clusters of a valid UTF-8 string, each in NFC, as set keys.
     *
     * @param  string                 $value Valid UTF-8 string
     * @return array<array-key, true>
     * @throws RuntimeException       When ICU cannot split the value
     */
    private static function clusterSet(string $value): array
    {
        $set = [];
        foreach (self::clusters($value) as $cluster) {
            $set[self::nfc($cluster)] = true;
        }

        return $set;
    }

    /**
     * The grapheme clusters of a valid UTF-8 string, one at a time.
     *
     * Splitting one cluster per call keeps the memory flat; an array of every
     * cluster costs about 64 bytes per character, which a long value turns
     * into a fatal memory error before any rule has failed.
     *
     * @param  string                              $value Valid UTF-8 string
     * @return Generator<int, string, mixed, void>
     * @throws RuntimeException                    When ICU cannot split the value
     */
    private static function clusters(string $value): Generator
    {
        $length = \strlen($value);
        $offset = 0;
        while ($offset < $length) {
            $cluster = grapheme_extract($value, 1, \GRAPHEME_EXTR_COUNT, $offset, $next);
            if (!\is_string($cluster) || $cluster === '' || !\is_int($next)) {
                throw new RuntimeException('Could not split the value into grapheme clusters.');
            }
            $offset = $next;

            yield $cluster;
        }
    }

    /**
     * A valid UTF-8 string in Normalization Form C.
     *
     * @param  string $value Valid UTF-8 string
     * @return string
     */
    private static function nfc(string $value): string
    {
        $normalized = Normalizer::normalize($value);

        return \is_string($normalized) ? $normalized : $value;
    }

    /**
     * The two forms notContains() compares: as given, and in NFC.
     *
     * With $fold, both are case folded with the simple folding mb_stripos()
     * uses, and the NFC form is normalized again after folding: `J` + U+030C
     * has no precomposed form, but its folded `j` + U+030C composes to `ǰ`.
     *
     * @param  string                $value Valid UTF-8 string
     * @param  bool                  $fold  Whether to fold the case
     * @return array{string, string}
     */
    private static function comparisonForms(string $value, bool $fold): array
    {
        if (!$fold) {
            return [$value, self::nfc($value)];
        }

        return [
            mb_convert_case($value, \MB_CASE_FOLD_SIMPLE, 'UTF-8'),
            self::nfc(mb_convert_case(self::nfc($value), \MB_CASE_FOLD_SIMPLE, 'UTF-8')),
        ];
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
