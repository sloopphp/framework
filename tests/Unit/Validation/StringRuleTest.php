<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\Chars;
use Sloop\Validation\LengthUnit;
use Sloop\Validation\Rule;
use Sloop\Validation\ValidationError;

final class StringRuleTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    /**
     * A value the given set accepts, taken from the charSets() provider.
     *
     * @param array<string, string> $samples
     */
    private static function sample(array $samples, Chars $set): string
    {
        return $samples[$set->value] ?? self::fail('charSets() has no case for ' . $set->value . '.');
    }

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
        self::assertErrorSame(
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
        self::assertErrorSame(
            new ValidationError('maxLength', ['max' => 1, 'unit' => 'graphemes'], 'The v field must not be longer than 1 character.'),
            self::onlyError(Rule::string()->maxLength(1), 'ab'),
        );
        self::assertErrorSame(
            new ValidationError('exactLength', ['length' => 3, 'unit' => 'graphemes'], 'The v field must be exactly 3 characters.'),
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

    /**
     * @return iterable<string, array{LengthUnit, int}>
     */
    public static function lengthUnits(): iterable
    {
        yield 'graphemes' => [LengthUnit::Graphemes, 1];
        yield 'codepoints' => [LengthUnit::Codepoints, 5];
        yield 'bytes' => [LengthUnit::Bytes, 18];
    }

    #[DataProvider('lengthUnits')]
    public function testLengthRulesCountInTheGivenUnit(LengthUnit $unit, int $length): void
    {
        $family = '👨‍👩‍👧'; // 1 grapheme, 5 code points, 18 bytes

        $this->assertSame([], self::failedRules(Rule::string()->minLength($length, unit: $unit), $family));
        $this->assertSame(['minLength'], self::failedRules(Rule::string()->minLength($length + 1, unit: $unit), $family));
        $this->assertSame([], self::failedRules(Rule::string()->maxLength($length, unit: $unit), $family));
        $this->assertSame(['maxLength'], self::failedRules(Rule::string()->maxLength($length - 1, unit: $unit), $family));
        $this->assertSame([], self::failedRules(Rule::string()->exactLength($length, unit: $unit), $family));
        $this->assertSame(['exactLength'], self::failedRules(Rule::string()->exactLength($length + 1, unit: $unit), $family));
        $this->assertSame([], self::failedRules(Rule::string()->blockSize($length, unit: $unit), $family));
        $this->assertSame(['blockSize'], self::failedRules(Rule::string()->blockSize($length + 1, unit: $unit), $family));
    }

    public function testCodepointsCountACombiningMarkSeparately(): void
    {
        $decomposed = "e\u{0301}";

        $this->assertSame([], self::failedRules(Rule::string()->exactLength(1), $decomposed));
        $this->assertSame([], self::failedRules(Rule::string()->exactLength(2, unit: LengthUnit::Codepoints), $decomposed));
    }

    public function testLengthErrorsCarryTheirUnit(): void
    {
        self::assertErrorSame(
            new ValidationError('minLength', ['min' => 4, 'unit' => 'codepoints'], 'The v field must be at least 4 characters.'),
            self::onlyError(Rule::string()->minLength(4, unit: LengthUnit::Codepoints), 'abc'),
        );
        self::assertErrorSame(
            new ValidationError('maxLength', ['max' => 2, 'unit' => 'bytes'], 'The v field must not be longer than 2 bytes.'),
            self::onlyError(Rule::string()->maxLength(2, unit: LengthUnit::Bytes), 'あ'),
        );
        self::assertErrorSame(
            new ValidationError('exactLength', ['length' => 2, 'unit' => 'bytes'], 'The v field must be exactly 2 bytes.'),
            self::onlyError(Rule::string()->exactLength(2, unit: LengthUnit::Bytes), 'あ'),
        );
    }

    public function testLengthMessagesUseTheSingularForOneAndNoDigitGrouping(): void
    {
        $this->assertSame(
            'The v field must not be longer than 1 byte.',
            self::onlyError(Rule::string()->maxLength(1, unit: LengthUnit::Bytes), 'あ')->message,
        );
        $this->assertSame(
            'The v field must be at least 1000 characters.',
            self::onlyError(Rule::string()->minLength(1000), 'a')->message,
        );
        $this->assertSame(
            'The v field length must be a multiple of 1000 bytes.',
            self::onlyError(Rule::string()->blockSize(1000, unit: LengthUnit::Bytes), 'a')->message,
        );
    }

    public function testOwnMessageCanSelectOnTheUnit(): void
    {
        $message = '{label}は{max}{unit, select, bytes {バイト} other {文字}}以内';

        $this->assertSame(
            'vは3バイト以内',
            self::onlyError(Rule::string()->maxLength(3, $message, LengthUnit::Bytes), 'ああ')->message,
        );
        $this->assertSame(
            'vは1文字以内',
            self::onlyError(Rule::string()->maxLength(1, $message), 'ああ')->message,
        );
    }

    // ---------------------------------------------------------------
    // blockSize
    // ---------------------------------------------------------------

    public function testBlockSizeRequiresAMultipleOfTheSize(): void
    {
        $rule = Rule::string()->blockSize(4);

        $this->assertSame([], self::failedRules($rule, 'abcd'));
        $this->assertSame([], self::failedRules($rule, 'abcdefgh'));
        $this->assertSame(['blockSize'], self::failedRules($rule, 'abc'));
        $this->assertSame(['blockSize'], self::failedRules($rule, 'abcde'));
    }

    public function testBlockSizeCountsInTheGivenUnit(): void
    {
        $this->assertSame([], self::failedRules(Rule::string()->blockSize(2), 'ああ'));
        $this->assertSame(['blockSize'], self::failedRules(Rule::string()->blockSize(2, unit: LengthUnit::Bytes), "\u{00E9}a"));
        $this->assertSame([], self::failedRules(Rule::string()->blockSize(3, unit: LengthUnit::Bytes), 'ああ'));
        $this->assertSame(['blockSize'], self::failedRules(Rule::string()->blockSize(2, unit: LengthUnit::Codepoints), "e\u{0301}a"));
    }

    public function testBlockSizeErrorCarriesItsSizeAndUnit(): void
    {
        self::assertErrorSame(
            new ValidationError('blockSize', ['size' => 4, 'unit' => 'graphemes'], 'The v field length must be a multiple of 4 characters.'),
            self::onlyError(Rule::string()->blockSize(4), 'abc'),
        );
        self::assertErrorSame(
            new ValidationError('blockSize', ['size' => 2, 'unit' => 'bytes'], 'The v field length must be a multiple of 2 bytes.'),
            self::onlyError(Rule::string()->blockSize(2, unit: LengthUnit::Bytes), 'あ'),
        );
    }

    public function testBlockSizeOfOneAcceptsAnyLength(): void
    {
        $this->assertSame([], self::failedRules(Rule::string()->blockSize(1), 'abc'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidBlockSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidBlockSizes')]
    public function testBlockSizeBelowOneThrows(int $size): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->blockSize($size));

        $this->assertSame('Block size must be at least 1, got ' . $size . '.', $e->getMessage());
    }

    // ---------------------------------------------------------------
    // regex / in / notIn
    // ---------------------------------------------------------------

    public function testRegex(): void
    {
        $rule = Rule::string()->regex('/\A[a-z]+\z/');

        $this->assertSame([], self::failedRules($rule, 'abc'));
        self::assertErrorSame(
            new ValidationError('regex', ['pattern' => '/\A[a-z]+\z/'], 'The v field format is invalid.'),
            self::onlyError($rule, 'ab1'),
        );
    }

    public function testRegexAbortedByPcreCountsAsFailure(): void
    {
        // The subject has to end in the character the pattern needs, or PCRE
        // answers from its pre-scan without ever backtracking; with the limit
        // lifted this pattern matches, so only an abort yields 'regex'.
        $limit = \ini_get('pcre.backtrack_limit');
        $jit   = \ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '1');
        ini_set('pcre.jit', '0');
        try {
            $this->assertSame(['regex'], self::failedRules(Rule::string()->regex('/(a+)+c/'), 'aaaaaaaaaac'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }

        $this->assertSame([], self::failedRules(Rule::string()->regex('/(a+)+c/'), 'aaaaaaaaaac'));
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
        self::assertErrorSame(
            new ValidationError('in', ['values' => ['a', 'b']], 'The selected v is invalid.'),
            self::onlyError($rule, 'c'),
        );
    }

    public function testNotIn(): void
    {
        $rule = Rule::string()->notIn(['admin']);

        $this->assertSame([], self::failedRules($rule, 'user'));
        self::assertErrorSame(new ValidationError('notIn', ['values' => ['admin']], 'The selected v is invalid.'), self::onlyError($rule, 'admin'));
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
        yield 'notChars' => ['notChars'];
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
        yield 'hiragana' => [Chars::Hiragana, ['ひらがなー', "か\u{3099}", 'か゛'], ['カ', '漢', 'a', '・', '〜', '、']];
        yield 'katakana' => [Chars::Katakana, ['カタカナー', "カ\u{3099}", 'ヴ', 'ヷヺ', 'ㇰ', 'ジョン・スミス'], ['ひ', '漢', '「」', '、', 'ｶ', 'ｰ', 'ﾞ', '･']];
        yield 'hankaku katakana' => [Chars::HankakuKatakana, ['ｶﾀｶﾅ', 'ｶﾞｰ', 'ﾃﾞｰﾀ', 'ｼﾞｮﾝ･ｽﾐｽ', 'ｦﾝ'], ['カ', 'ー', '・', '｡', '｢']];
        yield 'kanji' => [Chars::Kanji, ['漢字々〇'], ['ひ', 'カ', '、', '。', '「', '〆']];
        yield 'zenkaku symbols' => [Chars::ZenkakuSymbols, ['、。「」・゠！＃（）＝￥'], ['!', 'Ａ', '１', '･', "\u{3000}"]];
        yield 'zenkaku alpha' => [Chars::ZenkakuAlpha, ['ＡＺａｚ'], ['A', 'z', '０', '＠']];
        yield 'zenkaku numeric' => [Chars::ZenkakuNumeric, ['０１８９'], ['0', 'Ａ', '①']];
        yield 'zenkaku space' => [Chars::ZenkakuSpace, ["\u{3000}"], [' ', '、']];
        yield 'emoji' => [Chars::Emoji, ['😀', '👍🏽', '👨‍👩‍👧', '🇯🇵', '❤️'], ['a', '1']];
        yield 'hex' => [Chars::Hex, ['09afAF'], ['g', 'G']];
        yield 'symbols' => [Chars::Symbols, ['!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~'], ['a', '0', ' ', '！', '・']];
        yield 'supplementary' => [Chars::Supplementary, ['𠮷𩸽', '😀', "\u{10000}\u{10FFFF}"], ['☀', '漢', 'a', "\u{FFFF}"]];
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

    public function testEverySetPairBuildsAValidCharacterClass(): void
    {
        $samples = [];
        foreach (self::charSets() as [$set, $accepted]) {
            $samples[$set->value] = $accepted[0];
        }

        foreach (Chars::cases() as $first) {
            foreach (Chars::cases() as $second) {
                $pair = $first->value . ' + ' . $second->value;
                $rule = Rule::string()->chars([$first, $second]);
                $this->assertSame([], self::failedRules($rule, self::sample($samples, $first)), $pair);
                $this->assertSame([], self::failedRules($rule, self::sample($samples, $second)), $pair);
                $this->assertSame(['chars'], self::failedRules($rule, "\u{FFFD}"), $pair);
            }
        }
    }

    public function testDotsAndPunctuationAreEscapedInTheCharacterClass(): void
    {
        $this->assertSame([], self::failedRules(Rule::string()->chars([Chars::Dots]), '...'));
        $this->assertSame([], self::failedRules(Rule::string()->chars([Chars::Punctuation, Chars::Dots]), '.,!?'));
        $this->assertSame(['chars'], self::failedRules(Rule::string()->chars([Chars::Dots]), 'a'));
    }

    public function testCharsMatchesTheWholeValue(): void
    {
        $rule = Rule::string()->chars([Chars::Alpha]);

        $this->assertSame(['chars'], self::failedRules($rule, "abc\n"));
        $this->assertSame(['chars'], self::failedRules($rule, "\nabc"));
        $this->assertSame(['chars'], self::failedRules($rule, "ab\nc"));
    }

    public function testCharsAcceptsTheUnionOfTheSets(): void
    {
        $rule = Rule::string()->chars([Chars::Uppercase, Chars::Numeric]);

        $this->assertSame([], self::failedRules($rule, 'A1'));
        $this->assertSame(['chars'], self::failedRules($rule, 'a1'));
    }

    public function testCharsErrorListsTheSetNames(): void
    {
        self::assertErrorSame(
            new ValidationError('chars', ['chars' => ['alpha', 'zenkaku_symbols'], 'listed' => []], 'The v field contains characters that are not allowed.'),
            self::onlyError(Rule::string()->chars([Chars::Alpha, Chars::ZenkakuSymbols]), '1'),
        );
    }

    public function testCharsAcceptsListedCharactersAlongsideTheSets(): void
    {
        $rule = Rule::string()->chars([Chars::Alpha, '#*']);

        $this->assertSame([], self::failedRules($rule, 'a#b*'));
        $this->assertSame([], self::failedRules($rule, '##'));
        $this->assertSame(['chars'], self::failedRules($rule, 'a!'));
    }

    public function testCharsAcceptsTheCharactersOfEveryListedString(): void
    {
        $rule = Rule::string()->chars(['ab', '#']);

        $this->assertSame([], self::failedRules($rule, 'a#b'));
        $this->assertSame(['chars'], self::failedRules($rule, 'a#c'));
    }

    public function testCharsWithOnlyListedCharacters(): void
    {
        $rule = Rule::string()->chars(['abc']);

        $this->assertSame([], self::failedRules($rule, 'cab'));
        $this->assertSame(['chars'], self::failedRules($rule, 'abx'));
    }

    public function testCharsMatchesAListedCharacterAsAWholeGraphemeCluster(): void
    {
        $this->assertSame([], self::failedRules(Rule::string()->chars(['👨‍👩‍👧']), '👨‍👩‍👧👨‍👩‍👧'));
        $this->assertSame(['chars'], self::failedRules(Rule::string()->chars(['👨‍👩‍👧']), '👨👩'));
        $this->assertSame(['chars'], self::failedRules(Rule::string()->chars(['👍🏻']), '👍'));
        $this->assertSame(['chars'], self::failedRules(Rule::string()->chars(['a']), "a\u{0301}"));
    }

    public function testCharsComparesListedCharactersInNfc(): void
    {
        $this->assertSame([], self::failedRules(Rule::string()->chars([Chars::Alpha, "\u{00E9}"]), "e\u{0301}"));
        $this->assertSame([], self::failedRules(Rule::string()->chars(["e\u{0301}"]), "\u{00E9}"));
        $this->assertSame('ce' . "\u{0301}", self::valueOf(Rule::string()->chars([Chars::Alpha, "\u{00E9}"]), "ce\u{0301}"));
    }

    public function testCharsErrorSeparatesTheSetsFromTheListedCharacters(): void
    {
        self::assertErrorSame(
            new ValidationError('chars', ['chars' => ['numeric'], 'listed' => ['alpha', '#']], 'The v field contains characters that are not allowed.'),
            self::onlyError(Rule::string()->chars([Chars::Numeric, 'alpha', '#']), 'x'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function charListRules(): iterable
    {
        yield 'chars' => ['chars'];
        yield 'notChars' => ['notChars'];
    }

    #[DataProvider('charListRules')]
    public function testEmptyListedCharactersThrow(string $method): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->{$method}([Chars::Alpha, '']));

        $this->assertSame($method . '() needs at least one character in each listed string.', $e->getMessage());
    }

    #[DataProvider('charListRules')]
    public function testInvalidUtf8ListedCharactersThrow(string $method): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->{$method}(["\xFF"]));

        $this->assertSame($method . '() needs listed characters in valid UTF-8.', $e->getMessage());
    }

    // ---------------------------------------------------------------
    // notChars
    // ---------------------------------------------------------------

    public function testNotCharsFailsOnAnyCharacterOfTheSets(): void
    {
        $rule = Rule::string()->notChars([Chars::Emoji]);

        $this->assertSame([], self::failedRules($rule, 'hello、世界'));
        $this->assertSame(['notChars'], self::failedRules($rule, 'hi 😀'));
        $this->assertSame(['notChars'], self::failedRules($rule, '☀'));
    }

    public function testNotCharsEmojiAlsoRejectsTextSymbolsAndTheZeroWidthJoiner(): void
    {
        $rule = Rule::string()->notChars([Chars::Emoji]);

        $this->assertSame(['notChars'], self::failedRules($rule, 'Acme©'));
        $this->assertSame(['notChars'], self::failedRules($rule, 'Brand™'));
        $this->assertSame(['notChars'], self::failedRules($rule, "\u{0DC1}\u{0DCA}\u{200D}\u{0DBB}\u{0DD3}"));
    }

    public function testNotCharsListedNewlinesDoNotMatchCrlf(): void
    {
        $this->assertSame([], self::failedRules(Rule::string()->notChars(["\r", "\n"]), "a\r\nb"));
        $this->assertSame(['notChars'], self::failedRules(Rule::string()->notChars([Chars::Newlines]), "a\r\nb"));
    }

    public function testNotCharsSupplementaryMatchesWhatUtf8mb3CannotStore(): void
    {
        $rule = Rule::string()->notChars([Chars::Supplementary]);

        $this->assertSame([], self::failedRules($rule, '☀❤漢'));
        $this->assertSame(['notChars'], self::failedRules($rule, '𠮷野家'));
        $this->assertSame(['notChars'], self::failedRules($rule, '😀'));
    }

    public function testNotCharsFailsOnAListedCharacter(): void
    {
        $rule = Rule::string()->notChars([Chars::HankakuKatakana, 'lI1O0']);

        $this->assertSame([], self::failedRules($rule, 'passWord'));
        $this->assertSame(['notChars'], self::failedRules($rule, 'pass1'));
        $this->assertSame(['notChars'], self::failedRules($rule, 'ｶﾅ'));
    }

    public function testNotCharsComparesListedCharactersAsNfcGraphemeClusters(): void
    {
        $this->assertSame(['notChars'], self::failedRules(Rule::string()->notChars(["\u{00E9}"]), "cafe\u{0301}"));
        $this->assertSame([], self::failedRules(Rule::string()->notChars(['l']), "l\u{0303}"));
        $this->assertSame([], self::failedRules(Rule::string()->notChars(['👍🏻']), '👍'));
    }

    public function testNotCharsFailsWhenPcreGivesUp(): void
    {
        $jit       = \ini_get('pcre.jit');
        $backtrack = \ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            // A pattern no other test builds: PHP caches each compiled pattern with
            // its JIT code, and a cached JIT pattern ignores pcre.jit=0 and the
            // backtrack limit, so a shared pattern would let PCRE succeed here.
            $this->assertSame(['notChars'], self::failedRules(Rule::string()->notChars([Chars::Letter, Chars::Tabs]), str_repeat('-', 50000)));
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $backtrack);
        }
    }

    public function testListedCharacterRulesKeepMemoryFlatOnALongValue(): void
    {
        $value = str_repeat('a', 1_000_000);
        $rules = [
            Rule::string()->chars([Chars::Numeric, 'a']),
            Rule::string()->notChars(['"']),
            Rule::string()->minCharClasses(2, [Chars::Numeric, 'a']),
        ];

        foreach ($rules as $rule) {
            memory_reset_peak_usage();
            $before = memory_get_usage();
            self::failedRules($rule, $value);
            $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
        }
    }

    public function testNotCharsError(): void
    {
        self::assertErrorSame(
            new ValidationError('notChars', ['chars' => ['emoji'], 'listed' => ['#']], 'The v field contains characters that are not allowed.'),
            self::onlyError(Rule::string()->notChars([Chars::Emoji, '#']), 'a#'),
        );
    }

    // ---------------------------------------------------------------
    // minCharClasses
    // ---------------------------------------------------------------

    public function testMinCharClassesCountsTheSetsTheValueUses(): void
    {
        $rule = Rule::string()->minCharClasses(3, [Chars::Uppercase, Chars::Lowercase, Chars::Numeric, Chars::Symbols]);

        $this->assertSame([], self::failedRules($rule, 'abcDEF12'));
        $this->assertSame([], self::failedRules($rule, 'abc!12'));
        $this->assertSame(['minCharClasses'], self::failedRules($rule, 'abcdef12'));
        $this->assertSame(['minCharClasses'], self::failedRules($rule, 'あいう1'));
    }

    public function testMinCharClassesRequiresAllWhenTheMinimumIsTheCount(): void
    {
        $rule = Rule::string()->minCharClasses(2, [Chars::Lowercase, Chars::Numeric]);

        $this->assertSame([], self::failedRules($rule, 'a1'));
        $this->assertSame(['minCharClasses'], self::failedRules($rule, 'abc'));
    }

    public function testMinCharClassesCountsAListedStringAsOneClass(): void
    {
        $rule = Rule::string()->minCharClasses(3, [Chars::Lowercase, Chars::Numeric, '!@']);

        $this->assertSame([], self::failedRules($rule, 'a1!'));
        $this->assertSame(['minCharClasses'], self::failedRules($rule, 'a!@'));
    }

    public function testMinCharClassesCountsOverlappingSetsIndependently(): void
    {
        $this->assertSame([], self::failedRules(Rule::string()->minCharClasses(2, [Chars::Alpha, Chars::Uppercase]), 'ABC'));
    }

    public function testMinCharClassesComparesListedCharactersAsNfcGraphemeClusters(): void
    {
        $rule = Rule::string()->minCharClasses(2, [Chars::Lowercase, "\u{00E9}"]);

        $this->assertSame([], self::failedRules($rule, "ae\u{0301}"));
        $this->assertSame(['minCharClasses'], self::failedRules(Rule::string()->minCharClasses(2, [Chars::Numeric, 'a']), "1a\u{0301}"));
    }

    public function testMinCharClassesError(): void
    {
        self::assertErrorSame(
            new ValidationError('minCharClasses', ['min' => 2, 'chars' => ['lowercase', 'numeric'], 'listed' => ['!']], 'The v field must contain at least 2 kinds of characters.'),
            self::onlyError(Rule::string()->minCharClasses(2, [Chars::Lowercase, Chars::Numeric, '!']), 'abc'),
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function invalidCharClassMinimums(): iterable
    {
        yield 'zero' => [0, 'minCharClasses() needs a minimum of at least 1, got 0.'];
        yield 'more than the sets' => [3, 'minCharClasses() needs a minimum of at most 2 (the number of sets given), got 3.'];
    }

    #[DataProvider('invalidCharClassMinimums')]
    public function testMinCharClassesRejectsAMinimumOutsideTheSets(int $min, string $message): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->minCharClasses($min, [Chars::Lowercase, Chars::Numeric]));

        $this->assertSame($message, $e->getMessage());
    }

    public function testMinCharClassesRejectsAnEmptySetList(): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->minCharClasses(1, []));

        $this->assertSame('minCharClasses() needs at least one value.', $e->getMessage());
    }

    /**
     * @return iterable<string, array{list<Chars|string>}>
     */
    public static function duplicatedCharClasses(): iterable
    {
        yield 'same case' => [[Chars::Alpha, Chars::Alpha]];
        yield 'same listed string' => [['ab', Chars::Numeric, 'ab']];
    }

    /**
     * @param list<Chars|string> $sets
     */
    #[DataProvider('duplicatedCharClasses')]
    public function testMinCharClassesRejectsASetGivenTwice(array $sets): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->minCharClasses(2, $sets));

        $this->assertSame('minCharClasses() needs each set at most once.', $e->getMessage());
    }

    public function testMinCharClassesTellsACaseFromAListedStringWithTheSameSpelling(): void
    {
        $this->assertSame([], self::failedRules(Rule::string()->minCharClasses(2, [Chars::Alpha, 'alpha']), 'Zl'));
    }

    public function testMinCharClassesRejectsInvalidUtf8ListedCharacters(): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->minCharClasses(1, ["\xFF"]));

        $this->assertSame('minCharClasses() needs listed characters in valid UTF-8.', $e->getMessage());
    }

    public function testMinCharClassesRejectsAnEmptyListedString(): void
    {
        $rule = Rule::string();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $rule->minCharClasses(1, ['']));

        $this->assertSame('minCharClasses() needs at least one character in each listed string.', $e->getMessage());
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
        self::assertErrorSame(
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
