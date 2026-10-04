<?php

declare(strict_types=1);

namespace Sloop\Validation;

use RuntimeException;

/**
 * Unit in which the length rules of StringRule count a string.
 *
 * The case value is passed to the error as the `unit` parameter, so a message
 * can choose its wording with `{unit, select, bytes {...} other {...}}`.
 */
enum LengthUnit: string
{
    /**
     * User-perceived characters (grapheme clusters): 👨‍👩‍👧 is 1.
     *
     * Not a bound on storage size: any number of combining marks or
     * joiner-linked characters after a base character count as 1.
     */
    case Graphemes = 'graphemes';

    /**
     * Unicode code points: 👨‍👩‍👧 is 5. A VARCHAR(n) column of MySQL and
     * MariaDB holds n code points.
     */
    case Codepoints = 'codepoints';

    /**
     * UTF-8 bytes: 👨‍👩‍👧 is 18.
     */
    case Bytes = 'bytes';

    /**
     * Length of a valid UTF-8 string in this unit.
     *
     * @param  string           $value Valid UTF-8 string
     * @return int
     * @throws RuntimeException When ICU cannot count the grapheme clusters
     */
    public function length(string $value): int
    {
        return match ($this) {
            self::Graphemes  => self::graphemes($value),
            self::Codepoints => mb_strlen($value, 'UTF-8'),
            self::Bytes      => \strlen($value),
        };
    }

    /**
     * Number of grapheme clusters in a valid UTF-8 string.
     *
     * @param  string           $value Valid UTF-8 string
     * @return int
     * @throws RuntimeException When ICU cannot count the grapheme clusters
     */
    private static function graphemes(string $value): int
    {
        $length = grapheme_strlen($value);
        if (!\is_int($length)) {
            throw new RuntimeException('Could not count the grapheme clusters of the value.');
        }

        return $length;
    }
}
