<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Validation\Sanitize;

final class SanitizeTest extends TestCase
{
    /**
     * @return iterable<string, array{Sanitize, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'trim ascii whitespace' => [Sanitize::Trim, " \t\n\r\0\x0B a b \t\n\r\0\x0B ", 'a b'];
        yield 'trim ideographic space' => [Sanitize::Trim, "\u{3000}あ\u{3000}い\u{3000}", "あ\u{3000}い"];
        yield 'trim keeps inner newlines' => [Sanitize::Trim, " a\nb ", "a\nb"];
        yield 'strip tags' => [Sanitize::StripTags, '<b>bold</b> <script>x</script>', 'bold x'];
        yield 'strip control chars' => [Sanitize::StripControlChars, "a\x00b\x07c\x1Bd\x7Fe\u{85}f\u{9F}g", 'abcdefg'];
        yield 'strip control chars keeps tab and newlines' => [Sanitize::StripControlChars, "a\tb\nc\rd", "a\tb\nc\rd"];
        yield 'strip control chars keeps other characters' => [Sanitize::StripControlChars, "あ\u{A0}😀", "あ\u{A0}😀"];
        yield 'strip newlines' => [Sanitize::StripNewlines, "a\r\nb\nc\rd\te", "abcd\te"];
        yield 'strip tabs' => [Sanitize::StripTabs, "a\tb\t\nc", "ab\nc"];
    }

    #[DataProvider('cases')]
    public function testApply(Sanitize $sanitize, string $input, string $expected): void
    {
        $this->assertSame($expected, $sanitize->apply($input));
    }
}
