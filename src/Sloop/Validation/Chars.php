<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Character sets accepted by StringRule::chars().
 *
 * Every case adds characters to the allowed set; chars() accepts a value made
 * only of characters in the union of the cases given. No case narrows another
 * (Uppercase alone allows A-Z; it is not a modifier of Alpha).
 */
enum Chars: string
{
    /**
     * PCRE character class contents keyed by case value.
     *
     * The kana and kanji sets use the Script property (`sc=`), not the
     * Script_Extensions that a bare `\p{Han}` means in PCRE2 10.40 and later:
     * the extensions also cover punctuation shared by the scripts (、。「」〜),
     * which belongs to ZenkakuSymbols. The sound marks, the prolonged sound
     * marks, and the middle dots that separate the parts of a katakana name
     * have no script of their own, so they are listed explicitly; the
     * full-width middle dot is in ZenkakuSymbols as well.
     *
     * Characters that would otherwise be read as character-class syntax are
     * escaped: an unescaped `.` at the start of a class opens a POSIX collating
     * element, and the class does not compile when a second `.` closes it
     * before the `]` (`[..]` and `[.,!?:;&.]` fail, `[.]` and `[.,]` compile).
     *
     * @var array<string, string>
     */
    private const array PATTERNS = [
        'alpha'           => 'a-zA-Z',
        'uppercase'       => 'A-Z',
        'lowercase'       => 'a-z',
        'numeric'         => '0-9',
        'spaces'          => ' ',
        'newlines'        => '\r\n',
        'tabs'            => '\t',
        'dots'            => '\.',
        'commas'          => ',',
        'punctuation'     => '\.,!?:;&',
        'dashes'          => '_\-',
        'slashes'         => '\/\\\\',
        'brackets'        => '()',
        'at'              => '@',
        'letter'          => '\p{L}',
        'hiragana'        => '\p{sc=Hiragana}\x{3099}-\x{309C}\x{30FC}',
        'katakana'        => '\p{sc=Katakana}\x{3099}-\x{309C}\x{30FB}\x{30FC}\x{FF65}\x{FF70}\x{FF9E}\x{FF9F}',
        'kanji'           => '\p{sc=Han}',
        'zenkaku_symbols' => '\x{3000}-\x{303F}\x{30A0}\x{30FB}\x{FF01}-\x{FF0F}\x{FF1A}-\x{FF20}\x{FF3B}-\x{FF40}\x{FF5B}-\x{FF60}\x{FFE0}-\x{FFE6}',
        'emoji'           => '\p{Extended_Pictographic}\x{200D}\x{FE0F}\x{20E3}\x{1F3FB}-\x{1F3FF}\x{1F1E6}-\x{1F1FF}\x{E0020}-\x{E007F}',
        'hex'             => '0-9a-fA-F',
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

    /** Katakana and half-width katakana, with their (semi-)voiced sound marks and prolonged sound marks, and the middle dots `・` `･` that separate the parts of a name. */
    case Katakana = 'katakana';

    /** Han ideographs (kanji), including `々` and `〇`; not the shared punctuation `、。「」`. */
    case Kanji = 'kanji';

    /** CJK symbols and punctuation (U+3000-U+303F, including the ideographic space), the middle dot `・` and `゠`, and full-width ASCII symbols and currency signs. */
    case ZenkakuSymbols = 'zenkaku_symbols';

    /** Emoji: pictographs and the joiners, variation selector, skin tones, regional indicators, keycap, and tags that build emoji sequences. */
    case Emoji = 'emoji';

    /** Hexadecimal digits 0-9, a-f, A-F. */
    case Hex = 'hex';

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
