<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Security\Html;

final class HtmlTest extends TestCase
{
    protected function tearDown(): void
    {
        ini_restore('default_charset');
    }

    // -------------------------------------------------------
    // escape
    // -------------------------------------------------------

    #[DataProvider('escapeProvider')]
    public function testEscapeEncodesHtmlSpecialCharacters(string $input, string $expected): void
    {
        $this->assertSame($expected, Html::escape($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function escapeProvider(): array
    {
        return [
            'plain text'          => ['hello', 'hello'],
            'empty string'        => ['', ''],
            'tags'                => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            'ampersand'           => ['a & b', 'a &amp; b'],
            'double quote'        => ['"a"', '&quot;a&quot;'],
            'single quote'        => ["'a'", '&#039;a&#039;'],
            'already an entity'   => ['Tom &amp; Jerry', 'Tom &amp;amp; Jerry'],
            'multibyte unchanged' => ['日本語', '日本語'],
        ];
    }

    public function testEscapeReturnsEmptyStringForNull(): void
    {
        $this->assertSame('', Html::escape(null));
    }

    public function testEscapeReplacesInvalidUtf8WithReplacementCharacter(): void
    {
        $this->assertSame("a\u{FFFD}(b", Html::escape("a\xC3(b"));
    }

    public function testEscapeUsesUtf8RegardlessOfDefaultCharset(): void
    {
        ini_set('default_charset', 'ISO-8859-1');

        $this->assertSame("a\u{FFFD}(b", Html::escape("a\xC3(b"));
    }

    // -------------------------------------------------------
    // e() helper
    // -------------------------------------------------------

    public function testHelperUsesUtf8RegardlessOfDefaultCharset(): void
    {
        ini_set('default_charset', 'ISO-8859-1');

        $this->assertSame("a\u{FFFD}(b", e("a\xC3(b"));
    }

    public function testHelperDelegatesToEscape(): void
    {
        $this->assertSame('&lt;b&gt; &amp;amp; &#039;', e('<b> &amp; \''));
    }

    public function testHelperReturnsEmptyStringForNull(): void
    {
        $this->assertSame('', e(null));
    }

    // -------------------------------------------------------
    // json
    // -------------------------------------------------------

    #[DataProvider('jsonProvider')]
    public function testJsonEncodesValueAsScriptSafeLiteral(mixed $input, string $expected): void
    {
        $this->assertSame($expected, Html::json($input));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function jsonProvider(): array
    {
        return [
            'closing script tag' => ['</script><b>', '"\u003C\\/script\u003E\u003Cb\u003E"'],
            'ampersand'          => ['a&b', '"a\u0026b"'],
            'quotes'             => ['\'"', '"\u0027\u0022"'],
            'line separators'    => ["\u{2028}\u{2029}", '"\u2028\u2029"'],
            'integer'            => [42, '42'],
            'null'               => [null, 'null'],
            'list'               => [[1, 'x'], '[1,"x"]'],
            'map'                => [['k' => '<v>'], '{"k":"\u003Cv\u003E"}'],
        ];
    }

    public function testJsonReplacesInvalidUtf8WithReplacementCharacter(): void
    {
        $this->assertSame('"a\ufffd(b"', Html::json("a\xC3(b"));
    }

    public function testJsonThrowsForValueThatCannotBeEncoded(): void
    {
        $this->expectException(\JsonException::class);

        Html::json(\INF);
    }
}
