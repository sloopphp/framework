<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * A base-10 number held as its digits, never as a float.
 *
 * @internal Used by DecimalRule for parsing, formatting, and comparison.
 */
final readonly class DecimalLiteral
{
    /**
     * Create a literal from its parts.
     *
     * @param bool   $negative Whether the sign is minus (false for zero)
     * @param string $integer  Integer digits without leading zeros; '' for zero
     * @param string $fraction Fraction digits as written, trailing zeros kept
     */
    private function __construct(
        public bool $negative,
        public string $integer,
        public string $fraction,
    ) {
    }

    /**
     * Parse a string of the form `-?\d+(\.\d+)?`.
     *
     * @param  string    $value String to parse
     * @return self|null The literal, or null when the string is not in that form
     */
    public static function parse(string $value): ?self
    {
        if (preg_match('/\A(-?)(\d+)(?:\.(\d+))?\z/', $value, $matches) !== 1) {
            return null;
        }

        $integer  = ltrim($matches[2], '0');
        $fraction = $matches[3] ?? '';
        $isZero   = $integer === '' && trim($fraction, '0') === '';

        return new self($matches[1] === '-' && !$isZero, $integer, $fraction);
    }

    /**
     * The same number with the fraction padded with zeros to the given width.
     *
     * @param  int  $scale Number of fraction digits; at least the current count
     * @return self
     */
    public function padded(int $scale): self
    {
        return new self($this->negative, $this->integer, str_pad($this->fraction, $scale, '0'));
    }

    /**
     * The number as written, with a leading 0 for a zero integer part.
     *
     * @return string
     */
    public function format(): string
    {
        $number = ($this->negative ? '-' : '') . ($this->integer === '' ? '0' : $this->integer);

        return $this->fraction === '' ? $number : $number . '.' . $this->fraction;
    }

    /**
     * Compare with another literal by value.
     *
     * @param  self $other Literal to compare with
     * @return int  Negative, zero, or positive as this is less than, equal to, or greater than $other
     */
    public function compare(self $other): int
    {
        if ($this->negative !== $other->negative) {
            return $this->negative ? -1 : 1;
        }

        $scale     = max(\strlen($this->fraction), \strlen($other->fraction));
        $width     = max(\strlen($this->integer), \strlen($other->integer));
        $own       = str_pad($this->integer, $width, '0', \STR_PAD_LEFT) . str_pad($this->fraction, $scale, '0');
        $theirs    = str_pad($other->integer, $width, '0', \STR_PAD_LEFT) . str_pad($other->fraction, $scale, '0');
        $magnitude = strcmp($own, $theirs) <=> 0;

        return $this->negative ? -$magnitude : $magnitude;
    }
}
