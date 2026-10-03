<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Character sets used by StringRule::chars(), notChars() and minCharClasses().
 *
 * Every case adds characters to the set; chars() accepts a value made only of
 * characters in the union of the cases given. No case narrows another
 * (Uppercase alone allows A-Z; it is not a modifier of Alpha). Full-width and
 * half-width forms are separate cases, so a set can be widened by adding cases
 * but never needs narrowing.
 */
enum Chars: string
{
    /**
     * PCRE character class contents keyed by case value.
     *
     * The hiragana and kanji sets use the Script property (`sc=`), not the
     * Script_Extensions that a bare `\p{Han}` means in PCRE2 10.40 and later:
     * the extensions also cover punctuation shared by the scripts (、。「」〜),
     * which belongs to ZenkakuSymbols. The sound marks, the prolonged sound
     * marks, and the middle dots that separate the parts of a katakana name
     * have no script of their own, so they are listed explicitly; the
     * full-width middle dot is in ZenkakuSymbols as well.
     *
     * Katakana lists the ranges of the Katakana script (PCRE2 10.47) other
     * than the half-width forms U+FF66-FF9D: a character class has no way to
     * subtract those from `\p{sc=Katakana}`. HankakuKatakana is the whole
     * half-width block U+FF65-FF9F, its middle dot, prolonged sound mark and
     * sound marks included.
     *
     * Characters that would otherwise be read as character-class syntax are
     * escaped: an unescaped `.` at the start of a class opens a POSIX collating
     * element, and the class does not compile when a second `.` closes it
     * before the `]` (`[..]` and `[.,!?:;&.]` fail, `[.]` and `[.,]` compile).
     *
     * @var array<string, string>
     */
    private const array PATTERNS = [
        'alpha'            => 'a-zA-Z',
        'uppercase'        => 'A-Z',
        'lowercase'        => 'a-z',
        'numeric'          => '0-9',
        'spaces'           => ' ',
        'newlines'         => '\r\n',
        'tabs'             => '\t',
        'dots'             => '\.',
        'commas'           => ',',
        'punctuation'      => '\.,!?:;&',
        'dashes'           => '_\-',
        'slashes'          => '\/\\\\',
        'brackets'         => '()',
        'at'               => '@',
        'letter'           => '\p{L}',
        'hiragana'         => '\p{sc=Hiragana}\x{3099}-\x{309C}\x{30FC}',
        'katakana'         => '\x{30A1}-\x{30FA}\x{30FD}-\x{30FF}\x{31F0}-\x{31FF}\x{32D0}-\x{32FE}\x{3300}-\x{3357}\x{1AFF0}-\x{1AFF3}\x{1AFF5}-\x{1AFFB}\x{1AFFD}-\x{1AFFE}\x{1B000}\x{1B120}-\x{1B122}\x{1B155}\x{1B164}-\x{1B167}\x{3099}-\x{309C}\x{30FB}\x{30FC}',
        'hankaku_katakana' => '\x{FF65}-\x{FF9F}',
        'kanji'            => '\p{sc=Han}',
        'zenkaku_alpha'    => '\x{FF21}-\x{FF3A}\x{FF41}-\x{FF5A}',
        'zenkaku_numeric'  => '\x{FF10}-\x{FF19}',
        'zenkaku_space'    => '\x{3000}',
        'zenkaku_symbols'  => '\x{3001}-\x{303F}\x{30A0}\x{30FB}\x{FF01}-\x{FF0F}\x{FF1A}-\x{FF20}\x{FF3B}-\x{FF40}\x{FF5B}-\x{FF60}\x{FFE0}-\x{FFE6}',
        'emoji'            => '\p{Extended_Pictographic}\x{200D}\x{FE0F}\x{20E3}\x{1F3FB}-\x{1F3FF}\x{1F1E6}-\x{1F1FF}\x{E0020}-\x{E007F}',
        'hex'              => '0-9a-fA-F',
        'symbols'          => '\x{21}-\x{2F}\x{3A}-\x{40}\x{5B}-\x{60}\x{7B}-\x{7E}',
        'supplementary'    => '\x{10000}-\x{10FFFF}',
    ];

    /** Latin letters a-z and A-Z. */
    case Alpha = 'alpha';

    /** Latin capital letters A-Z. */
    case Uppercase = 'uppercase';

    /** Latin small letters a-z. */
    case Lowercase = 'lowercase';

    /** ASCII digits 0-9. */
    case Numeric = 'numeric';

    /** The ASCII space. */
    case Spaces = 'spaces';

    /** Carriage return and line feed. */
    case Newlines = 'newlines';

    /** The horizontal tab. */
    case Tabs = 'tabs';

    /** The full stop `.`. */
    case Dots = 'dots';

    /** The comma `,`. */
    case Commas = 'commas';

    /** `. , ! ? : ; &`. */
    case Punctuation = 'punctuation';

    /** Underscore and hyphen-minus `_ -`. */
    case Dashes = 'dashes';

    /** Slash and backslash. */
    case Slashes = 'slashes';

    /** Parentheses `( )`. */
    case Brackets = 'brackets';

    /** The at sign `@`. */
    case At = 'at';

    /** Letters of any script (Unicode category L), including kanji and kana. */
    case Letter = 'letter';

    /** Hiragana, the (semi-)voiced sound marks, and the prolonged sound mark `ー`. */
    case Hiragana = 'hiragana';

    /** Full-width katakana, the (semi-)voiced sound marks, the prolonged sound mark `ー`, and the middle dot `・` that separates the parts of a name; not half-width katakana. */
    case Katakana = 'katakana';

    /** Half-width katakana U+FF65-FF9F, with the half-width middle dot `･`, prolonged sound mark `ｰ` and sound marks `ﾞ` `ﾟ`. */
    case HankakuKatakana = 'hankaku_katakana';

    /** Han ideographs (kanji), including `々` and `〇`; not the shared punctuation `、。「」`. */
    case Kanji = 'kanji';

    /** Full-width Latin letters `Ａ-Ｚ` `ａ-ｚ`. */
    case ZenkakuAlpha = 'zenkaku_alpha';

    /** Full-width digits `０-９`. */
    case ZenkakuNumeric = 'zenkaku_numeric';

    /** The ideographic space U+3000. */
    case ZenkakuSpace = 'zenkaku_space';

    /** CJK symbols and punctuation (U+3001-U+303F; not the ideographic space, which is ZenkakuSpace), the middle dot `・` and `゠`, and full-width ASCII symbols and currency signs. */
    case ZenkakuSymbols = 'zenkaku_symbols';

    /** Emoji: pictographs and the joiners, variation selector, skin tones, regional indicators, keycap, and tags that build emoji sequences. */
    case Emoji = 'emoji';

    /** Hexadecimal digits 0-9, a-f, A-F. */
    case Hex = 'hex';

    /** All 32 ASCII symbols ``!"#$%&'()*+,-./:;<=>?@[\]^_`{|}~``; overlaps Punctuation, Dashes, Slashes, Brackets, At, Dots and Commas. */
    case Symbols = 'symbols';

    /** Supplementary characters U+10000-10FFFF: 4 bytes in UTF-8, so not storable in a MySQL utf8 (utf8mb3) column. Includes most emoji, but not those in the BMP such as `☀`. */
    case Supplementary = 'supplementary';

    /**
     * Contents of a PCRE character class (without the brackets) for this set.
     *
     * @return string
     */
    public function pattern(): string
    {
        return self::PATTERNS[$this->value];
    }
}
