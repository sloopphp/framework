<?php

declare(strict_types=1);

namespace Sloop\Validation;

use BackedEnum;
use Closure;

/**
 * Entry point of a field declaration: pick the type, then chain the rules.
 *
 *     'name'  => Rule::string(Sanitize::Trim)->required()->maxLength(50),
 *     'age'   => Rule::int()->between(0, 150),
 *     'price' => Rule::decimal(10, 2)->min(0),
 *
 * The factory decides the type of the validated value (Rule::int() yields an
 * int for "42", Rule::string() keeps "42" as a string). Sanitizers are applied
 * in the given order to string input before anything is checked; besides the
 * Sanitize cases, any first-class callable taking and returning a string can
 * be given (`mb_strtolower(...)`). Factories with type arguments take them
 * first and the sanitizers after them.
 */
final class Rule
{
    /**
     * Not instantiable; use the static factories.
     */
    private function __construct()
    {
    }

    /**
     * A field whose validated value is a string.
     *
     * @param  Sanitize|(Closure(string): string) ...$sanitizers Applied in order before validation
     * @return StringRule
     * @throws \InvalidArgumentException          When a sanitizer that deletes characters inside the value follows Sanitize::StripTags
     */
    public static function string(Sanitize|Closure ...$sanitizers): StringRule
    {
        return new StringRule(array_values($sanitizers));
    }

    /**
     * A field whose validated value is an int.
     *
     * @param  Sanitize|(Closure(string): string) ...$sanitizers Applied in order before validation
     * @return IntRule
     * @throws \InvalidArgumentException          When a sanitizer that deletes characters inside the value follows Sanitize::StripTags
     */
    public static function int(Sanitize|Closure ...$sanitizers): IntRule
    {
        return new IntRule(array_values($sanitizers));
    }

    /**
     * A field whose validated value is a float.
     *
     * @param  Sanitize|(Closure(string): string) ...$sanitizers Applied in order before validation
     * @return FloatRule
     * @throws \InvalidArgumentException          When a sanitizer that deletes characters inside the value follows Sanitize::StripTags
     */
    public static function float(Sanitize|Closure ...$sanitizers): FloatRule
    {
        return new FloatRule(array_values($sanitizers));
    }

    /**
     * A field whose validated value is a bool.
     *
     * @param  Sanitize|(Closure(string): string) ...$sanitizers Applied in order before validation
     * @return BoolRule
     * @throws \InvalidArgumentException          When a sanitizer that deletes characters inside the value follows Sanitize::StripTags
     */
    public static function bool(Sanitize|Closure ...$sanitizers): BoolRule
    {
        return new BoolRule(array_values($sanitizers));
    }

    /**
     * A field whose validated value is an exact decimal, limited like SQL's DECIMAL(precision, scale).
     *
     * @param  int                                $precision     Maximum number of digits in all
     * @param  int                                $scale         Maximum number of digits after the point
     * @param  Sanitize|(Closure(string): string) ...$sanitizers Applied in order before validation
     * @return DecimalRule
     * @throws \InvalidArgumentException          When $precision is below 1, $scale is outside 0..$precision, or a sanitizer that deletes characters inside the value follows Sanitize::StripTags
     */
    public static function decimal(int $precision, int $scale, Sanitize|Closure ...$sanitizers): DecimalRule
    {
        return new DecimalRule($precision, $scale, array_values($sanitizers));
    }

    /**
     * A field whose validated value is a case of a backed enum.
     *
     * @template E of BackedEnum
     * @param  class-string<E>                    $enum          Backed enum class
     * @param  Sanitize|(Closure(string): string) ...$sanitizers Applied in order before validation
     * @return EnumRule<E>
     * @throws \InvalidArgumentException          When $enum is not a backed enum, or a sanitizer that deletes characters inside the value follows Sanitize::StripTags
     */
    public static function enum(string $enum, Sanitize|Closure ...$sanitizers): EnumRule
    {
        return new EnumRule($enum, array_values($sanitizers));
    }
}
