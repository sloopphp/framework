<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Support\Str;

final class StrTest extends TestCase
{
    // -------------------------------------------------------
    // camel
    // -------------------------------------------------------

    #[DataProvider('camelProvider')]
    public function testCamelConvertsToCase(string $input, string $expected): void
    {
        $this->assertSame($expected, Str::camel($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function camelProvider(): array
    {
        return [
            'snake_case'          => ['foo_bar', 'fooBar'],
            'kebab-case'          => ['foo-bar', 'fooBar'],
            'space'               => ['foo bar', 'fooBar'],
            'StudlyCase'          => ['FooBar', 'fooBar'],
            'single word'         => ['hello', 'hello'],
            'multiple delimiters' => ['foo_bar-baz qux', 'fooBarBazQux'],
        ];
    }

    // -------------------------------------------------------
    // snake
    // -------------------------------------------------------

    #[DataProvider('snakeProvider')]
    public function testSnakeConvertsToCase(string $input, string $expected): void
    {
        $this->assertSame($expected, Str::snake($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function snakeProvider(): array
    {
        return [
            'camelCase'    => ['fooBar', 'foo_bar'],
            'StudlyCase'   => ['FooBar', 'foo_bar'],
            'kebab-case'   => ['foo-bar', 'foo_bar'],
            'space'        => ['foo bar', 'foo_bar'],
            'single word'  => ['hello', 'hello'],
        ];
    }

    public function testSnakeWithCustomDelimiter(): void
    {
        $this->assertSame('foo-bar', Str::snake('fooBar', '-'));
    }

    // -------------------------------------------------------
    // studly
    // -------------------------------------------------------

    #[DataProvider('studlyProvider')]
    public function testStudlyConvertsToCase(string $input, string $expected): void
    {
        $this->assertSame($expected, Str::studly($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function studlyProvider(): array
    {
        return [
            'snake_case'   => ['foo_bar', 'FooBar'],
            'kebab-case'   => ['foo-bar', 'FooBar'],
            'space'        => ['foo bar', 'FooBar'],
            'camelCase'    => ['fooBar', 'FooBar'],
            'single word'  => ['hello', 'Hello'],
        ];
    }

    // -------------------------------------------------------
    // slug
    // -------------------------------------------------------

    #[DataProvider('slugProvider')]
    public function testSlugConvertsToSlug(string $input, string $expected): void
    {
        $this->assertSame($expected, Str::slug($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function slugProvider(): array
    {
        return [
            'spaces'                     => ['Hello World', 'hello-world'],
            'special characters removed' => ['Hello & World!', 'hello-world'],
            'consecutive spaces'         => ['Hello   World', 'hello-world'],
            'with hyphens'               => ['foo-bar baz', 'foo-bar-baz'],
            'japanese with space'        => ['東京 タワー', '東京-タワー'],
            'numbers with dot removed'   => ['PHP 8.4 Release', 'php-84-release'],
            'emoji removed'              => ['Hello 🌍 World', 'hello-world'],
            'empty string'               => ['', ''],
            'fullwidth chars'            => ['１２３ＡＢＣ', '１２３ａｂｃ'],
            'german uppercase umlaut'    => ['Über Straße', 'über-straße'],
            'turkish uppercase I'        => ['İSTANBUL', 'i̇stanbul'],
        ];
    }

    public function testSlugWithCustomSeparator(): void
    {
        $this->assertSame('hello_world', Str::slug('Hello World', '_'));
    }

    public function testSlugTrimsLeadingAndTrailingSeparator(): void
    {
        $this->assertSame('hello-world', Str::slug('-Hello World-'));
    }

    public function testSlugTrimsLeadingAndTrailingSeparatorCustom(): void
    {
        $this->assertSame('hello_world', Str::slug(' Hello World ', '_'));
    }

    // -------------------------------------------------------
    // random
    // -------------------------------------------------------

    public function testRandomGeneratesStringOfSpecifiedLength(): void
    {
        $result = Str::random(32);

        $this->assertSame(32, \strlen($result));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $result);
    }

    public function testRandomDefaultLengthIsSixteen(): void
    {
        $result = Str::random();

        $this->assertSame(16, \strlen($result));
    }

    public function testRandomGeneratesUniqueValues(): void
    {
        $this->assertNotSame(Str::random(), Str::random());
    }

    public function testRandomGeneratesOnlyAlphanumericChars(): void
    {
        $result = Str::random(100);

        $this->assertSame(100, \strlen($result));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{100}$/', $result);
    }

    public function testRandomLengthOneGeneratesSingleChar(): void
    {
        $result = Str::random(1);

        $this->assertSame(1, \strlen($result));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]$/', $result);
    }

    public function testRandomCoversFullCharset(): void
    {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $seen   = [];
        $result = Str::random(10000);

        for ($i = 0; $i < \strlen($result); $i++) {
            $seen[$result[$i]] = true;
        }

        for ($i = 0; $i < \strlen($chars); $i++) {
            $this->assertArrayHasKey($chars[$i], $seen, 'Character "' . $chars[$i] . '" was never generated');
        }
    }

    // -------------------------------------------------------
    // randomHex
    // -------------------------------------------------------

    public function testRandomHexGeneratesOnlyHexChars(): void
    {
        $result = Str::randomHex(32);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result);
    }

    public function testRandomHexReturnsSpecifiedLength(): void
    {
        $this->assertSame(32, \strlen(Str::randomHex(32)));
        $this->assertSame(16, \strlen(Str::randomHex(16)));
        $this->assertSame(1, \strlen(Str::randomHex(1)));
    }

    public function testRandomHexSupportsOddLength(): void
    {
        $result = Str::randomHex(7);

        $this->assertSame(7, \strlen($result));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $result);
    }

    public function testRandomHexReturnsEmptyForZeroLength(): void
    {
        $this->assertSame('', Str::randomHex(0));
    }

    public function testRandomHexThrowsExceptionForNegativeLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Length must not be negative, got -1.');

        Str::randomHex(-1);
    }

    public function testRandomHexGeneratesUniqueValues(): void
    {
        $this->assertNotSame(Str::randomHex(32), Str::randomHex(32));
    }

    public function testRandomHexCoversFullCharset(): void
    {
        $chars  = '0123456789abcdef';
        $seen   = [];
        $result = Str::randomHex(10000);

        for ($i = 0; $i < \strlen($result); $i++) {
            $seen[$result[$i]] = true;
        }

        for ($i = 0; $i < \strlen($chars); $i++) {
            $this->assertArrayHasKey($chars[$i], $seen, 'Character "' . $chars[$i] . '" was never generated');
        }
    }

    // -------------------------------------------------------
    // truncate
    // -------------------------------------------------------

    public function testTruncateReturnsStringWithinLimit(): void
    {
        $this->assertSame('hello', Str::truncate('hello', 10));
    }

    public function testTruncateCutsStringExceedingLimit(): void
    {
        $this->assertSame('hel...', Str::truncate('hello world', 3));
    }

    public function testTruncateWithCustomSuffix(): void
    {
        $this->assertSame('hel…', Str::truncate('hello world', 3, '…'));
    }

    #[DataProvider('truncateGraphemeProvider')]
    public function testTruncateCountsByGraphemeCluster(string $input, int $limit, string $expected): void
    {
        $this->assertSame($expected, Str::truncate($input, $limit));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function truncateGraphemeProvider(): array
    {
        return [
            'CJK kanji'                        => ['漢字テスト文字列', 4, '漢字テス...'],
            'combining accent e+acute as one'  => ["caf\u{0065}\u{0301}", 4, "caf\u{0065}\u{0301}"],
            'combining accent truncated'       => ["caf\u{0065}\u{0301}s", 4, "caf\u{0065}\u{0301}..."],
            'ZWJ family emoji as one'          => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}abc", 2, "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}a..."],
            'flag emoji as one'                => ["\u{1F1EF}\u{1F1F5}hello", 3, "\u{1F1EF}\u{1F1F5}he..."],
            'skin tone emoji as one'           => ["\u{1F44B}\u{1F3FD}test", 3, "\u{1F44B}\u{1F3FD}te..."],
            'keycap sequence as one'           => ["1\u{FE0F}\u{20E3}234", 3, "1\u{FE0F}\u{20E3}23..."],
            'variation selector text as one'   => ["\u{845B}\u{E0100}ab", 2, "\u{845B}\u{E0100}a..."],
            'mixed ascii and emoji within limit' => ["\u{1F600}\u{1F601}", 5, "\u{1F600}\u{1F601}"],
        ];
    }

    // -------------------------------------------------------
    // contains
    // -------------------------------------------------------

    public function testContainsReturnsTrueWhenFound(): void
    {
        $this->assertTrue(Str::contains('hello world', 'world'));
    }

    public function testContainsReturnsTrueForAnyInArray(): void
    {
        $this->assertTrue(Str::contains('hello world', ['xyz', 'world']));
    }

    public function testContainsReturnsFalseWhenNotFound(): void
    {
        $this->assertFalse(Str::contains('hello', 'xyz'));
    }

    public function testContainsReturnsFalseForEmptyNeedle(): void
    {
        $this->assertFalse(Str::contains('hello', ''));
    }

    // -------------------------------------------------------
    // startsWith
    // -------------------------------------------------------

    public function testStartsWithReturnsTrueOnMatch(): void
    {
        $this->assertTrue(Str::startsWith('hello world', 'hello'));
    }

    public function testStartsWithReturnsTrueForAnyInArray(): void
    {
        $this->assertTrue(Str::startsWith('hello world', ['xyz', 'hello']));
    }

    public function testStartsWithReturnsFalseOnMismatch(): void
    {
        $this->assertFalse(Str::startsWith('hello world', 'world'));
    }

    // -------------------------------------------------------
    // endsWith
    // -------------------------------------------------------

    public function testEndsWithReturnsTrueOnMatch(): void
    {
        $this->assertTrue(Str::endsWith('hello world', 'world'));
    }

    public function testEndsWithReturnsTrueForAnyInArray(): void
    {
        $this->assertTrue(Str::endsWith('hello.php', ['.js', '.php']));
    }

    public function testEndsWithReturnsFalseOnMismatch(): void
    {
        $this->assertFalse(Str::endsWith('hello world', 'hello'));
    }

    // -------------------------------------------------------
    // Cache behavior
    // -------------------------------------------------------

    public function testCamelCacheReturnsSameResultOnSecondCall(): void
    {
        $first  = Str::camel('cache_test_camel');
        $second = Str::camel('cache_test_camel');

        $this->assertSame('cacheTestCamel', $first);
        $this->assertSame($first, $second);
    }

    public function testStudlyCacheReturnsSameResultOnSecondCall(): void
    {
        $first  = Str::studly('cache_test_studly');
        $second = Str::studly('cache_test_studly');

        $this->assertSame('CacheTestStudly', $first);
        $this->assertSame($first, $second);
    }

    public function testSnakeCacheKeyIncludesDelimiter(): void
    {
        $underscore = Str::snake('FooBar');
        $hyphen     = Str::snake('FooBar', '-');

        $this->assertSame('foo_bar', $underscore);
        $this->assertSame('foo-bar', $hyphen);
    }

    // -------------------------------------------------------
    // Boundary values
    // -------------------------------------------------------

    public function testCamelReturnsEmptyForEmptyString(): void
    {
        $this->assertSame('', Str::camel(''));
    }

    public function testSnakeReturnsEmptyForEmptyString(): void
    {
        $this->assertSame('', Str::snake(''));
    }

    public function testSnakeExpandsConsecutiveUppercase(): void
    {
        $this->assertSame('h_t_m_l_parser', Str::snake('HTMLParser'));
    }

    public function testStudlyReturnsEmptyForEmptyString(): void
    {
        $this->assertSame('', Str::studly(''));
    }

    public function testStudlySingleWordWithNoDelimiters(): void
    {
        $this->assertSame('Hello', Str::studly('hello'));
    }

    public function testRandomReturnsEmptyForZeroLength(): void
    {
        $this->assertSame('', Str::random(0));
    }

    public function testTruncateReturnsEmptyForEmptyString(): void
    {
        $this->assertSame('', Str::truncate('', 5));
    }

    public function testTruncateWithZeroLimitReturnsSuffixOnly(): void
    {
        $this->assertSame('...', Str::truncate('hello', 0));
    }

    public function testContainsReturnsFalseForAllEmptyNeedles(): void
    {
        $this->assertFalse(Str::contains('hello', ['', '']));
    }

    public function testStartsWithReturnsFalseForEmptyHaystack(): void
    {
        $this->assertFalse(Str::startsWith('', 'a'));
    }

    public function testEndsWithReturnsFalseForEmptyHaystack(): void
    {
        $this->assertFalse(Str::endsWith('', 'a'));
    }

    public function testRandomThrowsExceptionForNegativeLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Length must not be negative, got -1.');

        Str::random(-1);
    }

    public function testRandomThrowsExceptionIncludesActualValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Length must not be negative, got -10.');

        Str::random(-10);
    }

    public function testTruncateThrowsExceptionForNegativeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must not be negative, got -1.');

        Str::truncate('hello', -1);
    }

    public function testTruncateThrowsExceptionIncludesActualValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must not be negative, got -5.');

        Str::truncate('hello', -5);
    }
}
