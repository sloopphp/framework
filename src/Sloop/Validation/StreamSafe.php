<?php

declare(strict_types=1);

namespace Sloop\Validation;

use IntlChar;
use Normalizer;

/**
 * Tells whether a string is in the Stream-Safe Text Format of Unicode (UAX #15, UAX15-D3).
 *
 * A string is stream-safe when its NFKD form has no run of more than 30
 * non-starters (code points whose canonical combining class is not 0).
 * ICU's normalization takes time quadratic in the length of such a run when
 * its marks need reordering, so StringRule checks this before it normalizes.
 * The check itself takes time linear in the length of the string.
 *
 * @internal Used by StringRule.
 */
final class StreamSafe
{
    /**
     * Longest run of non-starters a stream-safe string may have.
     *
     * @var int
     */
    public const int MAX_NON_STARTERS = 30;

    /**
     * Fewest code points of ALL_NON_STARTERS in a row that a run longer than MAX_NON_STARTERS needs.
     *
     * In NFKD a code point with a starter ends with at most 3 non-starters, and
     * one without holds at most 2 (both measured over every code point with ICU
     * 76.1). A run starts with at most one code point of the first kind, so a run
     * of 31 needs 14 of the second: 3 + 2 × 14 = 31.
     *
     * @var int
     */
    private const int SHORTEST_RUN = 14;

