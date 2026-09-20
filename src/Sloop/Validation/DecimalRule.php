<?php

declare(strict_types=1);

namespace Sloop\Validation;

use BcMath\Number;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * Rules for a field whose validated value is an exact base-10 number.
 *
 * The limits follow SQL's DECIMAL(precision, scale): at most $scale digits
 * after the point and at most $precision digits in all. A value beyond either
 * limit fails; nothing is rounded. The validated value is a string with
 * exactly $scale fraction digits ("19.9" and 19.9 both become "19.90"), or a
 * BcMath\Number when asNumber() is declared.
 *
 * Accepts a JSON int, a string of the form `-?\d+(\.\d+)?`, and a JSON float
 * only when $precision is 15 or less: a float holds 15 significant decimal
 * digits reliably, and a float whose shortest form has more fraction digits
 * than $scale fails instead of being rounded.
 *
 * @extends FieldRule<DecimalLiteral>
 */
final class DecimalRule extends FieldRule
{
    /**
     * Largest precision at which a JSON float is accepted.
     *
     * @var int
     */
    private const int FLOAT_SAFE_PRECISION = 15;

    /**
     * Whether the validated value is returned as a BcMath\Number.
     *
     * @var bool
     */
    private bool $asNumber = false;

    /**
     * Create the rule set.
     *
     * @param  int                                    $precision  Maximum number of digits in all
     * @param  int                                    $scale      Maximum number of digits after the point
     * @param  list<Sanitize|Closure(string): string> $sanitizers Sanitizers applied before validation, in order
     * @throws InvalidArgumentException               When $precision is below 1 or $scale is outside 0..$precision
     */
    public function __construct(
        private readonly int $precision,
        private readonly int $scale,
        array $sanitizers,
    ) {
        if ($precision < 1 || $scale < 0 || $scale > $precision) {
            throw new InvalidArgumentException(
                'decimal() needs precision >= 1 and 0 <= scale <= precision, got ' . $precision . ' and ' . $scale . '.',
            );
        }
        parent::__construct($sanitizers);
    }

    /**
     * Return the validated value as a BcMath\Number instead of a string.
     *
     * @return self
     * @throws LogicException When the bcmath extension is not loaded
     */
    public function asNumber(): self
    {
        if (!class_exists(Number::class)) {
            throw new LogicException('asNumber() needs the bcmath extension.');
        }

        $copy           = clone $this;
        $copy->asNumber = true;

        return $copy;
    }

    /**
     * Use this value when the field is empty.
     *
     * Written as an int or a decimal string regardless of asNumber(); it is
     * padded to $scale fraction digits and converted like a validated value.
     *
     * @param  int|string               $value Default value
     * @return self
     * @throws InvalidArgumentException When the value is not a decimal or does not fit $precision and $scale
     * @throws LogicException           When the field is required or a default has already been declared
     */
    public function default(int|string $value): self
    {
        $literal = DecimalLiteral::parse((string) $value);
        if ($literal === null || !$this->fits($literal)) {
            throw new InvalidArgumentException(
                'The default of decimal(' . $this->precision . ', ' . $this->scale . ') must be a decimal that fits it, got '
                . $value . '.',
            );
        }

        return $this->withDefault($literal->padded($this->scale));
    }

    /**
     * Fail when the value is less than given.
     *
     * @param  int|string               $min     Smallest accepted value; a string keeps fraction digits exact
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $min is not a decimal or the message template is malformed
     */
    public function min(int|string $min, ?string $message = null): self
    {
        $bound = self::bound($min);

        return $this->withCheck('min', ['min' => $min], static fn (DecimalLiteral $value): bool => $value->compare($bound) >= 0, $message);
    }

    /**
     * Fail when the value is greater than given.
     *
     * @param  int|string               $max     Largest accepted value; a string keeps fraction digits exact
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $max is not a decimal or the message template is malformed
     */
    public function max(int|string $max, ?string $message = null): self
    {
        $bound = self::bound($max);

        return $this->withCheck('max', ['max' => $max], static fn (DecimalLiteral $value): bool => $value->compare($bound) <= 0, $message);
    }

