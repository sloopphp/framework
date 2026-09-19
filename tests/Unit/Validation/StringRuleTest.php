<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\Chars;
use Sloop\Validation\Rule;
use Sloop\Validation\ValidationError;

final class StringRuleTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    // ---------------------------------------------------------------
    // Type
    // ---------------------------------------------------------------

    public function testStringIsKeptAsIs(): void
    {
        $this->assertSame('42', self::valueOf(Rule::string(), '42'));
        $this->assertSame(' a ', self::valueOf(Rule::string(), ' a '));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStrings(): iterable
    {
        yield 'int' => [42];
        yield 'float' => [1.5];
        yield 'bool' => [true];
        yield 'array' => [['a']];
        yield 'invalid UTF-8' => ["\xff"];
        yield 'truncated UTF-8' => ["\xe3\x81"];
    }

    #[DataProvider('nonStrings')]
    public function testNonStringIsATypeFailure(mixed $input): void
    {
        $this->assertEquals(
            new ValidationError('string', [], 'The v field must be a string.'),
            self::onlyError(Rule::string()->minLength(100), $input),
        );
    }

    // ---------------------------------------------------------------
    // Length
    // ---------------------------------------------------------------

    public function testMinLengthCountsGraphemes(): void
    {
        $rule = Rule::string()->minLength(3);

        $this->assertSame([], self::failedRules($rule, 'abc'));
        $this->assertSame([], self::failedRules($rule, 'あいう'));
        $this->assertSame([], self::failedRules($rule, "e\u{301}e\u{301}e\u{301}"));
        $this->assertSame(['minLength'], self::failedRules($rule, 'ab'));
        $this->assertSame(['minLength'], self::failedRules($rule, '👨‍👩‍👧👍'));
    }

    public function testMaxLengthCountsGraphemes(): void
    {
        $rule = Rule::string()->maxLength(2);

        $this->assertSame([], self::failedRules($rule, 'ab'));
        $this->assertSame([], self::failedRules($rule, '👨‍👩‍👧👍'));
        $this->assertSame(['maxLength'], self::failedRules($rule, 'abc'));
    }

    public function testExactLength(): void
    {
        $rule = Rule::string()->exactLength(2);

        $this->assertSame([], self::failedRules($rule, 'ab'));
        $this->assertSame(['exactLength'], self::failedRules($rule, 'a'));
        $this->assertSame(['exactLength'], self::failedRules($rule, 'abc'));
    }

    public function testLengthErrorsCarryTheirBound(): void
    {
        $this->assertEquals(
            new ValidationError('maxLength', ['max' => 1], 'The v field must not be longer than 1 characters.'),
            self::onlyError(Rule::string()->maxLength(1), 'ab'),
        );
        $this->assertEquals(
            new ValidationError('exactLength', ['length' => 3], 'The v field must be exactly 3 characters.'),
            self::onlyError(Rule::string()->exactLength(3), 'ab'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lengthRules(): iterable
    {
        yield 'minLength' => ['minLength'];
        yield 'maxLength' => ['maxLength'];
        yield 'exactLength' => ['exactLength'];
    }

    #[DataProvider('lengthRules')]
    public function testNegativeLengthThrows(string $method): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->{$method}(-1));

        $this->assertSame('Length must not be negative, got -1.', $e->getMessage());
    }

    public function testZeroLengthIsAccepted(): void
    {
        $this->assertSame([], self::failedRules(Rule::string()->minLength(0), 'a'));
    }

    // ---------------------------------------------------------------
    // regex / in / notIn
    // ---------------------------------------------------------------

    public function testRegex(): void
    {
        $rule = Rule::string()->regex('/\A[a-z]+\z/');

        $this->assertSame([], self::failedRules($rule, 'abc'));
        $this->assertEquals(
            new ValidationError('regex', ['pattern' => '/\A[a-z]+\z/'], 'The v field format is invalid.'),
            self::onlyError($rule, 'ab1'),
        );
    }

    public function testRegexAbortedByPcreCountsAsFailure(): void
    {
        $limit = \ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $this->assertSame(['regex'], self::failedRules(Rule::string()->regex('/(a+)+b/'), 'aaaaaaaaaac'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    public function testInvalidRegexThrowsAtDeclaration(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::string()->regex('/[a-z/'));

        $this->assertMatchesRegularExpression('#\AInvalid regular expression /\[a-z/: \S#', $e->getMessage());
    }

    public function testInvalidRegexLeavesTheErrorHandlerUntouched(): void
    {
        $before = set_error_handler(null);
        restore_error_handler();

        $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::string()->regex('no delimiters'));

        $after = set_error_handler(null);
        restore_error_handler();
        $this->assertSame($before, $after);
    }

    public function testIn(): void
    {
        $rule = Rule::string()->in(['a', 'b']);

        $this->assertSame([], self::failedRules($rule, 'b'));
        $this->assertEquals(
            new ValidationError('in', ['values' => ['a', 'b']], 'The selected v is invalid.'),
            self::onlyError($rule, 'c'),
        );
    }

    public function testNotIn(): void
    {
        $rule = Rule::string()->notIn(['admin']);

        $this->assertSame([], self::failedRules($rule, 'user'));
        $this->assertEquals(new ValidationError('notIn', ['values' => ['admin']], 'The selected v is invalid.'), self::onlyError($rule, 'admin'));
    }

    public function testInComparesStrictly(): void
    {
        $this->assertSame(['in'], self::failedRules(Rule::string()->in(['1.0']), '1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listRules(): iterable
    {
        yield 'in' => ['in'];
        yield 'notIn' => ['notIn'];
        yield 'chars' => ['chars'];
        yield 'url' => ['url'];
    }

    #[DataProvider('listRules')]
    public function testEmptyListThrows(string $method): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->{$method}([]));

        $this->assertSame($method . '() needs at least one value.', $e->getMessage());
    }

    // ---------------------------------------------------------------
    // chars
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{Chars, list<string>, list<string>}>
     */
    public static function charSets(): iterable
    {
        yield 'alpha' => [Chars::Alpha, ['azAZ'], ['a1', 'é', 'あ']];
        yield 'uppercase' => [Chars::Uppercase, ['AZ'], ['Ab']];
        yield 'lowercase' => [Chars::Lowercase, ['az'], ['aB']];
        yield 'numeric' => [Chars::Numeric, ['0189'], ['1a', '１']];
        yield 'spaces' => [Chars::Spaces, [' '], ["\t", "\u{3000}"]];
        yield 'newlines' => [Chars::Newlines, ["\r\n"], [' ']];
        yield 'tabs' => [Chars::Tabs, ["\t"], [' ']];
        yield 'dots' => [Chars::Dots, ['.'], [',', 'a']];
        yield 'commas' => [Chars::Commas, [','], ['.']];
        yield 'punctuation' => [Chars::Punctuation, ['.,!?:;&'], ['-', "'"]];
        yield 'dashes' => [Chars::Dashes, ['_-'], ['.', '—']];
        yield 'slashes' => [Chars::Slashes, ['/\\'], ['|']];
        yield 'brackets' => [Chars::Brackets, ['()'], ['[', '{']];
        yield 'at' => [Chars::At, ['@'], ['a']];
        yield 'letter' => [Chars::Letter, ['José', 'Müller', 'あア漢'], ['a1', 'a b']];
        yield 'hiragana' => [Chars::Hiragana, ['ひらがなー'], ['カ', '漢', 'a']];
        yield 'katakana' => [Chars::Katakana, ['カタカナー', 'ｶﾀｶﾅ'], ['ひ', '漢']];
        yield 'kanji' => [Chars::Kanji, ['漢字々〇'], ['ひ', 'カ']];
        yield 'zenkaku symbols' => [Chars::ZenkakuSymbols, ["、。「」\u{3000}！＃（）＝￥"], ['!', 'Ａ', '１']];
        yield 'emoji' => [Chars::Emoji, ['😀', '👍🏽', '👨‍👩‍👧', '🇯🇵', '❤️'], ['a', '1']];
        yield 'hex' => [Chars::Hex, ['09afAF'], ['g', 'G']];
    }

    /**
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    #[DataProvider('charSets')]
    public function testEachCharSetAllowsOnlyItsCharacters(Chars $set, array $accepted, array $rejected): void
    {
        $rule = Rule::string()->chars([$set]);

        foreach ($accepted as $value) {
            $this->assertSame([], self::failedRules($rule, $value), $set->name . ' should accept ' . $value);
        }
        foreach ($rejected as $value) {
            $this->assertSame(['chars'], self::failedRules($rule, $value), $set->name . ' should reject ' . $value);
        }
    }

    public function testCharsAcceptsTheUnionOfTheSets(): void
    {
        $rule = Rule::string()->chars([Chars::Uppercase, Chars::Numeric]);

        $this->assertSame([], self::failedRules($rule, 'A1'));
        $this->assertSame(['chars'], self::failedRules($rule, 'a1'));
    }

    public function testCharsErrorListsTheSetNames(): void
    {
        $this->assertEquals(
            new ValidationError('chars', ['chars' => ['alpha', 'zenkaku_symbols']], 'The v field contains characters that are not allowed.'),
            self::onlyError(Rule::string()->chars([Chars::Alpha, Chars::ZenkakuSymbols]), '1'),
        );
    }

    // ---------------------------------------------------------------
    // email / url / ip
    // ---------------------------------------------------------------

    public function testEmail(): void
    {
        $rule = Rule::string()->email();

        $this->assertSame([], self::failedRules($rule, 'user@example.com'));
        $this->assertSame([], self::failedRules($rule, 'user@example.invalid'));
        $this->assertSame(['email'], self::failedRules($rule, 'user@'));
        $this->assertSame(['email'], self::failedRules($rule, 'user'));
    }

    public function testEmailWithDnsRejectsADomainWithoutMxRecord(): void
    {
        $this->assertSame(['email'], self::failedRules(Rule::string()->email(dns: true), 'user@example.invalid'));
        $this->assertSame(['email'], self::failedRules(Rule::string()->email(dns: true), 'not an email'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'https' => ['https://example.com/a?b=c', true];
        yield 'http' => ['http://example.com', true];
        yield 'upper-case scheme' => ['HTTPS://example.com', true];
        yield 'javascript' => ['javascript://%0Aalert(1)', false];
        yield 'file' => ['file:///etc/passwd', false];
        yield 'ftp' => ['ftp://example.com', false];
        yield 'no scheme' => ['example.com', false];
        yield 'malformed with accepted scheme' => ['http://exa mple.com', false];
    }

    #[DataProvider('urls')]
    public function testUrlAcceptsHttpAndHttpsByDefault(string $url, bool $valid): void
    {
        $this->assertSame($valid ? [] : ['url'], self::failedRules(Rule::string()->url(), $url));
    }

    public function testUrlSchemesCanBeChanged(): void
    {
        $rule = Rule::string()->url(['HTTPS', 'ftp']);

        $this->assertSame([], self::failedRules($rule, 'https://example.com'));
        $this->assertSame([], self::failedRules($rule, 'ftp://example.com'));
        $this->assertEquals(
            new ValidationError('url', ['schemes' => ['HTTPS', 'ftp']], 'The v field must be a valid URL.'),
            self::onlyError($rule, 'http://example.com'),
        );
    }

    public function testIp(): void
    {
        $rule = Rule::string()->ip();

        $this->assertSame([], self::failedRules($rule, '192.168.0.1'));
        $this->assertSame([], self::failedRules($rule, '::1'));
        $this->assertSame(['ip'], self::failedRules($rule, '256.0.0.1'));
    }
}
