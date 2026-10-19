<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Validation\Stub\Payment;
use Sloop\Tests\Unit\Validation\Stub\Priority;
use Sloop\Validation\Chars;
use Sloop\Validation\DecimalRule;
use Sloop\Validation\FieldRule;
use Sloop\Validation\FileRule;
use Sloop\Validation\IntRule;
use Sloop\Validation\ListRule;
use Sloop\Validation\Rule;
use Sloop\Validation\StringRule;
use Sloop\Validation\ValidationError;
use Sloop\Validation\ValidationResult;
use Sloop\Validation\Validator;
use TypeError;

final class ConditionalRuleTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    /**
     * @param array<string, FieldRule<covariant mixed>> $rules
     * @param array<string, mixed>                      $input
     */
    private static function validate(array $rules, array $input): ValidationResult
    {
        return new Validator($rules)->validate($input);
    }

    /**
     * @param  array<string, FieldRule<covariant mixed>> $rules
     * @param  array<string, mixed>                      $input
     * @return array<string, list<string>>
     */
    private static function failedRulesOf(array $rules, array $input): array
    {
        return array_map(
            static fn (array $errors): array => array_map(static fn (ValidationError $e): string => $e->rule, $errors),
            self::validate($rules, $input)->errors(),
        );
    }

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    private static function whenOnAConditionallyRequiredField(): array
    {
        return [
            'c' => Rule::string(),
            'b' => Rule::string()->requiredWith('c')->default('x'),
            'a' => Rule::string()->when('b', 'x', static fn (StringRule $r): StringRule => $r->in(['ok'])),
        ];
    }

    // -------------------------------------------------------
    // requiredWith
    // -------------------------------------------------------

    public function testRequiredWithFailsWhenTheOtherFieldIsFilledAndThisOneIsEmpty(): void
    {
        $errors = self::validate(
            ['area' => Rule::string(), 'number' => Rule::string()->requiredWith('area')],
            ['area' => '03', 'number' => ''],
        )->errors();

        self::assertErrorsSame(
            ['number' => [new ValidationError('requiredWith', ['other' => 'area'], 'The number field is required when area is present.')]],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, list<string>>}>
     */
    public static function pairInputs(): iterable
    {
        yield 'both empty' => [['area' => '', 'number' => ''], []];
        yield 'only area' => [['area' => '03', 'number' => ''], ['number' => ['requiredWith']]];
        yield 'only number' => [['area' => '', 'number' => '1234'], ['area' => ['requiredWith']]];
        yield 'both filled' => [['area' => '03', 'number' => '1234'], []];
    }

    /**
     * @param array<string, mixed>        $input
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('pairInputs')]
    public function testRequiredWithOnBothFieldsMakesAPair(array $input, array $expected): void
    {
        $rules = [
            'area'   => Rule::string()->requiredWith('number'),
            'number' => Rule::string()->requiredWith('area'),
        ];

        $this->assertSame($expected, self::failedRulesOf($rules, $input));
    }

    public function testRequiredWithLeavesTheFieldOptionalWhenTheOtherIsEmpty(): void
    {
        $result = self::validate(
            ['area' => Rule::string(), 'number' => Rule::string()->requiredWith('area')],
            ['area' => null],
        );

        $this->assertSame(['area' => null, 'number' => null], $result->values());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function filledOthers(): iterable
    {
        yield 'only the second' => [['b' => 'x'], 'b'];
        yield 'both' => [['a' => 'x', 'b' => 'y'], 'a'];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('filledOthers')]
    public function testRequiredWithReportsTheFirstDeclaredFieldThatIsFilled(array $input, string $other): void
    {
        $rules = [
            'a' => Rule::string(),
            'b' => Rule::string(),
            'c' => Rule::string()->requiredWith(['a', 'b']),
        ];

        $this->assertSame(['other' => $other], (self::validate($rules, $input)->errors()['c'][0] ?? null)?->params);
    }

    public function testRequiredWithIgnoresAnOtherFieldThatFailedItsOwnRules(): void
    {
        $rules = ['count' => Rule::int(), 'unit' => Rule::string()->requiredWith('count')];

        $this->assertSame(['count' => ['int']], self::failedRulesOf($rules, ['count' => 'many']));
    }

    public function testRequiredWithTreatsAnEmptyOtherFieldWithADefaultAsFilled(): void
    {
        $rules = ['area' => Rule::string()->default('03'), 'number' => Rule::string()->requiredWith('area')];

        $this->assertSame(['number' => ['requiredWith']], self::failedRulesOf($rules, []));
    }

    public function testRequiredWithNamesTheOtherFieldByItsLabel(): void
    {
        $error = self::validate(
            ['area' => Rule::string()->label('Area code'), 'number' => Rule::string()->requiredWith('area')],
            ['area' => '03'],
        )->errors()['number'][0] ?? null;

        $this->assertSame('The number field is required when Area code is present.', $error?->message);
    }

    public function testRequiredWithUsesItsOwnMessage(): void
    {
        $error = self::validate(
            ['area' => Rule::string(), 'number' => Rule::string()->requiredWith('area', message: '{label} needs {other}')],
            ['area' => '03'],
        )->errors()['number'][0] ?? null;

        $this->assertSame('number needs area', $error?->message);
    }

    public function testAFieldWithADefaultFailsWhenTheConditionHolds(): void
    {
        $rules = ['area' => Rule::string(), 'number' => Rule::string()->requiredWith('area')->default('-')];

        $this->assertSame(['number' => ['requiredWith']], self::failedRulesOf($rules, ['area' => '03']));
    }

    public function testAFieldWithADefaultTakesItWhenTheConditionDoesNotHold(): void
    {
        $rules = ['area' => Rule::string(), 'number' => Rule::string()->requiredWith('area')->default('-')];

        $this->assertSame(['area' => null, 'number' => '-'], self::validate($rules, [])->values());
    }

    /**
     * @return iterable<string, array{Closure(): mixed, class-string<\Throwable>}>
     */
    public static function badRequiredWithArguments(): iterable
    {
        yield 'empty list' => [static fn () => Rule::string()->requiredWith([]), InvalidArgumentException::class];
        yield 'after required()' => [static fn () => Rule::string()->required()->requiredWith('a'), LogicException::class];
        yield 'before required()' => [static fn () => Rule::string()->requiredWith('a')->required(), LogicException::class];
        yield 'malformed message' => [static fn () => Rule::string()->requiredWith('a', message: "'{label}'"), InvalidArgumentException::class];
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function namesThatAreNotStrings(): iterable
    {
        yield 'int' => [['a', 1], 'requiredWith() needs strings, got int at position 1.'];
        yield 'object' => [[new \stdClass()], 'requiredWith() needs strings, got stdClass at position 0.'];
        yield 'null' => [['a', 'b', null], 'requiredWith() needs strings, got null at position 2.'];
        yield 'after a key' => [['k' => 'a', 'm' => 1], 'requiredWith() needs strings, got int at position 1.'];
    }

    #[DataProvider('namesThatAreNotStrings')]
    public function testRequiredWithRefusesANameThatIsNotAString(mixed $fields, string $message): void
    {
        $thrown = $this->assertThrows(
            TypeError::class,
            // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
            static fn () => Rule::string()->requiredWith($fields),
        );
        self::assertSame($message, $thrown->getMessage());
    }

    public function testRequiredWithIgnoresTheKeysOfTheList(): void
    {
        // @phpstan-ignore argument.type (the point of the test is what happens to the keys)
        $rules = ['a' => Rule::string(), 'b' => Rule::string()->requiredWith(['k' => 'a'])];

        $this->assertSame(['b' => ['requiredWith']], self::failedRulesOf($rules, ['a' => 'x']));
    }

    /**
     * @param Closure(): mixed         $declare
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('badRequiredWithArguments')]
    public function testRequiredWithRefusesADeclarationThatCannotTakeEffect(Closure $declare, string $expected): void
    {
        $this->assertThrows($expected, $declare);
    }

    public function testRequiredWithNeedsAField(): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::string()->requiredWith([]));

        $this->assertSame('requiredWith() needs at least one field.', $thrown->getMessage());
    }

    // -------------------------------------------------------
    // requiredIf
    // -------------------------------------------------------

    public function testRequiredIfFailsWhenTheOtherFieldHasTheValue(): void
    {
        $errors = self::validate(
            ['type' => Rule::string(), 'company' => Rule::string()->requiredIf('type', 'corp')],
            ['type' => 'corp'],
        )->errors();

        self::assertErrorsSame(
            ['company' => [new ValidationError('requiredIf', ['other' => 'type', 'value' => 'corp'], 'The company field is required when type is corp.')]],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function requiredIfCases(): iterable
    {
        yield 'other value' => ['person', []];
        yield 'one of the listed values' => ['school', ['requiredIf']];
        yield 'other empty' => ['', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('requiredIfCases')]
    public function testRequiredIfHoldsForAnyListedValue(mixed $type, array $expected): void
    {
        $rules = ['type' => Rule::string(), 'company' => Rule::string()->requiredIf('type', ['corp', 'school'])];

        $this->assertSame($expected, self::failedRulesOf($rules, ['type' => $type])['company'] ?? []);
    }

    public function testRequiredIfComparesTheValidatedValue(): void
    {
        $rules = ['count' => Rule::int(), 'note' => Rule::string()->requiredIf('count', 3)];

        $this->assertSame(['note' => ['requiredIf']], self::failedRulesOf($rules, ['count' => '3']));
    }

    public function testRequiredIfReportsAnEnumByItsBackingValue(): void
    {
        $error = self::validate(
            ['pay' => Rule::enum(Payment::class), 'iban' => Rule::string()->requiredIf('pay', Payment::Transfer)],
            ['pay' => 'transfer'],
        )->errors()['iban'][0] ?? null;

        $this->assertSame(['other' => 'pay', 'value' => 'transfer'], $error?->params);
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>, string, int|float}>
     */
    public static function numericConditions(): iterable
    {
        yield 'int' => [Rule::int(), '3', 3];
        yield 'float' => [Rule::float(), '1.5', 1.5];
    }

    /**
     * @param FieldRule<covariant mixed> $other
     */
    #[DataProvider('numericConditions')]
    public function testRequiredIfReportsANumberAsItIs(FieldRule $other, string $input, int|float $value): void
    {
        $error = self::validate(
            ['n' => $other, 'note' => Rule::string()->requiredIf('n', $value)],
            ['n' => $input],
        )->errors()['note'][0] ?? null;

        $this->assertSame(['other' => 'n', 'value' => $value], $error?->params);
    }

    public function testRequiredIfReportsABoolAsText(): void
    {
        $error = self::validate(
            ['is_link' => Rule::bool(), 'url' => Rule::string()->requiredIf('is_link', true)],
            ['is_link' => '1'],
        )->errors()['url'][0] ?? null;

        $this->assertSame(['other' => 'is_link', 'value' => 'true'], $error?->params);
    }

    public function testRequiredIfIgnoresAnOtherFieldThatFailedItsOwnRules(): void
    {
        $rules = ['type' => Rule::string()->in(['corp']), 'company' => Rule::string()->requiredIf('type', 'person')];

        $this->assertSame(['type' => ['in']], self::failedRulesOf($rules, ['type' => 'person']));
    }

    public function testRequiredIfComparesAgainstTheDefaultOfAnEmptyOtherField(): void
    {
        $rules = ['type' => Rule::string()->default('corp'), 'company' => Rule::string()->requiredIf('type', 'corp')];

        $this->assertSame(['company' => ['requiredIf']], self::failedRulesOf($rules, []));
    }

    public function testRequiredIfReportsTheFirstConditionThatHolds(): void
    {
        $rules = [
            'a' => Rule::string(),
            'b' => Rule::string(),
            'c' => Rule::string()->requiredWith('a')->requiredIf('b', 'x'),
        ];

        $errors = self::validate($rules, ['a' => 'y', 'b' => 'x'])->errors()['c'] ?? [];

        $this->assertSame([['requiredWith', ['other' => 'a']]], array_map(static fn (ValidationError $e): array => [$e->rule, $e->params], $errors));
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function badConditionValues(): iterable
    {
        $type = static fn (string $method, string $got): string => $method . '() compares with a string, int, float, bool or backed enum case, got ' . $got . '.';

        yield 'requiredIf, null' => [static fn () => Rule::string()->requiredIf('a', null), $type('requiredIf', 'null')];
        yield 'requiredIf, empty list' => [static fn () => Rule::string()->requiredIf('a', []), 'requiredIf() needs at least one value.'];
        yield 'requiredIf, nested list' => [static fn () => Rule::string()->requiredIf('a', [['x']]), $type('requiredIf', 'array')];
        yield 'requiredIf, object' => [static fn () => Rule::string()->requiredIf('a', new \stdClass()), $type('requiredIf', 'stdClass')];
        yield 'requiredIf, null in the list' => [static fn () => Rule::string()->requiredIf('a', ['x', null]), $type('requiredIf', 'null')];
        yield 'when, empty list' => [static fn () => Rule::string()->when('a', [], static fn (StringRule $r): StringRule => $r->url()), 'when() needs at least one value.'];
        yield 'when, null' => [static fn () => Rule::string()->when('a', null, static fn (StringRule $r): StringRule => $r->url()), $type('when', 'null')];
    }

    /**
     * @param Closure(): mixed $declare
     */
    #[DataProvider('badConditionValues')]
    public function testAConditionRefusesAValueNoFieldCanHold(Closure $declare, string $message): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, $declare);

        $this->assertSame($message, $thrown->getMessage());
    }

    public function testRequiredIfCannotFollowRequired(): void
    {
        $this->assertThrows(LogicException::class, static fn () => Rule::string()->required()->requiredIf('a', 'x'));
    }

    public function testRequiredCannotFollowRequiredIf(): void
    {
        $this->assertThrows(LogicException::class, static fn () => Rule::string()->requiredIf('a', 'x')->required());
    }

    public function testRulesAddedByWhenDoNotApplyToTheDefault(): void
    {
        $rules = [
            'kind'   => Rule::string(),
            'amount' => Rule::int()->default(0)->when('kind', 'refund', static fn (IntRule $r): IntRule => $r->min(1)),
        ];

        $this->assertSame([], self::failedRulesOf($rules, ['kind' => 'refund']));
        $this->assertSame(['amount' => ['min']], self::failedRulesOf($rules, ['kind' => 'refund', 'amount' => '0']));
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>, string, string}>
     */
    public static function otherFieldsFailingOnlyAgainstOthers(): iterable
    {
        yield 'when' => [Rule::string()->when('k', 'x', static fn (StringRule $r): StringRule => $r->in(['ok'])), 'y', 'in'];
        yield 'same' => [Rule::string()->same('k'), 'y', 'same'];
        yield 'different' => [Rule::string()->different('k'), 'x', 'different'];
    }

    /**
     * @param FieldRule<covariant mixed> $other
     */
    #[DataProvider('otherFieldsFailingOnlyAgainstOthers')]
    public function testRequiredWithDoesNotSeeFailuresAddedAgainstOtherFields(FieldRule $other, string $value, string $rule): void
    {
        // o fails only against k, which a required condition does not count, so o is filled.
        $rules = ['k' => Rule::string(), 'o' => $other, 'c' => Rule::string()->requiredWith('o')];

        $this->assertSame(['o' => [$rule], 'c' => ['requiredWith']], self::failedRulesOf($rules, ['k' => 'x', 'o' => $value]));
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>, mixed}>
     */
    public static function mismatchedTypes(): iterable
    {
        yield 'string for an int field' => [Rule::int(), '3'];
        yield 'int for a string field' => [Rule::string(), 3];
        yield 'int for a float field' => [Rule::float(), 3];
        yield 'string for a bool field' => [Rule::bool(), 'true'];
        yield 'backing value for an enum field' => [Rule::enum(Payment::class), 'cash'];
        yield 'case of another enum' => [Rule::enum(Payment::class), Priority::Low];
        yield 'one mismatch in a list' => [Rule::string(), ['a', 1]];
        yield 'decimal field' => [Rule::decimal(5, 2), '1.00'];
        yield 'date field' => [Rule::date(), '2026-01-01'];
        yield 'list field' => [Rule::list(Rule::string()), 'a'];
    }

    /**
     * @param FieldRule<covariant mixed> $other
     */
    #[DataProvider('mismatchedTypes')]
    public function testARequiredIfValueThatTheOtherFieldCannotHoldIsRefused(FieldRule $other, mixed $value): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => new Validator(['other' => $other, 'self' => Rule::string()->requiredIf('other', $value)]),
        );
    }

    /**
     * @param FieldRule<covariant mixed> $other
     */
    #[DataProvider('mismatchedTypes')]
    public function testAWhenValueThatTheOtherFieldCannotHoldIsRefused(FieldRule $other, mixed $value): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => new Validator(['other' => $other, 'self' => Rule::string()->when('other', $value, static fn (StringRule $r): StringRule => $r->email())]),
        );
    }

    public function testTheRefusalNamesTheFieldsAndTheValue(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => new Validator(['count' => Rule::int(), 'note' => Rule::string()->requiredIf('count', '3')]),
        );

        $this->assertSame(
            'Field "note" compares "count" with a string in requiredIf(), which that field never holds. '
            . 'Compare a string, int, float or bool field with a value of its type, or an enum field with its case.',
            $thrown->getMessage(),
        );
    }

    // -------------------------------------------------------
    // when
    // -------------------------------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function whenCases(): iterable
    {
        yield 'condition holds, value is not a URL' => [['is_link' => '1', 'source' => 'paper'], ['url']];
        yield 'condition holds, value is a URL' => [['is_link' => '1', 'source' => 'https://example.com'], []];
        yield 'condition does not hold' => [['is_link' => '0', 'source' => 'paper'], []];
        yield 'own value empty' => [['is_link' => '1', 'source' => ''], []];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $expected
     */
    #[DataProvider('whenCases')]
    public function testWhenAppliesItsRulesOnlyWhileTheConditionHolds(array $input, array $expected): void
    {
        $rules = [
            'is_link' => Rule::bool(),
            'source'  => Rule::string()->when('is_link', true, static fn (StringRule $r): StringRule => $r->url()),
        ];

        $this->assertSame($expected, self::failedRulesOf($rules, $input)['source'] ?? []);
    }

    public function testRulesAddedByWhenRunAfterTheFieldsOwnRules(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'code' => Rule::string()
                ->when('kind', 'x', static fn (StringRule $r): StringRule => $r->in(['ok']))
                ->regex('/^\d+$/'),
        ];

        $this->assertSame(['code' => ['regex', 'in']], self::failedRulesOf($rules, ['kind' => 'x', 'code' => 'ng']));
    }

    public function testWhenCarriesRulesOnTheSizeOfTheValue(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'code' => Rule::string()->when('kind', 'x', static fn (StringRule $r): StringRule => $r->minLength(3)),
        ];

        $this->assertSame(['code' => ['minLength']], self::failedRulesOf($rules, ['kind' => 'x', 'code' => 'ab']));
    }

    public function testWhenReportsEveryRuleOnTheSizeItAddsThatFails(): void
    {
        $rules = [
            'kind'  => Rule::string(),
            'items' => Rule::list(Rule::string())->when('kind', 'x', static fn (ListRule $r): ListRule => $r->minCount(3)->exactCount(4)),
        ];

        $this->assertSame(['items' => ['minCount', 'exactCount']], self::failedRulesOf($rules, ['kind' => 'x', 'items' => ['a', 'b']]));
    }

    /**
     * @return iterable<string, array{Closure(StringRule): StringRule}>
     */
    public static function rulesOnTheCharacters(): iterable
    {
        yield 'chars' => [static fn (StringRule $r): StringRule => $r->chars([Chars::Alpha])];
        yield 'notChars' => [static fn (StringRule $r): StringRule => $r->notChars([Chars::Numeric])];
        yield 'minCharClasses' => [static fn (StringRule $r): StringRule => $r->minCharClasses(2, [Chars::Alpha, Chars::Numeric])];
        yield 'notContains' => [static fn (StringRule $r): StringRule => $r->notContains(['bad'])];
    }

    /**
     * @param Closure(StringRule): StringRule $then
     */
    #[DataProvider('rulesOnTheCharacters')]
    public function testWhenChecksTheCombiningRunOfItsRulesOnTheCharactersOnlyWhileTheConditionHolds(Closure $then): void
    {
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()->when('kind', 'x', $then),
        ];
        $long  = 'a' . str_repeat("\u{0301}", 31);

        $this->assertSame(['name' => ['combiningRun']], self::failedRulesOf($rules, ['kind' => 'x', 'name' => $long]));
        $this->assertSame([], self::failedRulesOf($rules, ['kind' => 'y', 'name' => $long]));
    }

    public function testACombiningRunFromWhenStopsOnlyTheRulesOfThatWhen(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()
                ->regex('/^b/')
                ->when('kind', 'x', static fn (StringRule $r): StringRule => $r->chars([Chars::Alpha])->in(['a'])),
        ];

        $this->assertSame(
            ['name' => ['regex', 'combiningRun']],
            self::failedRulesOf($rules, ['kind' => 'x', 'name' => 'a' . str_repeat("\u{0301}", 31)]),
        );
    }

    public function testEveryWhenThatHoldsChecksTheCombiningRunOfItsOwnRules(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()
                ->when('kind', 'x', static fn (StringRule $r): StringRule => $r->chars([Chars::Alpha]))
                ->when('kind', 'x', static fn (StringRule $r): StringRule => $r->notContains(['bad'])),
        ];

        $this->assertSame(
            ['name' => ['combiningRun', 'combiningRun']],
            self::failedRulesOf($rules, ['kind' => 'x', 'name' => 'a' . str_repeat("\u{0301}", 31)]),
        );
    }

    public function testARuleTakenFromTheClosureOfAnotherWhenStillBringsTheCombiningRun(): void
    {
        $before = Rule::string();
        $kept   = new class ($before) {
            public function __construct(public StringRule $rule)
            {
            }
        };
        Rule::string()->chars([Chars::Alpha])->when('kind', 'x', static function (StringRule $r) use ($kept): StringRule {
            $kept->rule = $r;

            return $r->url();
        });
        $taken = $kept->rule;
        self::assertNotSame($before, $taken);

        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()->when('kind', 'x', static fn (StringRule $r): StringRule => $taken->chars([Chars::Alpha])),
        ];

        $this->assertSame(
            ['name' => ['combiningRun']],
            self::failedRulesOf($rules, ['kind' => 'x', 'name' => 'a' . str_repeat("\u{0301}", 31)]),
        );
    }

    public function testAFieldThatChecksTheCombiningRunItselfFailsOnItWhetherOrNotTheConditionHolds(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()
                ->chars([Chars::Alpha])
                ->when('kind', 'x', static fn (StringRule $r): StringRule => $r->notContains(['bad'])->in(['a'])),
        ];
        $long  = 'a' . str_repeat("\u{0301}", 31);

        $this->assertSame(['name' => ['combiningRun']], self::failedRulesOf($rules, ['kind' => 'x', 'name' => $long]));
        $this->assertSame(['name' => ['combiningRun']], self::failedRulesOf($rules, ['kind' => 'y', 'name' => $long]));
    }

    public function testWhenAcceptsAListOfValues(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'code' => Rule::string()->when('kind', ['x', 'y'], static fn (StringRule $r): StringRule => $r->in(['ok'])),
        ];

        $this->assertSame(['code' => ['in']], self::failedRulesOf($rules, ['kind' => 'y', 'code' => 'ng']));
    }

    public function testEveryWhenThatHoldsAddsItsRules(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'code' => Rule::string()
                ->when('kind', 'x', static fn (StringRule $r): StringRule => $r->in(['ok']))
                ->when('kind', 'x', static fn (StringRule $r): StringRule => $r->regex('/^\d+$/')),
        ];

        $this->assertSame(['code' => ['in', 'regex']], self::failedRulesOf($rules, ['kind' => 'x', 'code' => 'ng']));
    }

    public function testRulesOnTheElementsAddedByWhenWaitForTheElementsToPass(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'ids'  => Rule::list(Rule::int())->when('kind', 'x', static fn ($r) => $r->distinct()),
        ];

        $this->assertSame(['ids.0' => ['int']], self::failedRulesOf($rules, ['kind' => 'x', 'ids' => ['a', 1, 1]]));
    }

    public function testRulesOnTheElementsAddedByWhenRunOnceTheElementsPass(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'ids'  => Rule::list(Rule::int())->when('kind', 'x', static fn ($r) => $r->distinct()),
        ];

        $this->assertSame(['ids' => ['distinct']], self::failedRulesOf($rules, ['kind' => 'x', 'ids' => [1, 1]]));
    }

    public function testWhenCanNameItsOwnField(): void
    {
        $rules = ['code' => Rule::string()->when('code', 'bad', static fn (StringRule $r): StringRule => $r->in(['good']))];

        $this->assertSame(['code' => ['in']], self::failedRulesOf($rules, ['code' => 'bad']));
    }

    public function testWhenDoesNotSeeFailuresAddedByAnotherWhen(): void
    {
        $rules = [
            'a' => Rule::string()->when('b', 'x', static fn (StringRule $r): StringRule => $r->in(['ok'])),
            'b' => Rule::string()->when('a', 'y', static fn (StringRule $r): StringRule => $r->in(['ok'])),
        ];

        $this->assertSame(['a' => ['in'], 'b' => ['in']], self::failedRulesOf($rules, ['a' => 'y', 'b' => 'x']));
    }

    public function testWhenSkipsAFieldThatFailedARequiredCondition(): void
    {
        $this->assertSame(['b' => ['requiredWith']], self::failedRulesOf(self::whenOnAConditionallyRequiredField(), ['c' => 'z', 'a' => 'ng']));
    }

    public function testWhenSeesTheDefaultOfAFieldWhoseConditionDoesNotHold(): void
    {
        $this->assertSame(['a' => ['in']], self::failedRulesOf(self::whenOnAConditionallyRequiredField(), ['a' => 'ng']));
    }

    /**
     * @return iterable<string, array{Closure(StringRule): mixed, string}>
     */
    public static function forbiddenInsideWhen(): iterable
    {
        $changed  = 'when() can only add rules on the value; the closure changed something else about the field.';
        $returned = 'when() needs the closure to return the rule it was given, with rules added.';
        // Each closure also adds a rule, so the refusal is not the one for adding none.
        yield 'required' => [static fn (StringRule $r): mixed => $r->url()->required(), $changed];
        yield 'default' => [static fn (StringRule $r): mixed => $r->url()->default('x'), $changed];
        yield 'label' => [static fn (StringRule $r): mixed => $r->url()->label('X'), $changed];
        yield 'message' => [static fn (StringRule $r): mixed => $r->url()->message('m'), $changed];
        yield 'same' => [static fn (StringRule $r): mixed => $r->url()->same('a'), $changed];
        yield 'different' => [static fn (StringRule $r): mixed => $r->url()->different('a'), $changed];
        yield 'notContainsField' => [static fn (StringRule $r): mixed => $r->url()->notContainsField('a'), $changed];
        yield 'requiredWith' => [static fn (StringRule $r): mixed => $r->url()->requiredWith('a'), $changed];
        yield 'requiredIf' => [static fn (StringRule $r): mixed => $r->url()->requiredIf('a', 'x'), $changed];
        yield 'when' => [static fn (StringRule $r): mixed => $r->url()->when('a', 'x', static fn (StringRule $s): StringRule => $s->url()), $changed];
        yield 'no return' => [static function (StringRule $r): mixed {
            $r->url();
            return null;
        }, $returned];
        yield 'another rule' => [static fn (StringRule $r): mixed => Rule::int()->min(1), $returned];
    }

    /**
     * @param Closure(StringRule): mixed $then
     */
    #[DataProvider('forbiddenInsideWhen')]
    public function testWhenRefusesAClosureThatDoesMoreThanAddRules(Closure $then, string $message): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::string()->when('a', 'x', $then));
        self::assertSame($message, $thrown->getMessage());
    }

    public function testWhenLeavesTheRuleItWasCalledOnUnchanged(): void
    {
        $base = Rule::string();
        $base->when('a', 'x', static fn (StringRule $r): StringRule => $r->url());

        $this->assertSame([], self::failedRulesOf(['f' => $base, 'a' => Rule::string()], ['f' => 'not a url', 'a' => 'x']));
    }

    public function testRequiredWithLeavesTheRuleItWasCalledOnUnchanged(): void
    {
        $base = Rule::string();
        $base->requiredWith('a');

        $this->assertSame([], self::failedRulesOf(['f' => $base, 'a' => Rule::string()], ['a' => 'x']));
    }

    public function testWhenRefusesAClosureThatAddsNoRules(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Rule::string()->when('a', 'x', static fn (StringRule $r): StringRule => $r),
        );
        self::assertSame('when() needs the closure to add at least one rule.', $thrown->getMessage());
    }

    public function testWhenRefusesAClosureThatChangesASettingOfTheType(): void
    {
        // max() adds a rule, so the refusal is not the one for adding none.
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Rule::decimal(5, 2)->when('a', 'x', static fn (DecimalRule $r): DecimalRule => $r->asNumber()->max('9')),
        );
        self::assertSame('when() can only add rules on the value; the closure changed something else about the field.', $thrown->getMessage());
    }

    public function testRulesAddedByWhenDoNotRunWhileTheConditionDoesNotHold(): void
    {
        // mimeTypes() reads the stream; it must not be read for a condition that does not hold.
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(10);
        $file->expects(self::never())->method('getStream');
        $rules = [
            'kind' => Rule::string(),
            'f'    => Rule::file()->when('kind', 'img', static fn (FileRule $r): FileRule => $r->mimeTypes(['image/png'])),
        ];

        $this->assertSame([], self::failedRulesOf($rules, ['kind' => 'doc', 'f' => $file]));
    }

    public function testRulesAddedByWhenRunWhileTheConditionHolds(): void
    {
        // The pair of the test above: the same rule does read the stream once the condition holds.
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(10);
        $file->expects(self::once())->method('getStream')->willThrowException(new RuntimeException('stream read'));
        $rules = [
            'kind' => Rule::string(),
            'f'    => Rule::file()->when('kind', 'img', static fn (FileRule $r): FileRule => $r->mimeTypes(['image/png'])),
        ];

        $thrown = $this->assertThrows(RuntimeException::class, static fn () => self::failedRulesOf($rules, ['kind' => 'img', 'f' => $file]));
        self::assertSame('stream read', $thrown->getMessage());
    }

    // -------------------------------------------------------
    // declaring against the other fields
    // -------------------------------------------------------

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>}>
     */
    public static function rulesNamingMissing(): iterable
    {
        yield 'requiredWith' => [Rule::string()->requiredWith('missing')];
        yield 'requiredWith in a list' => [Rule::string()->requiredWith(['a', 'missing'])];
        yield 'requiredIf' => [Rule::string()->requiredIf('missing', 'x')];
        yield 'when' => [Rule::string()->when('missing', 'x', static fn (StringRule $r): StringRule => $r->url())];
    }

    /**
     * @param FieldRule<covariant mixed> $rule
     */
    #[DataProvider('rulesNamingMissing')]
    public function testAConditionOnAFieldWithoutRulesIsRefused(FieldRule $rule): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator(['a' => Rule::string(), 'self' => $rule]));
    }

    public function testTheRefusalOfAMissingFieldNamesIt(): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator(['self' => Rule::string()->requiredWith('missing')]));

        $this->assertSame('Field "self" names "missing" in requiredWith(), which has no rule.', $thrown->getMessage());
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>}>
     */
    public static function rulesNamingThemselves(): iterable
    {
        yield 'requiredWith' => [Rule::string()->requiredWith('self')];
        yield 'requiredWith in a list' => [Rule::string()->requiredWith(['a', 'self'])];
        yield 'requiredIf' => [Rule::string()->requiredIf('self', 'x')];
    }

    /**
     * @param FieldRule<covariant mixed> $rule
     */
    #[DataProvider('rulesNamingThemselves')]
    public function testARequiredConditionOnItsOwnFieldIsRefused(FieldRule $rule): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator(['a' => Rule::string(), 'self' => $rule]));

        $this->assertSame('Field "self" is required depending on itself, which never changes whether it is required.', $thrown->getMessage());
    }

    public function testDuplicatesInTheListsAreAccepted(): void
    {
        $rules = [
            'a' => Rule::string(),
            'b' => Rule::string()->requiredWith(['a', 'a']),
            'c' => Rule::string()->requiredIf('a', ['x', 'x']),
        ];

        $this->assertSame(['b' => ['requiredWith'], 'c' => ['requiredIf']], self::failedRulesOf($rules, ['a' => 'x']));
    }

    public function testACycleWithTwoDefaultsIsRefused(): void
    {
        $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator([
            'a' => Rule::string()->requiredWith('b')->default('x'),
            'b' => Rule::string()->requiredWith('a')->default('y'),
            'c' => Rule::string()->requiredWith('a'),
        ]));

        $this->assertSame(
            'Fields "a", "b" are required depending on each other, and "a", "b" have a default, so which one is required would depend on the order they are checked.',
            $thrown->getMessage(),
        );
    }

    public function testALongerCycleWithTwoDefaultsIsRefused(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator([
            'a' => Rule::string()->requiredWith('b')->default('x'),
            'b' => Rule::string()->requiredIf('c', 'z'),
            'c' => Rule::string()->requiredWith('a')->default('y'),
        ]));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function declarationOrders(): iterable
    {
        yield 'a first' => [['a', 'b']];
        yield 'b first' => [['b', 'a']];
    }

    /**
     * @param list<string> $order
     */
    #[DataProvider('declarationOrders')]
    public function testACycleWithOneDefaultGivesTheSameAnswerInAnyOrder(array $order): void
    {
        $all   = ['a' => Rule::string()->requiredWith('b')->default('x'), 'b' => Rule::string()->requiredWith('a')];
        $rules = [];
        foreach ($order as $field) {
            $rules[$field] = $all[$field];
        }

        $result = self::validate($rules, []);

        $this->assertSame(['b' => ['requiredWith']], self::failedRulesOf($rules, []));
        $this->assertSame(['a' => 'x'], $result->values());
    }

    /**
     * @param list<string> $order
     */
    #[DataProvider('declarationOrders')]
    public function testAFieldIsJudgedAfterTheFieldItNamesInAnyOrder(array $order): void
    {
        // b fails its own condition, so a sees b as failed and is not judged.
        $all   = [
            'a' => Rule::string()->requiredWith('b'),
            'b' => Rule::string()->requiredIf('c', 'x')->default('-'),
        ];
        $rules = ['c' => Rule::string()];
        foreach ($order as $field) {
            $rules[$field] = $all[$field];
        }

        $this->assertSame(['b' => ['requiredIf']], self::failedRulesOf($rules, ['c' => 'x']));
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>}>
     */
    public static function rulesNamingBLater(): iterable
    {
        yield 'second field of a requiredWith()' => [Rule::string()->requiredWith(['e', 'b'])];
        yield 'first of two conditions' => [Rule::string()->requiredWith('b')->requiredWith('e')];
    }

    /**
     * @param FieldRule<covariant mixed> $c
     */
    #[DataProvider('rulesNamingBLater')]
    public function testEveryFieldAConditionNamesIsJudgedFirst(FieldRule $c): void
    {
        // c is declared before b and names it; b fails its own condition, so c
        // has to see b as failed rather than as holding its default.
        $rules = [
            'd' => Rule::string(),
            'e' => Rule::string(),
            'c' => $c,
            'b' => Rule::string()->requiredIf('d', 'x')->default('-'),
        ];

        $this->assertSame(['b' => ['requiredIf']], self::failedRulesOf($rules, ['d' => 'x']));
    }

    /**
     * @param list<string> $order
     */
    #[DataProvider('declarationOrders')]
    public function testTheFieldWithADefaultIsJudgedFirstInACycle(array $order): void
    {
        // a fails because y is filled; b names a, which failed, so b is not judged.
        $all   = ['a' => Rule::string()->default('v')->requiredWith(['b', 'y']), 'b' => Rule::string()->requiredWith('a')];
        $rules = ['y' => Rule::string()];
        foreach ($order as $field) {
            $rules[$field] = $all[$field];
        }

        $this->assertSame(['a' => ['requiredWith']], self::failedRulesOf($rules, ['y' => 'z']));
    }

    public function testAFieldNamingACycleIsJudgedAfterTheCycle(): void
    {
        // a reaches b and c, and b and c reach each other: a is declared first
        // but has to see b fail its own condition.
        $rules = [
            'd' => Rule::string(),
            'a' => Rule::string()->requiredWith('b'),
            'b' => Rule::string()->requiredIf('d', 'x')->requiredWith('c')->default('-'),
            'c' => Rule::string()->requiredWith('b'),
        ];

        $this->assertSame(['b' => ['requiredIf']], self::failedRulesOf($rules, ['d' => 'x']));
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>}>
     */
    public static function fieldsWithADefaultNamingACycle(): iterable
    {
        yield 'one field' => [Rule::string()->default('-')->requiredWith('a')];
        yield 'after a field outside the cycle' => [Rule::string()->default('-')->requiredWith(['z', 'a'])];
        yield 'in a later condition' => [Rule::string()->default('-')->requiredWith('a')->requiredWith('z')];
    }

    /**
     * @param FieldRule<covariant mixed> $c
     */
    #[DataProvider('fieldsWithADefaultNamingACycle')]
    public function testAFieldWithADefaultNamingACycleWaitsForTheFieldWithADefaultInIt(FieldRule $c): void
    {
        // c and a both have a default, and c is declared first. c has to see a
        // fail its own condition rather than read a as filled by its default.
        $rules = [
            'c' => $c,
            'a' => Rule::string()->default('-')->requiredWith('b'),
            'b' => Rule::string()->requiredWith('a'),
            'z' => Rule::string(),
        ];

        $this->assertSame(['a' => ['requiredWith']], self::failedRulesOf($rules, ['b' => 'x']));
    }

    public function testAFieldNamedAfterARepeatedNameIsStillJudgedFirst(): void
    {
        // a names e twice before c; c still has to be judged before a.
        $rules = [
            'd' => Rule::string(),
            'e' => Rule::string(),
            'a' => Rule::string()->requiredWith(['e', 'e', 'c']),
            'c' => Rule::string()->requiredIf('d', 'x')->default('-'),
        ];

        $this->assertSame(['c' => ['requiredIf']], self::failedRulesOf($rules, ['d' => 'x']));
    }

    public function testAChainOfFieldsWithADefaultIsAccepted(): void
    {
        // Not a cycle, so two defaults are fine; b's default counts as filled.
        $rules = [
            'a' => Rule::string()->requiredWith('b')->default('x'),
            'b' => Rule::string()->default('y'),
        ];

        $this->assertSame(['a' => ['requiredWith']], self::failedRulesOf($rules, []));
    }

    public function testSameIsSkippedWhenTheOtherFieldFailedARequiredCondition(): void
    {
        $rules = [
            'c' => Rule::string(),
            'b' => Rule::string()->requiredWith('c'),
            'a' => Rule::string()->same('b'),
        ];

        $this->assertSame(['b' => ['requiredWith']], self::failedRulesOf($rules, ['c' => 'z', 'a' => 'q']));
    }

    public function testConditionsWorkBetweenKeysOfAShape(): void
    {
        $rule = Rule::list(Rule::shape([
            'is_link' => Rule::bool(),
            'source'  => Rule::string()
                ->requiredIf('is_link', true)
                ->when('is_link', true, static fn (StringRule $r): StringRule => $r->url()),
        ]));

        $this->assertSame(
            ['v.0.source' => ['requiredIf'], 'v.1.source' => ['url']],
            self::failedRulesOf(['v' => $rule], ['v' => [['is_link' => true], ['is_link' => true, 'source' => 'paper'], ['is_link' => false]]]),
        );
    }

    public function testAConditionInsideAShapeMustNameAKeyOfThatShape(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::shape(['a' => Rule::string()->requiredWith('outer')]));
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>}>
     */
    public static function conditionalElements(): iterable
    {
        yield 'requiredWith' => [Rule::string()->requiredWith('a')];
        yield 'requiredIf' => [Rule::string()->requiredIf('a', 'x')];
        yield 'when' => [Rule::string()->when('a', 'x', static fn (StringRule $r): StringRule => $r->url())];
    }

    /**
     * @param FieldRule<covariant mixed> $element
     */
    #[DataProvider('conditionalElements')]
    public function testTheElementOfAListCannotNameAnotherField(FieldRule $element): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::list($element));
    }

    public function testAContainerDefaultIsNotCheckedAgainstTheConditions(): void
    {
        $rule = Rule::shape(['a' => Rule::string(), 'b' => Rule::string()->requiredWith('a')])->default(['a' => 'x']);

        $this->assertSame(['a' => 'x', 'b' => null], self::valueOf($rule, null));
    }
}
