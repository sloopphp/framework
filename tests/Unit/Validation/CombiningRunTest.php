<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use IntlChar;
use Normalizer;
use PHPUnit\Framework\TestCase;
use Sloop\Validation\CombiningRun;

final class CombiningRunTest extends TestCase
{
    // UNORM_MAYBE in ICU; IntlChar has no constant for the values of NFC_Quick_Check.
    private const int NFC_QUICK_CHECK_MAYBE = 2;

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

    // One scan of every code point serves all three checks; it takes about a third of a second.
    /**
     * Whether NFC may compose the code point with the one before: a non-starter, a starter whose NFC_Quick_Check is Maybe, or one whose NFD starts with either.
     */
    private static function nfcMayCompose(int $cp): bool
    {
        $continues = static fn (int $c): bool => IntlChar::getCombiningClass($c) !== 0 || IntlChar::getIntPropertyValue($c, IntlChar::PROPERTY_NFC_QUICK_CHECK) === self::NFC_QUICK_CHECK_MAYBE;
        if ($continues($cp)) {
            return true;
        }
        $char       = IntlChar::chr($cp);
        $decomposed = Normalizer::getRawDecomposition($char, Normalizer::FORM_D) === null ? null : Normalizer::normalize($char, Normalizer::FORM_D);

        return \is_string($decomposed) && $continues(mb_ord($decomposed, 'UTF-8'));
    }

    public function testEveryCodePointEndingWithNonStartersIsCountedAndAddsNoMoreThanTheShortestRunAssumes(): void
    {
        $missed          = [];
        $missedRepeated  = [];
        $longestTrailing = 0;
        $longestAll      = 0;
        foreach (self::nonStarterEdges() as $cp => [$allNonStarters, $count]) {
            $char   = ($allNonStarters ? 'a' : '') . IntlChar::chr($cp);
            $over   = $char . str_repeat("\u{0301}", CombiningRun::MAX_RUN + 1 - $count);
            $atMost = $char . str_repeat("\u{0301}", CombiningRun::MAX_RUN - $count);
            if (CombiningRun::allows($over) || !CombiningRun::allows($atMost)) {
                $missed[] = \sprintf('U+%04X', $cp);
            }
            if (!$allNonStarters) {
                $longestTrailing = max($longestTrailing, $count);

                continue;
            }
            $longestAll = max($longestAll, $count);
            $repeated   = IntlChar::chr($cp);
            $over       = 'a' . str_repeat($repeated, intdiv(CombiningRun::MAX_RUN, $count) + 1);
            $atMost     = 'a' . str_repeat($repeated, intdiv(CombiningRun::MAX_RUN, $count));
            if (CombiningRun::allows($over) || !CombiningRun::allows($atMost)) {
                $missedRepeated[] = \sprintf('U+%04X', $cp);
            }
        }

        $this->assertSame([], $missed, 'Followed by U+0301 up to the limit');
        $this->assertSame([], $missedRepeated, 'Repeated up to the limit');
        // CombiningRun::SHORTEST_RUN (14) rests on these two numbers.
        $this->assertSame([3, 2], [$longestTrailing, $longestAll], 'Longest trailing run and longest all-non-starter form');
    }

    public function testEveryCodePointThatNfcMayComposeContinuesARun(): void
    {
        $missed = [];
        for ($cp = 0; $cp <= 0x10FFFF; ++$cp) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF || !self::nfcMayCompose($cp)) {
                continue;
            }
            $char = IntlChar::chr($cp);
            // Taking turns with a vowel sign that composes keeps every run of non-starters at 2 or fewer,
            // so only the run of code points that NFC composes can fail these.
            $over   = str_repeat("\u{16126}" . $char, intdiv(CombiningRun::MAX_RUN, 2) + 1);
            $atMost = str_repeat("\u{16126}" . $char, intdiv(CombiningRun::MAX_RUN, 2));
            if (CombiningRun::allows($over) || !CombiningRun::allows($atMost)) {
                $missed[] = \sprintf('U+%04X', $cp);
            }
        }

        $this->assertSame([], $missed);
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

        // CombiningRun counts a code point with a starter by its trailing non-starters alone.
        $this->assertSame([], $leading);
    }
}
