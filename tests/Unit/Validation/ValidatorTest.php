<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\FieldRule;
use Sloop\Validation\ListRule;
use Sloop\Validation\Rule;
use Sloop\Validation\Sanitize;
use Sloop\Validation\ValidationError;
use Sloop\Validation\ValidationMessages;
use Sloop\Validation\Validator;
use UnexpectedValueException;

final class ValidatorTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    protected function setUp(): void
    {
        ValidationMessages::reset();
    }

    protected function tearDown(): void
    {
        ValidationMessages::reset();
    }

    /**
     * @param  array<string, non-empty-list<ValidationError>> $errors
     * @return array<string, list<string>>
     */
    private static function messages(array $errors): array
    {
        return array_map(static fn (array $list): array => array_map(static fn (ValidationError $e): string => $e->message, $list), $errors);
    }

    /**
     * @param  list<ValidationError> $errors
     * @return list<string>
     */
    private static function rules(array $errors): array
    {
        return array_map(static fn (ValidationError $e): string => $e->rule, $errors);
    }

    // ---------------------------------------------------------------
    // Results
    // ---------------------------------------------------------------

    public function testANumericFieldNameIsRefused(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => new Validator(['name' => Rule::string(), '1' => Rule::string()]),
        );
        self::assertSame(
            'Validator needs named fields, and 1 is a number: PHP turns a numeric key into an int, which cannot name a field.',
            $thrown->getMessage(),
        );
    }

    public function testWithRefusesANumericFieldName(): void
    {
        // PHP turns the key '2' into an int once with() puts it in the array.
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => new Validator(['name' => Rule::string()])->with('2', Rule::int()),
        );
    }

    // ---------------------------------------------------------------
    // Error keys of two declarations that coincide
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{array<string, FieldRule<covariant mixed>>, string}>
     */
    public static function collidingDeclarations(): iterable
    {
        yield 'dotted field and a key of a shape' => [
            ['a.b' => Rule::int(), 'a' => Rule::shape(['b' => Rule::int()])],
            'Field "a.b" and field "a" key "b" would both report their errors under "a.b"',
        ];
        yield 'dotted field and a shape inside a shape' => [
            ['a.b' => Rule::int(), 'a' => Rule::shape(['b' => Rule::shape(['c' => Rule::int()])])],
            'Field "a.b" and field "a" key "b" would both report their errors under "a.b"',
        ];
        yield 'every field name has a dot' => [
            ['a.b' => Rule::shape(['c' => Rule::int()]), 'a.b.c' => Rule::int()],
            'Field "a.b" key "c" and field "a.b.c" would both report their errors under "a.b.c"',
        ];
        yield 'dotted field and a dotted key' => [
            ['a.b.c' => Rule::int(), 'a' => Rule::shape(['b.c' => Rule::int()])],
            'Field "a.b.c" and field "a" key "b.c" would both report their errors under "a.b.c"',
        ];
        yield 'field after the shape it collides with' => [
            ['a' => Rule::shape(['b' => Rule::int()]), 'a.b' => Rule::int()],
            'Field "a" key "b" and field "a.b" would both report their errors under "a.b"',
        ];
        yield 'dotted field and an element of a list' => [
            ['items.0' => Rule::int(), 'items' => Rule::list(Rule::int())->maxCount(3)],
            'Field "items.0" and field "items" element would both report their errors under "items.0"',
        ];
        yield 'field after the list it collides with' => [
            ['items' => Rule::list(Rule::int())->maxCount(3), 'items.0' => Rule::int()],
            'Field "items" element and field "items.0" would both report their errors under "items.0"',
        ];
        yield 'list meeting two earlier fields names the first' => [
            ['a.0' => Rule::int(), 'a.1' => Rule::int(), 'a' => Rule::list(Rule::int())->maxCount(3)],
            'Field "a.0" and field "a" element would both report their errors under "a.0"',
        ];
        yield 'position the count bound never reaches' => [
            ['items.5' => Rule::int(), 'items' => Rule::list(Rule::int())->maxCount(3)],
            'Field "items.5" and field "items" element would both report their errors under "items.5"',
        ];
        yield 'dotted field and a key of a shape in a list' => [
            ['items.0.p' => Rule::int(), 'items' => Rule::list(Rule::shape(['p' => Rule::int()]))->maxCount(3)],
            'Field "items.0.p" and field "items" element key "p" would both report their errors under "items.0.p"',
        ];
        yield 'dotted field and a list in a list' => [
            ['m.0.1' => Rule::int(), 'm' => Rule::list(Rule::list(Rule::int())->maxCount(3))->maxCount(3)],
            'Field "m.0.1" and field "m" element element would both report their errors under "m.0.1"',
        ];
        yield 'dotted field and a list in a shape' => [
            ['s.l.0' => Rule::int(), 's' => Rule::shape(['l' => Rule::list(Rule::int())->maxCount(3)])],
            'Field "s.l.0" and field "s" key "l" element would both report their errors under "s.l.0"',
        ];
        yield 'element of a dotted list and a dotted key' => [
            ['a.1' => Rule::list(Rule::int())->maxCount(9), 'a' => Rule::shape(['1.5' => Rule::int()])],
            'Field "a.1" element and field "a" key "1.5" would both report their errors under "a.1.5"',
        ];
        yield 'dotted list and an element of a list' => [
            ['a.0' => Rule::list(Rule::int())->maxCount(3), 'a' => Rule::list(Rule::list(Rule::int())->maxCount(3))->maxCount(3)],
            'Field "a.0" and field "a" element would both report their errors under "a.0"',
        ];
        yield 'elements of two lists at different steps' => [
            ['a.0.x' => Rule::list(Rule::int())->maxCount(3), 'a' => Rule::list(Rule::shape(['x.0' => Rule::int()]))->maxCount(3)],
            'Field "a.0.x" element and field "a" element key "x.0" would both report their errors under "a.0.x.0"',
        ];
        yield 'trailing dot and an empty key' => [
            ['a.' => Rule::int(), 'a' => Rule::shape(['' => Rule::int()])],
            'Field "a." and field "a" key "" would both report their errors under "a."',
        ];
    }

    /**
     * @param array<string, FieldRule<covariant mixed>> $rules
     */
    #[DataProvider('collidingDeclarations')]
    public function testDeclarationsReportingErrorsUnderTheSameKeyAreRefused(array $rules, string $message): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator($rules));

        $this->assertSame(
            $message . '; rename one, as a dot in a name cannot be told from the dots that join a path.',
            $e->getMessage(),
        );
    }

    public function testKeysOfAShapeReportingErrorsUnderTheSameKeyAreRefusedWhenTheShapeIsDeclared(): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Rule::shape(['b.c' => Rule::int(), 'b' => Rule::shape(['c' => Rule::int()])]),
        );

        $this->assertStringStartsWith('Field "b.c" and field "b" key "c" would both report', $e->getMessage());
    }

    public function testWithRefusesAFieldReportingErrorsUnderTheKeyOfAnother(): void
    {
        $base = new Validator(['a' => Rule::shape(['b' => Rule::int()])]);

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $base->with('a.b', Rule::int()));

        $this->assertStringStartsWith('Field "a" key "b" and field "a.b" would both report', $e->getMessage());
    }

    /**
     * @return iterable<string, array{array<string, FieldRule<covariant mixed>>, array<string, mixed>, list<string>}>
     */
    public static function dottedNamesWithKeysOfTheirOwn(): iterable
    {
        yield 'dotted field beside a scalar' => [
            ['a.b' => Rule::int(), 'a' => Rule::int()],
            ['a.b' => 'x', 'a' => 'y'],
            ['a.b', 'a'],
        ];
        yield 'name after a list that is not a position' => [
            ['items.x' => Rule::int(), 'items' => Rule::list(Rule::int())->maxCount(3)],
            ['items.x' => 'x', 'items' => ['y']],
            ['items.x', 'items.0'],
        ];
        yield 'leading zero' => [
            ['items.00' => Rule::int(), 'items' => Rule::list(Rule::int())->maxCount(3)],
            ['items.00' => 'x', 'items' => ['y']],
            ['items.00', 'items.0'],
        ];
        yield 'negative number' => [
            ['items.-1' => Rule::int(), 'items' => Rule::list(Rule::int())->maxCount(3)],
            ['items.-1' => 'x', 'items' => ['y']],
            ['items.-1', 'items.0'],
        ];
        yield 'elements of a list beside a field at another position' => [
            ['x.1.5' => Rule::int(), 'x.0' => Rule::list(Rule::int())->maxCount(3)],
            ['x.1.5' => 'x', 'x.0' => ['y']],
            ['x.1.5', 'x.0.0'],
        ];
        yield 'dotted key beside another key' => [
            ['a' => Rule::shape(['b.c' => Rule::int(), 'b' => Rule::int()])],
            ['a' => ['b.c' => 'x', 'b' => 'y']],
            ['a.b.c', 'a.b'],
        ];
    }

    /**
     * @param array<string, FieldRule<covariant mixed>> $rules
     * @param array<string, mixed>                      $data
     * @param list<string>                              $keys
     */
    #[DataProvider('dottedNamesWithKeysOfTheirOwn')]
    public function testDottedNamesWhoseErrorsHaveKeysOfTheirOwnAreAccepted(array $rules, array $data, array $keys): void
    {
        $errors = new Validator($rules)->validate($data)->errors();

        $this->assertSame($keys, array_keys($errors));
        foreach ($errors as $list) {
            $this->assertCount(1, $list);
        }
    }

    public function testValidInputYieldsValuesAndNoErrors(): void
    {
        $result = new Validator([
            'name' => Rule::string()->required(),
            'age'  => Rule::int(),
        ])->validate(['name' => 'Alice', 'age' => '42']);

        $this->assertFalse($result->failed());
        $this->assertSame([], $result->errors());
        $this->assertSame(['name' => 'Alice', 'age' => 42], $result->values());
    }

    public function testKeysWithoutRulesAreDroppedFromValues(): void
    {
        $result = new Validator(['name' => Rule::string()])->validate(['name' => 'Alice', 'admin' => '1']);

        $this->assertSame(['name' => 'Alice'], $result->values());
    }

    public function testFailedFieldsAreAbsentFromValues(): void
    {
        $result = new Validator([
            'name' => Rule::string()->required(),
            'age'  => Rule::int(),
        ])->validate(['age' => 'abc', 'name' => 'Bob']);

        $this->assertTrue($result->failed());
        $this->assertSame(['name' => 'Bob'], $result->values());
        $this->assertSame(['age'], array_keys($result->errors()));
    }

    public function testAFailureInOneFieldDoesNotStopTheOthers(): void
    {
        $result = new Validator([
            'a' => Rule::int(),
            'b' => Rule::list(Rule::int())->maxCount(1),
            'c' => Rule::string()->required(),
        ])->validate(['a' => 'x', 'b' => [1, 2]]);

        $this->assertSame(['a', 'b', 'c'], array_keys($result->errors()));
    }

    public function testErrorCarriesRuleParamsAndMessage(): void
    {
        $result = new Validator(['name' => Rule::string()->minLength(3)])->validate(['name' => 'ab']);

        self::assertErrorsSame(
            ['name' => [new ValidationError('minLength', ['min' => 3, 'unit' => 'graphemes'], 'The name field must be at least 3 characters.')]],
            $result->errors(),
        );
    }

    public function testValidatorCanBeReusedForDifferentInputs(): void
    {
        $validator = new Validator(['age' => Rule::int()->required()]);

        $this->assertSame(['age' => 1], $validator->validate(['age' => 1])->values());
        $this->assertTrue($validator->validate([])->failed());
        $this->assertSame(['age' => 2], $validator->validate(['age' => '2'])->values());
    }

    // ---------------------------------------------------------------
    // Empty values, required, default
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function emptyInputs(): iterable
    {
        yield 'missing key' => [[]];
        yield 'null' => [['v' => null]];
        yield 'empty string' => [['v' => '']];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('emptyInputs')]
    public function testEmptyOptionalFieldYieldsNullAndSkipsRules(array $data): void
    {
        $result = new Validator(['v' => Rule::int()->min(10)])->validate($data);

        $this->assertFalse($result->failed());
        $this->assertSame(['v' => null], $result->values());
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('emptyInputs')]
    public function testEmptyRequiredFieldFailsWithRequiredOnly(array $data): void
    {
        $result = new Validator(['v' => Rule::string()->required()->minLength(3)])->validate($data);

        self::assertErrorsSame(['v' => [new ValidationError('required', [], 'The v field is required.')]], $result->errors());
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('emptyInputs')]
    public function testEmptyFieldYieldsDefaultWithoutValidatingIt(array $data): void
    {
        $result = new Validator(['v' => Rule::int()->min(10)->default(0)])->validate($data);

        $this->assertFalse($result->failed());
        $this->assertSame(['v' => 0], $result->values());
    }

    public function testDefaultIsNotUsedForNonEmptyValue(): void
    {
        $this->assertSame(['v' => 5], new Validator(['v' => Rule::int()->default(0)])->validate(['v' => 5])->values());
    }

    public function testFalseAndZeroAreNotEmpty(): void
    {
        $result = new Validator([
            'b' => Rule::bool()->required(),
            'i' => Rule::int()->required(),
            's' => Rule::string()->required(),
        ])->validate(['b' => false, 'i' => 0, 's' => '0']);

        $this->assertSame(['b' => false, 'i' => 0, 's' => '0'], $result->values());
    }

    public function testRequiredAfterDefaultThrows(): void
    {
        $e = $this->assertThrows(LogicException::class, static fn () => Rule::int()->default(0)->required());

        $this->assertSame('A field cannot be both required and have a default.', $e->getMessage());
    }

    public function testDefaultAfterRequiredThrows(): void
    {
        $e = $this->assertThrows(LogicException::class, static fn () => Rule::int()->required()->default(0));

        $this->assertSame('A field cannot be both required and have a default.', $e->getMessage());
    }

    public function testSecondDefaultThrows(): void
    {
        $e = $this->assertThrows(LogicException::class, static fn () => Rule::int()->default(0)->default(1));

        $this->assertSame('The default has already been set.', $e->getMessage());
    }

    // ---------------------------------------------------------------
    // Failure collection
    // ---------------------------------------------------------------

    public function testAllFailedRulesOfAFieldAreCollectedInDeclarationOrder(): void
    {
        $result = new Validator([
            'code' => Rule::string()->maxLength(2)->regex('/\A\d+\z/')->notIn(['abc']),
        ])->validate(['code' => 'abc']);

        $this->assertSame(['maxLength', 'regex', 'notIn'], self::rules($result->errors()['code']));
    }

    public function testTypeFailureStopsTheFieldsRules(): void
    {
        $result = new Validator(['age' => Rule::int()->min(10)->max(5)])->validate(['age' => 'abc']);

        self::assertErrorsSame(
            ['age' => [new ValidationError('int', [], 'The age field must be an integer.')]],
            $result->errors(),
        );
    }

    // ---------------------------------------------------------------
    // Builders are immutable
    // ---------------------------------------------------------------

    public function testRequiredLeavesTheOriginalOptional(): void
    {
        $base = Rule::string();
        (void) $base->required();

        $this->assertFalse(new Validator(['v' => $base])->validate([])->failed());
    }

    public function testDefaultLeavesTheOriginalWithoutDefault(): void
    {
        $base = Rule::string();
        (void) $base->default('x');

        $this->assertSame(['v' => null], new Validator(['v' => $base])->validate([])->values());
    }

    public function testAddingARuleLeavesTheOriginalWithoutIt(): void
    {
        $base = Rule::string();
        (void) $base->minLength(5);

        $this->assertFalse(new Validator(['v' => $base])->validate(['v' => 'ab'])->failed());
    }

    public function testComparisonLeavesTheOriginalWithoutIt(): void
    {
        $base = Rule::string();
        (void) $base->same('w');

        $this->assertFalse(new Validator(['v' => $base, 'w' => Rule::string()])->validate(['v' => 'a', 'w' => 'b'])->failed());
    }

    public function testMessageAndLabelLeaveTheOriginalUnchanged(): void
    {
        $base = Rule::string()->required();
        (void) $base->message('custom {label}');
        (void) $base->label('Name');

        $this->assertSame(
            ['v' => ['The v field is required.']],
            self::messages(new Validator(['v' => $base])->validate([])->errors()),
        );
    }

    // ---------------------------------------------------------------
    // Sanitize
    // ---------------------------------------------------------------

    public function testSanitizersRunInOrderBeforeValidation(): void
    {
        $result = new Validator([
            'v' => Rule::string(Sanitize::Trim, mb_strtolower(...), static fn (string $s): string => $s . '!')->in(['abc!']),
        ])->validate(['v' => "\u{3000} ABC \t"]);

        $this->assertSame(['v' => 'abc!'], $result->values());
    }

    /**
     * @return iterable<string, array{Sanitize, string}>
     */
    public static function interiorDeletingSanitizers(): iterable
    {
        yield 'newlines' => [Sanitize::StripNewlines, "\n"];
        yield 'tabs' => [Sanitize::StripTabs, "\t"];
        yield 'control chars' => [Sanitize::StripControlChars, "\x0B"];
    }

    #[DataProvider('interiorDeletingSanitizers')]
    public function testSanitizerThatDeletesInteriorCharactersAfterStripTagsThrows(Sanitize $after, string $interior): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::string(Sanitize::StripTags, $after));

        $this->assertSame('Sanitize::' . $after->name . ' must come before Sanitize::StripTags, not after it.', $e->getMessage());
    }

    #[DataProvider('interiorDeletingSanitizers')]
    public function testTheSameSanitizerBeforeStripTagsRemovesTheTag(Sanitize $before, string $interior): void
    {
        $rule = Rule::string($before, Sanitize::StripTags);

        $this->assertSame(['v' => null], new Validator(['v' => $rule])->validate(['v' => '<' . $interior . 'img src=x onerror=alert(1)>'])->values());
    }

    public function testASanitizerAfterStripTagsThrowsEvenWithAnotherOneBetween(): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Rule::string(Sanitize::StripTags, Sanitize::Trim, Sanitize::StripNewlines),
        );

        $this->assertSame('Sanitize::StripNewlines must come before Sanitize::StripTags, not after it.', $e->getMessage());
    }

    public function testTrimIsAcceptedAfterStripTags(): void
    {
        $rule = Rule::string(Sanitize::StripTags, Sanitize::Trim);

        $this->assertSame(['v' => 'text'], new Validator(['v' => $rule])->validate(['v' => ' <b>text</b> '])->values());
    }

    public function testSanitizersCombineIntoASingleLineField(): void
    {
        $rule = Rule::string(Sanitize::StripControlChars, Sanitize::StripNewlines, Sanitize::StripTabs);

        $this->assertSame(['v' => 'abcd'], new Validator(['v' => $rule])->validate(['v' => "a\x00b\r\nc\td"])->values());
    }

    public function testSanitizingToEmptyMakesTheFieldEmpty(): void
    {
        $result = new Validator(['v' => Rule::string(Sanitize::Trim)->required()])->validate(['v' => "  \u{3000}"]);

        $this->assertSame(['required'], self::rules($result->errors()['v']));
    }

    public function testSanitizeRunsBeforeTypeConversion(): void
    {
        $this->assertSame(['v' => 42], new Validator(['v' => Rule::int(Sanitize::Trim)])->validate(['v' => ' 42 '])->values());
    }

    public function testSanitizersAreNotAppliedToNonStringValues(): void
    {
        $rule = Rule::int(static fn (string $s): string => throw new LogicException('must not be called'));

        $this->assertSame(['v' => 7], new Validator(['v' => $rule])->validate(['v' => 7])->values());
    }

    public function testSanitizersAreNotAppliedToInvalidUtf8(): void
    {
        $rule = Rule::string(static fn (string $s): string => throw new LogicException('must not be called'));

        $result = new Validator(['v' => $rule])->validate(['v' => "\xff"]);

        $this->assertSame(['string'], self::rules($result->errors()['v']));
    }

    public function testSanitizerClosureReturningNonStringThrows(): void
    {
        // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
        $rule = Rule::string(static fn (string $s): mixed => 1);

        $e = $this->assertThrows(UnexpectedValueException::class, static fn () => new Validator(['v' => $rule])->validate(['v' => 'x']));

        $this->assertSame('A sanitizer closure must return a string, got int.', $e->getMessage());
    }

    // ---------------------------------------------------------------
    // Messages and labels
    // ---------------------------------------------------------------

    public function testRuleMessageWinsOverFieldMessage(): void
    {
        $rule = Rule::string()->message('field: {label}')->minLength(3, message: 'rule: {label} {min}')->maxLength(1);

        $result = new Validator(['v' => $rule])->validate(['v' => 'ab']);

        $this->assertSame(['v' => ['rule: v 3', 'field: v']], self::messages($result->errors()));
    }

    public function testFieldMessageAppliesRegardlessOfItsPositionInTheChain(): void
    {
        $rule = Rule::string()->minLength(3)->message('field')->maxLength(1);

        $result = new Validator(['v' => $rule])->validate(['v' => 'ab']);

        $this->assertSame(['v' => ['field', 'field']], self::messages($result->errors()));
    }

    public function testFieldMessageAppliesToTypeAndRequiredFailures(): void
    {
        $validator = new Validator(['v' => Rule::int()->required()->message('bad {label}')]);

        $this->assertSame(['v' => ['bad v']], self::messages($validator->validate([])->errors()));
        $this->assertSame(['v' => ['bad v']], self::messages($validator->validate(['v' => 'x'])->errors()));
    }

    public function testRequiredTakesItsOwnMessage(): void
    {
        $result = new Validator(['v' => Rule::string()->required('need {label}')])->validate([]);

        $this->assertSame(['v' => ['need v']], self::messages($result->errors()));
    }

    public function testLabelReplacesFieldNameInMessages(): void
    {
        $result = new Validator(['user_name' => Rule::string()->required()->label('User name')])->validate([]);

        $this->assertSame(['user_name' => ['The User name field is required.']], self::messages($result->errors()));
    }

    public function testSecondMessageThrows(): void
    {
        $e = $this->assertThrows(LogicException::class, static fn () => Rule::string()->message('a')->message('b'));

        $this->assertSame('The field message has already been set.', $e->getMessage());
    }

    public function testSecondLabelThrows(): void
    {
        $e = $this->assertThrows(LogicException::class, static fn () => Rule::string()->label('a')->label('b'));

        $this->assertSame('The label has already been set.', $e->getMessage());
    }

    /**
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function quotedPlaceholderDeclarations(): iterable
    {
        yield 'message()' => [static fn () => Rule::string()->message("'{label}' is bad")];
        yield 'rule message' => [static fn () => Rule::string()->minLength(1, message: "'{min}'")];
        yield 'required message' => [static fn () => Rule::string()->required("'{label}'")];
        yield 'same message' => [static fn () => Rule::string()->same('x', "'{other}'")];
    }

    /**
     * @param Closure(): mixed $declare
     */
    #[DataProvider('quotedPlaceholderDeclarations')]
    public function testMessageWithSingleQuoteBeforePlaceholderThrowsAtDeclaration(Closure $declare): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, $declare);

        $this->assertStringContainsString('puts a single quote before "{"', $e->getMessage());
    }

    public function testApostropheInMessageIsKept(): void
    {
        $result = new Validator(['v' => Rule::string()->required("{label} can't be empty")])->validate([]);

        $this->assertSame(['v' => ["v can't be empty"]], self::messages($result->errors()));
    }

    public function testDoubleQuotedPlaceholderIsFilledIn(): void
    {
        $result = new Validator(['v' => Rule::string()->in(['a', 'b'], message: '"{values}" only')])->validate(['v' => 'c']);

        $this->assertSame(['v' => ['"a, b" only']], self::messages($result->errors()));
    }

    // ---------------------------------------------------------------
    // same() / different()
    // ---------------------------------------------------------------

    public function testSameComparesValidatedValues(): void
    {
        $result = new Validator([
            'password'         => Rule::string(Sanitize::Trim),
            'password_confirm' => Rule::string()->same('password'),
        ])->validate(['password' => 'secret ', 'password_confirm' => 'secret']);

        $this->assertFalse($result->failed());
    }

    public function testSameComparesAfterTypeConversion(): void
    {
        $result = new Validator([
            'a' => Rule::int()->same('b'),
            'b' => Rule::int(),
        ])->validate(['a' => '042', 'b' => 42]);

        $this->assertFalse($result->failed());
    }

    public function testSameFailsForDifferentValues(): void
    {
        $result = new Validator([
            'confirm'  => Rule::string()->same('password'),
            'password' => Rule::string()->label('Password'),
        ])->validate(['password' => 'a', 'confirm' => 'b']);

        self::assertErrorsSame(
            ['confirm' => [new ValidationError('same', ['other' => 'password'], 'The confirm field must match Password.')]],
            $result->errors(),
        );
    }

    public function testDifferentFailsForEqualValues(): void
    {
        $result = new Validator([
            'old' => Rule::string(),
            'new' => Rule::string()->different('old', message: '{label} vs {other}'),
        ])->validate(['old' => 'a', 'new' => 'a']);

        self::assertErrorsSame(
            ['new' => [new ValidationError('different', ['other' => 'old'], 'new vs old')]],
            $result->errors(),
        );
    }

    public function testDifferentPassesForDifferentValues(): void
    {
        $result = new Validator([
            'old' => Rule::string(),
            'new' => Rule::string()->different('old'),
        ])->validate(['old' => 'a', 'new' => 'b']);

        $this->assertFalse($result->failed());
    }

    public function testComparisonIsSkippedWhenTheOtherFieldFailed(): void
    {
        $result = new Validator([
            'confirm'  => Rule::string()->same('password'),
            'password' => Rule::string()->minLength(8),
        ])->validate(['password' => 'short', 'confirm' => 'other']);

        $this->assertSame(['password'], array_keys($result->errors()));
    }

    public function testComparisonIsSkippedWhenThisFieldIsEmptyOrOfTheWrongType(): void
    {
        $validator = new Validator([
            'a' => Rule::int()->same('b'),
            'b' => Rule::int(),
        ]);

        $this->assertFalse($validator->validate(['b' => 1])->failed());
        $this->assertSame(['int'], self::rules($validator->validate(['a' => 'x', 'b' => 1])->errors()['a']));
    }

    public function testComparisonIsSkippedForAnEmptyRequiredField(): void
    {
        $result = new Validator([
            'a' => Rule::string()->required()->same('b'),
            'b' => Rule::string(),
        ])->validate(['b' => 'x']);

        $this->assertSame(['required'], self::rules($result->errors()['a']));
    }

    public function testComparisonIsSkippedForAFieldThatFellBackToItsDefault(): void
    {
        $result = new Validator([
            'a' => Rule::int()->default(1)->same('b'),
            'b' => Rule::int(),
        ])->validate(['b' => 2]);

        $this->assertFalse($result->failed());
    }

    public function testASkippedFieldDoesNotStopLaterFieldsComparisons(): void
    {
        $result = new Validator([
            'a' => Rule::int()->same('c'),
            'b' => Rule::int()->same('c'),
            'c' => Rule::int(),
        ])->validate(['b' => 1, 'c' => 2]);

        $this->assertSame(['b'], array_keys($result->errors()));
    }

    public function testASkippedComparisonDoesNotStopTheFieldsNextComparison(): void
    {
        $result = new Validator([
            'a' => Rule::int()->same('b')->different('c'),
            'b' => Rule::int()->max(0),
            'c' => Rule::int(),
        ])->validate(['a' => 1, 'b' => 5, 'c' => 1]);

        $this->assertSame(['different'], self::rules($result->errors()['a']));
    }

    public function testComparisonRunsAlongsideTheFieldsOtherFailures(): void
    {
        $result = new Validator([
            'a' => Rule::string()->minLength(5)->same('b'),
            'b' => Rule::string(),
        ])->validate(['a' => 'x', 'b' => 'y']);

        $this->assertSame(['minLength', 'same'], self::rules($result->errors()['a']));
    }

    public function testAComparisonThatFailedDoesNotStopTheComparisonsPointingAtThatField(): void
    {
        $rules = [
            'a' => Rule::string()->same('b'),
            'b' => Rule::string()->same('c'),
            'c' => Rule::string(),
        ];
        $data  = ['a' => 'x', 'b' => 'y', 'c' => 'z'];

        $this->assertSame(['a', 'b'], array_keys(new Validator($rules)->validate($data)->errors()));
        $this->assertSame(['b', 'a'], array_keys(new Validator(array_reverse($rules))->validate($data)->errors()));
    }

    public function testComparisonWithAnEmptyOtherFieldWithoutADefaultComparesWithNull(): void
    {
        $validator = new Validator([
            'a' => Rule::string()->same('b'),
            'b' => Rule::string(),
        ]);

        $this->assertSame(['same'], self::rules($validator->validate(['a' => 'x'])->errors()['a']));
        $this->assertFalse($validator->validate([])->failed());
    }

    public function testComparisonWithAnEmptyOtherFieldComparesWithItsDefault(): void
    {
        $validator = new Validator([
            'a' => Rule::int()->same('b'),
            'b' => Rule::int()->default(3),
        ]);

        $this->assertFalse($validator->validate(['a' => 3])->failed());
        $this->assertTrue($validator->validate(['a' => 4])->failed());
    }

    public function testComparisonWithAFieldWithoutRulesThrows(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator(['a' => Rule::string()->same('b')]));

        $this->assertSame('Field "a" compares with "b", which has no rule.', $e->getMessage());
    }

    // ---------------------------------------------------------------
    // with()
    // ---------------------------------------------------------------

    public function testWithReturnsAValidatorWithTheExtraField(): void
    {
        $base     = new Validator(['a' => Rule::int()]);
        $extended = $base->with('b', Rule::int()->required());

        $this->assertSame(['a' => 1, 'b' => 2], $extended->validate(['a' => 1, 'b' => 2])->values());
        $this->assertSame(['a' => 1], $base->validate(['a' => 1, 'b' => 2])->values());
    }

    public function testWithAnExistingFieldThrows(): void
    {
        $base = new Validator(['a' => Rule::int()]);

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $base->with('a', Rule::string()));

        $this->assertSame('Field "a" already has rules.', $e->getMessage());
    }

    public function testWithChecksComparisonTargets(): void
    {
        $base = new Validator(['a' => Rule::int()]);

        $this->assertThrows(InvalidArgumentException::class, static fn () => $base->with('b', Rule::int()->same('c')));
        $this->assertFalse($base->with('b', Rule::int()->same('a'))->validate(['a' => 1, 'b' => 1])->failed());
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>, string}>
     */
    public static function unboundedLists(): iterable
    {
        yield 'list without a bound'          => [Rule::list(Rule::int()), 'ids'];
        yield 'inner list without a bound'    => [Rule::list(Rule::list(Rule::int()))->maxCount(5), 'ids.*'];
        yield 'list in a shape'               => [Rule::shape(['tags' => Rule::list(Rule::int())]), 'ids.tags'];
        yield 'list in a shape in a list'     => [Rule::list(Rule::shape(['tags' => Rule::list(Rule::int())]))->maxCount(5), 'ids.*.tags'];
        yield 'bound only inside when()'      => [Rule::list(Rule::int())->when('kind', 'x', static fn (ListRule $r): ListRule => $r->maxCount(5)), 'ids'];
    }

    /**
     * @param FieldRule<covariant mixed> $rule
     */
    #[DataProvider('unboundedLists')]
    public function testAListWithoutAnUpperBoundOnItsCountIsRefused(FieldRule $rule, string $path): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator(['kind' => Rule::string(), 'ids' => $rule]));

        $this->assertSame(
            'The list() at "' . $path . '" needs maxCount(), exactCount() or betweenCount(), so that an input failing every element cannot exhaust memory.',
            $e->getMessage(),
        );
    }

    public function testWithRefusesAListWithoutAnUpperBoundOnItsCount(): void
    {
        $base = new Validator(['a' => Rule::int()]);

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $base->with('ids', Rule::list(Rule::int())));

        $this->assertStringStartsWith('The list() at "ids" needs maxCount()', $e->getMessage());
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>}>
     */
    public static function boundedCounts(): iterable
    {
        yield 'maxCount'               => [Rule::list(Rule::int())->maxCount(5)];
        yield 'exactCount'             => [Rule::list(Rule::int())->exactCount(5)];
        yield 'betweenCount'           => [Rule::list(Rule::int())->betweenCount(1, 5)];
        yield 'every depth bounded'    => [Rule::list(Rule::shape(['tags' => Rule::list(Rule::int())->maxCount(3)]))->maxCount(5)];
        yield 'array without elements' => [Rule::array()];
    }

    /**
     * @param FieldRule<covariant mixed> $rule
     */
    #[DataProvider('boundedCounts')]
    public function testAListWithAnUpperBoundOnEveryDepthIsAccepted(FieldRule $rule): void
    {
        $validator = new Validator(['ids' => $rule]);

        $this->assertTrue($validator->validate(['ids' => 'x'])->failed());
    }
}
