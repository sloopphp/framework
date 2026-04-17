<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\LikePattern;

final class LikePatternTest extends TestCase
{
    public function testEscapeReturnsSameStringWhenNoWildcards(): void
    {
        $this->assertSame('hello', LikePattern::escape('hello'));
    }

    public function testEscapeReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', LikePattern::escape(''));
    }

    public function testEscapePrefixesPercentWithBackslash(): void
    {
        $this->assertSame('100\\% off', LikePattern::escape('100% off'));
    }

    public function testEscapePrefixesUnderscoreWithBackslash(): void
    {
        $this->assertSame('user\\_name', LikePattern::escape('user_name'));
    }

    public function testEscapeDoublesBackslashItself(): void
    {
        $this->assertSame('path\\\\to', LikePattern::escape('path\\to'));
    }

    public function testEscapeHandlesBackslashBeforePercentWithoutDoubleEscaping(): void
    {
        $this->assertSame('\\\\\\%', LikePattern::escape('\\%'));
    }

    public function testEscapeHandlesAllWildcardsTogether(): void
    {
        $this->assertSame(
            'foo\\_bar\\%baz\\\\qux',
            LikePattern::escape('foo_bar%baz\\qux'),
        );
    }

    public function testEscapeAcceptsCustomEscapeCharacter(): void
    {
        $this->assertSame('100!% off', LikePattern::escape('100% off', '!'));
    }

    public function testEscapeWithCustomEscapeEscapesItself(): void
    {
        $this->assertSame('a!!b', LikePattern::escape('a!b', '!'));
    }

    public function testEscapeWithCustomEscapeHandlesAllWildcards(): void
    {
        $this->assertSame('!_!%!!', LikePattern::escape('_%!', '!'));
    }

    public function testEscapeDoesNotDoubleEscapeAlreadyEscapedPercent(): void
    {
        $this->assertSame('\\\\\\%\\\\\\_', LikePattern::escape('\\%\\_'));
    }

    public function testEscapeHandlesConsecutivePercent(): void
    {
        $this->assertSame('\\%\\%', LikePattern::escape('%%'));
    }

    public function testEscapeHandlesConsecutiveUnderscore(): void
    {
        $this->assertSame('\\_\\_', LikePattern::escape('__'));
    }

    public function testEscapeHandlesWildcardAtStart(): void
    {
        $this->assertSame('\\%abc', LikePattern::escape('%abc'));
    }

    public function testEscapeHandlesWildcardAtEnd(): void
    {
        $this->assertSame('abc\\%', LikePattern::escape('abc%'));
    }

    public function testEscapePreservesMultibyteCharactersInValue(): void
    {
        $this->assertSame('こんにちは\\%', LikePattern::escape('こんにちは%'));
    }

    #[DataProvider('provideInvalidEscapeChars')]
    public function testEscapeThrowsOnInvalidEscapeLength(string $escape): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Escape character must be exactly one byte');

        LikePattern::escape('anything', $escape);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidEscapeChars(): array
    {
        return [
            'empty string'    => [''],
            'two ascii chars' => ['ab'],
            'multibyte char'  => ['あ'],
        ];
    }

    #[DataProvider('provideWildcardEscapeChars')]
    public function testEscapeThrowsWhenEscapeIsLikeWildcard(string $escape): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Escape character must not be a LIKE wildcard');

        LikePattern::escape('anything', $escape);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideWildcardEscapeChars(): array
    {
        return [
            'percent'    => ['%'],
            'underscore' => ['_'],
        ];
    }
}
