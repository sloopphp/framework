<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * A set of characters for StringRule::chars(), notChars() and minCharClasses().
 *
 * Chars implements it for the built-in sets; an application implements it,
 * usually with its own enum, for a set Chars does not have. An application
 * pattern is joined with the other sets into one PCRE character class, so it
 * must use only these forms, or the rule throws when it is declared:
 * a character other than `\ [ ] ^ - /`; `\` followed by an ASCII symbol
 * (`\-`, `\^`, `\/`); `\x{hex}`; `\p{name}` or `\P{name}`; and a range
 * `X-Y` between two characters. `\d`, `\x41` and other escapes throw. An
 * empty name throws as well, and so do two sets with the same name in
 * minCharClasses(), including a built-in name such as `numeric`. A single
 * `\x{...}` must be a Unicode scalar value; a range may span the surrogates,
 * which never occur in a validated value.
 *
 * PCRE2 10.47 and earlier (the system library or the one PHP bundles) can
 * mismatch a joined class that mixes code points up to U+00FF with ones at
 * U+8000 and above, such as Chars::Emoji next to a set ranging from `-` to
 * `あ`: `あ` is then missed. PCRE2 10.48 fixes it (#841); check PCRE_VERSION.
 */
interface CharSet
{
    /**
     * Contents of a PCRE character class (without the brackets), e.g. `\x{2460}-\x{2473}` or `\p{Han}`.
     *
     * @return string
     */
    public function pattern(): string;

    /**
     * Name reported in the `chars` parameter of a failed rule.
     *
     * @return string
     */
    public function name(): string;
}
