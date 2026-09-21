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
     * @throws \InvalidArgumentException          When Sanitize::StripNewlines, StripTabs or StripControlChars follows Sanitize::StripTags
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
     * @throws \InvalidArgumentException          When Sanitize::StripNewlines, StripTabs or StripControlChars follows Sanitize::StripTags
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
     * @throws \InvalidArgumentException          When Sanitize::StripNewlines, StripTabs or StripControlChars follows Sanitize::StripTags
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
     * @throws \InvalidArgumentException          When Sanitize::StripNewlines, StripTabs or StripControlChars follows Sanitize::StripTags
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
     * @throws \InvalidArgumentException          When $precision is below 1, $scale is outside 0..$precision, or Sanitize::StripNewlines, StripTabs or StripControlChars follows Sanitize::StripTags
     */
    public static function decimal(int $precision, int $scale, Sanitize|Closure ...$sanitizers): DecimalRule
    {
        return new DecimalRule($precision, $scale, array_values($sanitizers));
    }

    /**
     * A field that must be an array, whatever it holds.
     *
     * The elements reach the caller untouched. Use list() to give them all one
     * type, or shape() to give each key its own.
     *
     * @param  ArraySanitize|(Closure(array<array-key, mixed>): array<array-key, mixed>) ...$sanitizers Applied in order once the value is an array
     * @return AnyArrayRule
     */
    public static function array(ArraySanitize|Closure ...$sanitizers): AnyArrayRule
    {
        return new AnyArrayRule(array_values($sanitizers));
    }

    /**
     * A field that must be an array whose elements all satisfy one rule.
     *
     * The validated value is renumbered from zero, so the keys of the input do
     * not reach the caller.
     *
     * @param  FieldRule<covariant mixed>                                                $element       Rules every element must satisfy
     * @param  ArraySanitize|(Closure(array<array-key, mixed>): array<array-key, mixed>) ...$sanitizers Applied in order once the value is an array
     * @return ListRule
     */
    public static function list(FieldRule $element, ArraySanitize|Closure ...$sanitizers): ListRule
    {
        return new ListRule($element, array_values($sanitizers));
    }

    /**
     * A field that must be an array whose declared keys each have their own rules.
     *
     * Keys of the input without a rule are dropped.
     *
     * @param  array<array-key, FieldRule<covariant mixed>>                              $fields        Rules of each key, named (a numeric key is refused)
     * @param  ArraySanitize|(Closure(array<array-key, mixed>): array<array-key, mixed>) ...$sanitizers Applied in order once the value is an array
     * @return ShapeRule
     * @throws \InvalidArgumentException                                                 When no key is declared, a key is not a name, or a comparison names a key that has no rule
     */
    public static function shape(array $fields, ArraySanitize|Closure ...$sanitizers): ShapeRule
    {
        return new ShapeRule($fields, array_values($sanitizers));
    }

    /**
     * A field whose validated value is a case of a backed enum.
     *
     * @template E of BackedEnum
     * @param  class-string<E>                    $enum          Backed enum class
     * @param  Sanitize|(Closure(string): string) ...$sanitizers Applied in order before validation
     * @return EnumRule<E>
     * @throws \InvalidArgumentException          When $enum is not a backed enum, or Sanitize::StripNewlines, StripTabs or StripControlChars follows Sanitize::StripTags
     */
    public static function enum(string $enum, Sanitize|Closure ...$sanitizers): EnumRule
    {
        return new EnumRule($enum, array_values($sanitizers));
    }
}