    /**
     * Character class contents: the code points whose NFKD form starts or ends with a non-starter.
     *
     * Generated over every code point with ICU 76.1 (Unicode 16.0). A code point
     * that a later Unicode version adds is not here, and its non-starters are not
     * counted.
     *
     * @var string
     */
    private const string NON_STARTER_EDGE =
        '\x{A8}\x{AF}\x{B4}\x{B8}\x{C0}-\x{C5}\x{C7}-\x{CF}\x{D1}-\x{D6}\x{D9}-\x{DD}'
        . '\x{E0}-\x{E5}\x{E7}-\x{EF}\x{F1}-\x{F6}\x{F9}-\x{FD}\x{FF}-\x{10F}\x{112}-\x{125}\x{128}-\x{130}\x{134}-\x{137}'
        . '\x{139}-\x{13E}\x{143}-\x{148}\x{14C}-\x{151}\x{154}-\x{165}\x{168}-\x{17E}\x{1A0}-\x{1A1}\x{1AF}-\x{1B0}\x{1C4}-\x{1C6}'
        . '\x{1CD}-\x{1DC}\x{1DE}-\x{1E3}\x{1E6}-\x{1F0}\x{1F4}-\x{1F5}\x{1F8}-\x{21B}\x{21E}-\x{21F}\x{226}-\x{233}\x{2D8}-\x{2DD}'
        . '\x{300}-\x{34E}\x{350}-\x{36F}\x{37A}\x{384}-\x{386}\x{388}-\x{38A}\x{38C}\x{38E}-\x{390}\x{3AA}-\x{3B0}'
        . '\x{3CA}-\x{3CE}\x{3D3}-\x{3D4}\x{400}-\x{401}\x{403}\x{407}\x{40C}-\x{40E}\x{419}\x{439}'
        . '\x{450}-\x{451}\x{453}\x{457}\x{45C}-\x{45E}\x{476}-\x{477}\x{483}-\x{487}\x{4C1}-\x{4C2}\x{4D0}-\x{4D3}'
        . '\x{4D6}-\x{4D7}\x{4DA}-\x{4DF}\x{4E2}-\x{4E7}\x{4EA}-\x{4F5}\x{4F8}-\x{4F9}\x{591}-\x{5BD}\x{5BF}\x{5C1}-\x{5C2}'
        . '\x{5C4}-\x{5C5}\x{5C7}\x{610}-\x{61A}\x{622}-\x{626}\x{64B}-\x{65F}\x{670}\x{6C0}\x{6C2}'
        . '\x{6D3}\x{6D6}-\x{6DC}\x{6DF}-\x{6E4}\x{6E7}-\x{6E8}\x{6EA}-\x{6ED}\x{711}\x{730}-\x{74A}\x{7EB}-\x{7F3}'
        . '\x{7FD}\x{816}-\x{819}\x{81B}-\x{823}\x{825}-\x{827}\x{829}-\x{82D}\x{859}-\x{85B}\x{897}-\x{89F}\x{8CA}-\x{8E1}'
        . '\x{8E3}-\x{8FF}\x{929}\x{931}\x{934}\x{93C}\x{94D}\x{951}-\x{954}\x{958}-\x{95F}'
        . '\x{9BC}\x{9CD}\x{9DC}-\x{9DD}\x{9DF}\x{9FE}\x{A33}\x{A36}\x{A3C}'
        . '\x{A4D}\x{A59}-\x{A5B}\x{A5E}\x{ABC}\x{ACD}\x{B3C}\x{B4D}\x{B5C}-\x{B5D}'
        . '\x{BCD}\x{C3C}\x{C48}\x{C4D}\x{C55}-\x{C56}\x{CBC}\x{CCD}\x{D3B}-\x{D3C}'
        . '\x{D4D}\x{DCA}\x{DDA}\x{DDD}\x{E38}-\x{E3A}\x{E48}-\x{E4B}\x{EB8}-\x{EBA}\x{EC8}-\x{ECB}'
        . '\x{F18}-\x{F19}\x{F35}\x{F37}\x{F39}\x{F71}-\x{F7D}\x{F80}-\x{F84}\x{F86}-\x{F87}\x{FC6}'
        . '\x{1037}\x{1039}-\x{103A}\x{108D}\x{135D}-\x{135F}\x{1714}-\x{1715}\x{1734}\x{17D2}\x{17DD}'
        . '\x{18A9}\x{1939}-\x{193B}\x{1A17}-\x{1A18}\x{1A60}\x{1A75}-\x{1A7C}\x{1A7F}\x{1AB0}-\x{1ABD}\x{1ABF}-\x{1ACE}'
        . '\x{1B34}\x{1B44}\x{1B6B}-\x{1B73}\x{1BAA}-\x{1BAB}\x{1BE6}\x{1BF2}-\x{1BF3}\x{1C37}\x{1CD0}-\x{1CD2}'
        . '\x{1CD4}-\x{1CE0}\x{1CE2}-\x{1CE8}\x{1CED}\x{1CF4}\x{1CF8}-\x{1CF9}\x{1DC0}-\x{1E99}\x{1E9B}\x{1EA0}-\x{1EF9}'
        . '\x{1F00}-\x{1F15}\x{1F18}-\x{1F1D}\x{1F20}-\x{1F45}\x{1F48}-\x{1F4D}\x{1F50}-\x{1F57}\x{1F59}\x{1F5B}\x{1F5D}'
        . '\x{1F5F}-\x{1F7D}\x{1F80}-\x{1FB4}\x{1FB6}-\x{1FBD}\x{1FBF}-\x{1FC4}\x{1FC6}-\x{1FD3}\x{1FD6}-\x{1FDB}\x{1FDD}-\x{1FEE}\x{1FF2}-\x{1FF4}'
        . '\x{1FF6}-\x{1FFE}\x{2017}\x{203E}\x{20D0}-\x{20DC}\x{20E1}\x{20E5}-\x{20F0}\x{212B}\x{219A}-\x{219B}'
        . '\x{21AE}\x{21CD}-\x{21CF}\x{2204}\x{2209}\x{220C}\x{2224}\x{2226}\x{2241}'
        . '\x{2244}\x{2247}\x{2249}\x{2260}\x{2262}\x{226D}-\x{2271}\x{2274}-\x{2275}\x{2278}-\x{2279}'
        . '\x{2280}-\x{2281}\x{2284}-\x{2285}\x{2288}-\x{2289}\x{22AC}-\x{22AF}\x{22E0}-\x{22E3}\x{22EA}-\x{22ED}\x{2ADC}\x{2CEF}-\x{2CF1}'
        . '\x{2D7F}\x{2DE0}-\x{2DFF}\x{302A}-\x{302F}\x{304C}\x{304E}\x{3050}\x{3052}\x{3054}'
        . '\x{3056}\x{3058}\x{305A}\x{305C}\x{305E}\x{3060}\x{3062}\x{3065}'
        . '\x{3067}\x{3069}\x{3070}-\x{3071}\x{3073}-\x{3074}\x{3076}-\x{3077}\x{3079}-\x{307A}\x{307C}-\x{307D}\x{3094}'
        . '\x{3099}-\x{309C}\x{309E}\x{30AC}\x{30AE}\x{30B0}\x{30B2}\x{30B4}\x{30B6}'
        . '\x{30B8}\x{30BA}\x{30BC}\x{30BE}\x{30C0}\x{30C2}\x{30C5}\x{30C7}'
        . '\x{30C9}\x{30D0}-\x{30D1}\x{30D3}-\x{30D4}\x{30D6}-\x{30D7}\x{30D9}-\x{30DA}\x{30DC}-\x{30DD}\x{30F4}\x{30F7}-\x{30FA}'
        . '\x{30FE}\x{3304}\x{3307}\x{3310}\x{331E}\x{3321}\x{3332}\x{333B}'
        . '\x{3340}\x{334B}\x{334E}\x{A66F}\x{A674}-\x{A67D}\x{A69E}-\x{A69F}\x{A6F0}-\x{A6F1}\x{A806}'
        . '\x{A82C}\x{A8C4}\x{A8E0}-\x{A8F1}\x{A92B}-\x{A92D}\x{A953}\x{A9B3}\x{A9C0}\x{AAB0}'
        . '\x{AAB2}-\x{AAB4}\x{AAB7}-\x{AAB8}\x{AABE}-\x{AABF}\x{AAC1}\x{AAF6}\x{ABED}\x{FB1D}-\x{FB1F}\x{FB2A}-\x{FB36}'
        . '\x{FB38}-\x{FB3C}\x{FB3E}\x{FB40}-\x{FB41}\x{FB43}-\x{FB44}\x{FB46}-\x{FB4E}\x{FBA4}-\x{FBA5}\x{FBB0}-\x{FBB1}\x{FC5B}-\x{FC63}'
        . '\x{FC90}\x{FCD9}\x{FCF2}-\x{FCF4}\x{FD3C}-\x{FD3D}\x{FE20}-\x{FE2F}\x{FE49}-\x{FE4C}\x{FE70}-\x{FE72}\x{FE74}'
        . '\x{FE76}-\x{FE7F}\x{FE81}-\x{FE8C}\x{FEF5}-\x{FEFA}\x{FF9E}-\x{FF9F}\x{FFE3}\x{101FD}\x{102E0}\x{10376}-\x{1037A}'
        . '\x{105C9}\x{105E4}\x{10A0D}\x{10A0F}\x{10A38}-\x{10A3A}\x{10A3F}\x{10AE5}-\x{10AE6}\x{10D24}-\x{10D27}'
        . '\x{10D69}-\x{10D6D}\x{10EAB}-\x{10EAC}\x{10EFD}-\x{10EFF}\x{10F46}-\x{10F50}\x{10F82}-\x{10F85}\x{11046}\x{11070}\x{1107F}'
        . '\x{1109A}\x{1109C}\x{110AB}\x{110B9}-\x{110BA}\x{11100}-\x{11102}\x{11133}-\x{11134}\x{11173}\x{111C0}'
        . '\x{111CA}\x{11235}-\x{11236}\x{112E9}-\x{112EA}\x{1133B}-\x{1133C}\x{1134D}\x{11366}-\x{1136C}\x{11370}-\x{11374}\x{113CE}-\x{113D0}'
        . '\x{11442}\x{11446}\x{1145E}\x{114C2}-\x{114C3}\x{115BF}-\x{115C0}\x{1163F}\x{116B6}-\x{116B7}\x{1172B}'
        . '\x{11839}-\x{1183A}\x{1193D}-\x{1193E}\x{11943}\x{119E0}\x{11A34}\x{11A47}\x{11A99}\x{11C3F}'
        . '\x{11D42}\x{11D44}-\x{11D45}\x{11D97}\x{11F41}-\x{11F42}\x{1612F}\x{16AF0}-\x{16AF4}\x{16B30}-\x{16B36}\x{16FF0}-\x{16FF1}'
        . '\x{1BC9E}\x{1D15E}-\x{1D169}\x{1D16D}-\x{1D172}\x{1D17B}-\x{1D182}\x{1D185}-\x{1D18B}\x{1D1AA}-\x{1D1AD}\x{1D1BB}-\x{1D1C0}\x{1D242}-\x{1D244}'
        . '\x{1E000}-\x{1E006}\x{1E008}-\x{1E018}\x{1E01B}-\x{1E021}\x{1E023}-\x{1E024}\x{1E026}-\x{1E02A}\x{1E08F}\x{1E130}-\x{1E136}\x{1E2AE}'
        . '\x{1E2EC}-\x{1E2EF}\x{1E4EC}-\x{1E4EF}\x{1E5EE}-\x{1E5EF}\x{1E8D0}-\x{1E8D6}\x{1E944}-\x{1E94A}\x{1F213}';

