<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use IntlChar;
use Normalizer;
use PHPUnit\Framework\TestCase;
use Sloop\Validation\StreamSafe;

final class StreamSafeTest extends TestCase
{
    /**
     * Every code point whose NFKD form ends with a non-starter, with whether the form is all non-starters and how many it ends with.
     *
     * @return array<int, array{bool, int}>
     */
    private static function nonStarterEdges(): array
    {
        $edges = [];
        for ($cp = 0; $cp <= 0x10FFFF; ++$cp) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                continue;
            }
            $char       = IntlChar::chr($cp);
            $decomposed = Normalizer::getRawDecomposition($char, Normalizer::FORM_KD) === null ? $char : Normalizer::normalize($char, Normalizer::FORM_KD);
            if (!\is_string($decomposed)) {
                continue;
            }
            $codePoints = mb_str_split($decomposed, 1, 'UTF-8');
            $trailing   = 0;
            for ($i = \count($codePoints) - 1; $i >= 0 && IntlChar::getCombiningClass($codePoints[$i]) !== 0; --$i) {
                ++$trailing;
            }
            if ($trailing > 0) {
                $edges[$cp] = [$trailing === \count($codePoints), $trailing];
            }
        }

        return $edges;
    }

    public function testEveryCodePointEndingWithNonStartersIsCounted(): void
    {
        $missed = [];
        foreach (self::nonStarterEdges() as $cp => [$allNonStarters, $count]) {
            $char   = ($allNonStarters ? 'a' : '') . IntlChar::chr($cp);
            $over   = $char . str_repeat("\u{0301}", StreamSafe::MAX_NON_STARTERS + 1 - $count);
            $atMost = $char . str_repeat("\u{0301}", StreamSafe::MAX_NON_STARTERS - $count);
            if (StreamSafe::holds($over) || !StreamSafe::holds($atMost)) {
                $missed[] = \sprintf('U+%04X', $cp);
            }
        }

        $this->assertSame([], $missed);
    }

    public function testEveryCodePointOfNonStartersOnlyIsFoundRepeated(): void
    {
        $missed = [];
        foreach (self::nonStarterEdges() as $cp => [$allNonStarters, $count]) {
            if (!$allNonStarters) {
                continue;
            }
            $char   = IntlChar::chr($cp);
            $over   = 'a' . str_repeat($char, intdiv(StreamSafe::MAX_NON_STARTERS, $count) + 1);
            $atMost = 'a' . str_repeat($char, intdiv(StreamSafe::MAX_NON_STARTERS, $count));
            if (StreamSafe::holds($over) || !StreamSafe::holds($atMost)) {
                $missed[] = \sprintf('U+%04X', $cp);
            }
        }

        $this->assertSame([], $missed);
    }

    public function testNoCodePointAddsMoreThanTheShortestRunAssumes(): void
    {
        $longestTrailing = 0;
        $longestAll      = 0;
        foreach (self::nonStarterEdges() as [$allNonStarters, $count]) {
            if ($allNonStarters) {
                $longestAll = max($longestAll, $count);
            } else {
                $longestTrailing = max($longestTrailing, $count);
            }
        }

        // StreamSafe::SHORTEST_RUN (14) rests on these two numbers.
        $this->assertSame([3, 2], [$longestTrailing, $longestAll]);
    }

    public function testNoCodePointStartsWithNonStartersBeforeAStarter(): void
    {
        $leading = [];
        for ($cp = 0; $cp <= 0x10FFFF; ++$cp) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                continue;
            }
            $char       = IntlChar::chr($cp);
            $decomposed = Normalizer::getRawDecomposition($char, Normalizer::FORM_KD) === null ? null : Normalizer::normalize($char, Normalizer::FORM_KD);
            if (!\is_string($decomposed)) {
                continue;
            }
            $codePoints = mb_str_split($decomposed, 1, 'UTF-8');
            $starters   = array_filter($codePoints, static fn (string $c): bool => IntlChar::getCombiningClass($c) === 0);
            if ($starters !== [] && IntlChar::getCombiningClass($codePoints[0]) !== 0) {
                $leading[] = \sprintf('U+%04X', $cp);
            }
        }

        // StreamSafe counts a code point with a starter by its trailing non-starters alone.
        $this->assertSame([], $leading);
    }
}