    /**
     * Fail unless the value is between the two bounds, both included.
     *
     * @param  int|string               $min     Smallest accepted value
     * @param  int|string               $max     Largest accepted value
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When a bound is not a decimal, $min is greater than $max, or the message template is malformed
     */
    public function between(int|string $min, int|string $max, ?string $message = null): self
    {
        $lower = self::bound($min);
        $upper = self::bound($max);
        if ($lower->compare($upper) > 0) {
            throw new InvalidArgumentException('between() needs min <= max, got ' . $min . ' and ' . $max . '.');
        }

        return $this->withCheck(
            'between',
            ['min' => $min, 'max' => $max],
            static fn (DecimalLiteral $value): bool => $value->compare($lower) >= 0 && $value->compare($upper) <= 0,
            $message,
        );
    }

    /**
     * Read a decimal within the declared precision and scale.
     *
     * @param  mixed                       $value Raw value
     * @return DecimalLiteral|TypeMismatch The value with exactly $scale fraction digits
     */
    protected function coerce(mixed $value): DecimalLiteral|TypeMismatch
    {
        $text = match (true) {
            \is_int($value)    => (string) $value,
            \is_string($value) => $value,
            \is_float($value)  => $this->floatText($value),
            default            => null,
        };
        $literal = $text === null ? null : DecimalLiteral::parse($text);

        if ($literal === null || !$this->fits($literal)) {
            return new TypeMismatch();
        }

        return $literal->padded($this->scale);
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'decimal';
    }

    /**
     * Parameters reported with the type failure.
     *
     * @return array<string, int>
     */
    protected function typeParams(): array
    {
        return ['precision' => $this->precision, 'scale' => $this->scale];
    }

    /**
     * The decimal string, or a BcMath\Number when asNumber() was declared.
     *
     * @param  DecimalLiteral $value Validated value with $scale fraction digits
     * @return string|Number
     */
    protected function output(mixed $value): string|Number
    {
        $text = $value->format();

        return $this->asNumber && is_numeric($text) ? new Number($text) : $text;
    }

    /**
     * The decimal string, so that same() compares by value.
     *
     * @param  DecimalLiteral $value Validated value with $scale fraction digits
     * @return string
     */
    protected function comparable(mixed $value): string
    {
        return $value->format();
    }

    /**
     * Whether the literal fits DECIMAL($precision, $scale) without rounding.
     *
     * @param  DecimalLiteral $literal Parsed value
     * @return bool
     */
    private function fits(DecimalLiteral $literal): bool
    {
        return \strlen($literal->fraction) <= $this->scale
            && \strlen($literal->integer) <= $this->precision - $this->scale;
    }

    /**
     * Digits of a JSON float, when they can be read without rounding.
     *
     * Formats with $scale fraction digits and keeps the result only if it
     * reads back as the same float: a float whose shortest form needs more
     * fraction digits than $scale does not survive that round trip. The check
     * does not depend on the serialize_precision ini setting.
     *
     * @param  float       $value JSON float
     * @return string|null The digits, or null when the float is not accepted
     */
    private function floatText(float $value): ?string
    {
        if ($this->precision > self::FLOAT_SAFE_PRECISION || !is_finite($value)) {
            return null;
        }

        $text = \sprintf('%.' . $this->scale . 'F', $value);

        return (float) $text === $value ? $text : null;
    }

    /**
     * Parse a declared bound.
     *
     * @param  int|string               $bound Bound as declared
     * @return DecimalLiteral
     * @throws InvalidArgumentException When the bound is not a decimal
     */
    private static function bound(int|string $bound): DecimalLiteral
    {
        return DecimalLiteral::parse((string) $bound)
            ?? throw new InvalidArgumentException('A decimal bound must be of the form -?\d+(\.\d+)?, got ' . $bound . '.');
    }
}