    /**
     * Character class contents: the code points whose NFKD form is all non-starters.
     *
     * Generated the same way as NON_STARTER_EDGE, which holds all of them.
     *
     * @var string
     */
    private const string ALL_NON_STARTERS =
        '\x{300}-\x{34E}\x{350}-\x{36F}\x{483}-\x{487}\x{591}-\x{5BD}\x{5BF}\x{5C1}-\x{5C2}\x{5C4}-\x{5C5}\x{5C7}'
        . '\x{610}-\x{61A}\x{64B}-\x{65F}\x{670}\x{6D6}-\x{6DC}\x{6DF}-\x{6E4}\x{6E7}-\x{6E8}\x{6EA}-\x{6ED}\x{711}'
        . '\x{730}-\x{74A}\x{7EB}-\x{7F3}\x{7FD}\x{816}-\x{819}\x{81B}-\x{823}\x{825}-\x{827}\x{829}-\x{82D}\x{859}-\x{85B}'
        . '\x{897}-\x{89F}\x{8CA}-\x{8E1}\x{8E3}-\x{8FF}\x{93C}\x{94D}\x{951}-\x{954}\x{9BC}\x{9CD}'
        . '\x{9FE}\x{A3C}\x{A4D}\x{ABC}\x{ACD}\x{B3C}\x{B4D}\x{BCD}'
        . '\x{C3C}\x{C4D}\x{C55}-\x{C56}\x{CBC}\x{CCD}\x{D3B}-\x{D3C}\x{D4D}\x{DCA}'
        . '\x{E38}-\x{E3A}\x{E48}-\x{E4B}\x{EB8}-\x{EBA}\x{EC8}-\x{ECB}\x{F18}-\x{F19}\x{F35}\x{F37}\x{F39}'
        . '\x{F71}-\x{F75}\x{F7A}-\x{F7D}\x{F80}-\x{F84}\x{F86}-\x{F87}\x{FC6}\x{1037}\x{1039}-\x{103A}\x{108D}'
        . '\x{135D}-\x{135F}\x{1714}-\x{1715}\x{1734}\x{17D2}\x{17DD}\x{18A9}\x{1939}-\x{193B}\x{1A17}-\x{1A18}'
        . '\x{1A60}\x{1A75}-\x{1A7C}\x{1A7F}\x{1AB0}-\x{1ABD}\x{1ABF}-\x{1ACE}\x{1B34}\x{1B44}\x{1B6B}-\x{1B73}'
        . '\x{1BAA}-\x{1BAB}\x{1BE6}\x{1BF2}-\x{1BF3}\x{1C37}\x{1CD0}-\x{1CD2}\x{1CD4}-\x{1CE0}\x{1CE2}-\x{1CE8}\x{1CED}'
        . '\x{1CF4}\x{1CF8}-\x{1CF9}\x{1DC0}-\x{1DFF}\x{20D0}-\x{20DC}\x{20E1}\x{20E5}-\x{20F0}\x{2CEF}-\x{2CF1}\x{2D7F}'
        . '\x{2DE0}-\x{2DFF}\x{302A}-\x{302F}\x{3099}-\x{309A}\x{A66F}\x{A674}-\x{A67D}\x{A69E}-\x{A69F}\x{A6F0}-\x{A6F1}\x{A806}'
        . '\x{A82C}\x{A8C4}\x{A8E0}-\x{A8F1}\x{A92B}-\x{A92D}\x{A953}\x{A9B3}\x{A9C0}\x{AAB0}'
        . '\x{AAB2}-\x{AAB4}\x{AAB7}-\x{AAB8}\x{AABE}-\x{AABF}\x{AAC1}\x{AAF6}\x{ABED}\x{FB1E}\x{FE20}-\x{FE2F}'
        . '\x{FF9E}-\x{FF9F}\x{101FD}\x{102E0}\x{10376}-\x{1037A}\x{10A0D}\x{10A0F}\x{10A38}-\x{10A3A}\x{10A3F}'
        . '\x{10AE5}-\x{10AE6}\x{10D24}-\x{10D27}\x{10D69}-\x{10D6D}\x{10EAB}-\x{10EAC}\x{10EFD}-\x{10EFF}\x{10F46}-\x{10F50}\x{10F82}-\x{10F85}\x{11046}'
        . '\x{11070}\x{1107F}\x{110B9}-\x{110BA}\x{11100}-\x{11102}\x{11133}-\x{11134}\x{11173}\x{111C0}\x{111CA}'
        . '\x{11235}-\x{11236}\x{112E9}-\x{112EA}\x{1133B}-\x{1133C}\x{1134D}\x{11366}-\x{1136C}\x{11370}-\x{11374}\x{113CE}-\x{113D0}\x{11442}'
        . '\x{11446}\x{1145E}\x{114C2}-\x{114C3}\x{115BF}-\x{115C0}\x{1163F}\x{116B6}-\x{116B7}\x{1172B}\x{11839}-\x{1183A}'
        . '\x{1193D}-\x{1193E}\x{11943}\x{119E0}\x{11A34}\x{11A47}\x{11A99}\x{11C3F}\x{11D42}'
        . '\x{11D44}-\x{11D45}\x{11D97}\x{11F41}-\x{11F42}\x{1612F}\x{16AF0}-\x{16AF4}\x{16B30}-\x{16B36}\x{16FF0}-\x{16FF1}\x{1BC9E}'
        . '\x{1D165}-\x{1D169}\x{1D16D}-\x{1D172}\x{1D17B}-\x{1D182}\x{1D185}-\x{1D18B}\x{1D1AA}-\x{1D1AD}\x{1D242}-\x{1D244}\x{1E000}-\x{1E006}\x{1E008}-\x{1E018}'
        . '\x{1E01B}-\x{1E021}\x{1E023}-\x{1E024}\x{1E026}-\x{1E02A}\x{1E08F}\x{1E130}-\x{1E136}\x{1E2AE}\x{1E2EC}-\x{1E2EF}\x{1E4EC}-\x{1E4EF}'
        . '\x{1E5EE}-\x{1E5EF}\x{1E8D0}-\x{1E8D6}\x{1E944}-\x{1E94A}';

    /**
     * How each code point of NON_STARTER_EDGE adds to a run, computed on first use.
     *
     * @var array<string, array{bool, int}>
     */
    private static array $edges = [];

    /**
     * Whether the string has no run of more than 30 non-starters in NFKD.
     *
     * @param  string $value Valid UTF-8 string
     * @return bool
     */
    public static function holds(string $value): bool
    {
        // Only a run of code points that are all non-starters, with at most one more before it, can be too long.
        $found = preg_match_all('/[' . self::NON_STARTER_EDGE . ']?[' . self::ALL_NON_STARTERS . ']{' . self::SHORTEST_RUN . ',}/u', $value, $matches);
        if ($found === false) {
            return false;
        }

        foreach ($matches[0] as $segment) {
            if (!self::segmentHolds($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a run of code points from NON_STARTER_EDGE stays within the limit.
     *
     * @param  string $segment Code points from NON_STARTER_EDGE only, all but the first from ALL_NON_STARTERS
     * @return bool
     */
    private static function segmentHolds(string $segment): bool
    {
        $run    = 0;
        $length = \strlen($segment);
        $offset = 0;
        while ($offset < $length) {
            $size = self::sequenceLength(\ord($segment[$offset]));
            [$allNonStarters, $count] = self::edge(substr($segment, $offset, $size));
            $offset                  += $size;

            // A code point with a starter ends any run before it and starts a new one with its trailing non-starters.
            $run = $allNonStarters ? $run + $count : $count;
            if ($run > self::MAX_NON_STARTERS) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a code point's NFKD form is all non-starters, and how many it adds to a run.
     *
     * @param  string           $char One code point of NON_STARTER_EDGE
     * @return array{bool, int} All non-starters, and their number; otherwise false and the number of trailing non-starters
     */
    private static function edge(string $char): array
    {
        if (isset(self::$edges[$char])) {
            return self::$edges[$char];
        }

        $decomposed = Normalizer::normalize($char, Normalizer::FORM_KD);
        $codePoints = mb_str_split(\is_string($decomposed) ? $decomposed : $char, 1, 'UTF-8');
        $trailing   = 0;
        for ($i = \count($codePoints) - 1; $i >= 0 && IntlChar::getCombiningClass($codePoints[$i]) !== 0; --$i) {
            ++$trailing;
        }
        $edge = [$trailing === \count($codePoints), $trailing];

        return self::$edges[$char] = $edge;
    }

    /**
     * Number of bytes of the UTF-8 sequence that starts with the given byte.
     *
     * Only code points of NON_STARTER_EDGE reach here, and the first of them is
     * U+00A8, so a sequence is never a single byte.
     *
     * @param  int $lead First byte of a sequence of two bytes or more in valid UTF-8
     * @return int
     */
    private static function sequenceLength(int $lead): int
    {
        return match (true) {
            $lead < 0xE0 => 2,
            $lead < 0xF0 => 3,
            default      => 4,
        };
    }
}
