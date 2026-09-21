<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\ArraySanitize;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;
use Sloop\Validation\ValidationError;
use Sloop\Validation\Validator;
use UnexpectedValueException;

final class ArrayRuleTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    // ---------------------------------------------------------------
    // Rule::array() — the value is an array, whatever it holds
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function acceptedArrays(): iterable
    {
        yield 'list' => [[1, 'two', null]];
        yield 'map' => [['a' => 1]];
        yield 'empty' => [[]];
        yield 'nested' => [[['a']]];
    }

    #[DataProvider('acceptedArrays')]
    public function testArrayAcceptsAnyArrayAndKeepsItAsItIs(mixed $input): void
    {
        $this->assertSame($input, self::valueOf(Rule::array(), $input));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedArrays(): iterable
    {
        yield 'int' => [1];
        yield 'string' => ['a'];
        yield 'bool' => [true];
        yield 'float' => [1.5];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('rejectedArrays')]
    public function testArrayRejectsWhatIsNotAnArray(mixed $input): void
    {
        $this->assertSame(['array'], self::failedRules(Rule::array(), $input));
    }

    public function testArrayReportsTheTypeFailureWithTheDefaultMessage(): void
    {
        self::assertErrorSame(
            new ValidationError('array', [], 'The v field must be an array.'),
            self::onlyError(Rule::array(), 'no'),
        );
    }

    // ---------------------------------------------------------------
    // Emptiness: [] is a value, a missing key is not
    // ---------------------------------------------------------------

    public function testRequiredLetsAnEmptyArrayThroughBecauseItIsAValue(): void
    {
        $this->assertSame([], self::valueOf(Rule::array()->required(), []));
    }

    public function testRequiredFailsOnAMissingKey(): void
    {
        $this->assertSame(['required'], self::failedRules(Rule::array()->required(), null));
    }

    public function testAMissingKeyYieldsNullWhenNoDefaultIsDeclared(): void
    {
        $this->assertNull(self::valueOf(Rule::array(), null));
    }

    public function testAMissingKeyYieldsTheDeclaredDefault(): void
    {
        $this->assertSame(['a'], self::valueOf(Rule::array()->default(['a']), null));
    }

    public function testAnEmptyArrayCanBeTheDeclaredDefault(): void
    {
        $this->assertSame([], self::valueOf(Rule::list(Rule::int())->default([]), null));
    }

    public function testMinCountIsWhatRejectsAnEmptyArray(): void
    {
        $this->assertSame(['minCount'], self::failedRules(Rule::array()->minCount(1), []));
    }

    // ---------------------------------------------------------------
    // Count rules
    // ---------------------------------------------------------------

    public function testMinCountCountsTheElements(): void
    {
        $rule = Rule::array()->minCount(2);

        $this->assertSame([], self::failedRules($rule, [1, 2]));
        $this->assertSame(['minCount'], self::failedRules($rule, [1]));
    }

    public function testMaxCountCountsTheElements(): void
    {
        $rule = Rule::array()->maxCount(2);

        $this->assertSame([], self::failedRules($rule, [1, 2]));
        $this->assertSame(['maxCount'], self::failedRules($rule, [1, 2, 3]));
    }

    public function testBetweenCountAcceptsBothBoundsAndRejectsOutsideThem(): void
    {
        $rule = Rule::array()->betweenCount(1, 2);

        $this->assertSame([], self::failedRules($rule, [1]));
        $this->assertSame([], self::failedRules($rule, [1, 2]));
        $this->assertSame(['betweenCount'], self::failedRules($rule, []));
        $this->assertSame(['betweenCount'], self::failedRules($rule, [1, 2, 3]));
    }

    public function testExactCountCountsTheElements(): void
    {
        $rule = Rule::array()->exactCount(2);

        $this->assertSame([], self::failedRules($rule, [1, 2]));
        $this->assertSame(['exactCount'], self::failedRules($rule, [1]));
    }

    public function testACountFailureStopsBeforeTheElementsAreValidated(): void
    {
        $rule   = Rule::list(Rule::int())->maxCount(1);
        $errors = new Validator(['v' => $rule])->validate(['v' => ['x', 'y', 'z']])->errors();

        $this->assertSame(['v'], array_keys($errors));
        $this->assertSame(['maxCount'], array_map(static fn (ValidationError $e): string => $e->rule, $errors['v']));
    }

    public function testAPassingCountStillValidatesTheElements(): void
    {
        $errors = new Validator(['v' => Rule::list(Rule::int())->maxCount(5)])->validate(['v' => ['x', 'y', 'z']])->errors();

        $this->assertSame(['v.0', 'v.1', 'v.2'], array_keys($errors));
    }

    public function testACountFailureStopsAComparisonFromRunning(): void
    {
        $validator = new Validator([
            'a' => Rule::array()->maxCount(1)->same('b'),
            'b' => Rule::array(),
        ]);

        $errors = $validator->validate(['a' => [1, 2], 'b' => ['different']])->errors();

        $this->assertSame(['maxCount'], array_map(static fn (ValidationError $e): string => $e->rule, $errors['a']));
    }

    public function testACountRuleLeavesTheRuleItWasCalledOnUnchanged(): void
    {
        $base = Rule::array();

        $this->assertSame(['maxCount'], self::failedRules($base->maxCount(1), [1, 2, 3]));
        $this->assertSame([], self::failedRules($base, [1, 2, 3]));
    }

    public function testCountRulesCountTheKeysOfAMapToo(): void
    {
        $this->assertSame([], self::failedRules(Rule::array()->exactCount(2), ['a' => 1, 'b' => 2]));
    }

    public function testACountOfZeroIsAccepted(): void
    {
        $this->assertSame([], self::failedRules(Rule::array()->minCount(0), []));
        $this->assertSame([], self::failedRules(Rule::array()->maxCount(0), []));
        $this->assertSame([], self::failedRules(Rule::array()->exactCount(0), []));
        $this->assertSame([], self::failedRules(Rule::array()->betweenCount(0, 1), []));
    }

    public function testBetweenCountAcceptsTheSameValueForBothBounds(): void
    {
        $this->assertSame([], self::failedRules(Rule::array()->betweenCount(2, 2), [1, 2]));
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>, mixed, ValidationError}>
     */
    public static function countFailures(): iterable
    {
        yield 'minCount' => [
            Rule::array()->minCount(2),
            [1],
            new ValidationError('minCount', ['min' => 2], 'The v field must have at least 2 items.'),
        ];
        yield 'maxCount' => [
            Rule::array()->maxCount(1),
            [1, 2],
            new ValidationError('maxCount', ['max' => 1], 'The v field must not have more than 1 items.'),
        ];
        yield 'betweenCount' => [
            Rule::array()->betweenCount(2, 3),
            [1],
            new ValidationError('betweenCount', ['min' => 2, 'max' => 3], 'The v field must have between 2 and 3 items.'),
        ];
        yield 'exactCount' => [
            Rule::array()->exactCount(2),
            [1],
            new ValidationError('exactCount', ['count' => 2], 'The v field must have exactly 2 items.'),
        ];
    }

    /**
     * @param FieldRule<covariant mixed> $rule
     */
    #[DataProvider('countFailures')]
    public function testACountFailureCarriesItsBoundsAsParameters(
        FieldRule $rule,
        mixed $input,
        ValidationError $expected,
    ): void {
        self::assertErrorSame($expected, self::onlyError($rule, $input));
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function rejectedCountArguments(): iterable
    {
        yield 'negative min' => [static fn (): mixed => Rule::array()->minCount(-1), 'minCount() needs a count of 0 or more, got -1.'];
        yield 'negative max' => [static fn (): mixed => Rule::array()->maxCount(-1), 'maxCount() needs a count of 0 or more, got -1.'];
        yield 'negative between' => [static fn (): mixed => Rule::array()->betweenCount(-1, 2), 'betweenCount() needs a count of 0 or more, got -1.'];
        yield 'reversed between' => [static fn (): mixed => Rule::array()->betweenCount(3, 2), 'betweenCount() needs min <= max, got 3 and 2.'];
        yield 'negative exact' => [static fn (): mixed => Rule::array()->exactCount(-1), 'exactCount() needs a count of 0 or more, got -1.'];
    }

    /**
     * @param Closure(): mixed $declare
     */
    #[DataProvider('rejectedCountArguments')]
    public function testACountRuleRefusesAnImpossibleBound(Closure $declare, string $message): void
    {
        $this->assertSame($message, $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage());
    }

    // ---------------------------------------------------------------
    // Rule::list() — every element has the same type
    // ---------------------------------------------------------------

    public function testListValidatesEveryElement(): void
    {
        $this->assertSame([1, 2], self::valueOf(Rule::list(Rule::int()), ['1', '2']));
    }

    public function testListReportsTheFailingElementUnderItsIndex(): void
    {
        $errors = new Validator(['v' => Rule::list(Rule::int())])->validate(['v' => [1, 'x']])->errors();

        $this->assertSame(['v.1'], array_keys($errors));
        self::assertErrorSame(new ValidationError('int', [], 'The 1 field must be an integer.'), $errors['v.1'][0]);
    }

    public function testListReportsEveryFailingElementRatherThanStoppingAtTheFirst(): void
    {
        $errors = new Validator(['v' => Rule::list(Rule::int())])->validate(['v' => ['x', 2, 'y']])->errors();

        $this->assertSame(['v.0', 'v.2'], array_keys($errors));
    }

    public function testListRunsEveryRuleOfTheElement(): void
    {
        $errors = new Validator(['v' => Rule::list(Rule::int()->min(10)->max(5))])->validate(['v' => [7]])->errors();

        $this->assertSame(['min', 'max'], array_map(static fn (ValidationError $e): string => $e->rule, $errors['v.0']));
    }

    public function testListRenumbersTheValidatedValue(): void
    {
        $this->assertSame([1, 2], self::valueOf(Rule::list(Rule::int()), [3 => '1', 9 => '2']));
    }

    public function testListCountsPositionsWhateverKeysTheInputCameUnder(): void
    {
        $errors = new Validator(['v' => Rule::list(Rule::int())])->validate(['v' => ['a' => 1, 'b' => 'x']])->errors();

        $this->assertSame(['v.1'], array_keys($errors));
    }

    public function testAnInputKeyReachesNeitherTheErrorNorTheMessage(): void
    {
        $hostile = '<script>alert(1)</script>';
        $errors  = new Validator(['v' => Rule::list(Rule::string()->minLength(3))])
            ->validate(['v' => [$hostile => 'x']])
            ->errors();

        $this->assertSame(['v.0'], array_keys($errors));
        $this->assertSame('The 0 field must be at least 3 characters.', $errors['v.0'][0]->message);
    }

    public function testListDropsTheWholeFieldFromTheValuesWhenAnElementFails(): void
    {
        $result = new Validator(['v' => Rule::list(Rule::int())])->validate(['v' => [1, 'x']]);

        $this->assertSame([], $result->values());
    }

    public function testACountRuleOfAListSeesTheElementsThatWereValidated(): void
    {
        $rule = Rule::list(Rule::int(), ArraySanitize::RemoveEmpty)->minCount(2);

        $this->assertSame(['minCount'], self::failedRules($rule, [1, null, '']));
    }

    public function testAnEmptyElementOfAListTakesItsDeclaredDefault(): void
    {
        $this->assertSame([1, 0], self::valueOf(Rule::list(Rule::int()->default(0)), [1, null]));
    }

    // ---------------------------------------------------------------
    // Rule::shape() — each key has its own type
    // ---------------------------------------------------------------

    public function testShapeValidatesEachDeclaredKey(): void
    {
        $rule = Rule::shape(['name' => Rule::string(), 'age' => Rule::int()]);

        $this->assertSame(['name' => 'a', 'age' => 7], self::valueOf($rule, ['name' => 'a', 'age' => '7']));
    }

    public function testShapeReportsTheFailingKeyUnderItsName(): void
    {
        $rule   = Rule::shape(['price' => Rule::int()]);
        $errors = new Validator(['v' => $rule])->validate(['v' => ['price' => 'x']])->errors();

        $this->assertSame(['v.price'], array_keys($errors));
        self::assertErrorSame(new ValidationError('int', [], 'The price field must be an integer.'), $errors['v.price'][0]);
    }

    public function testShapeDropsTheKeysItDoesNotDeclare(): void
    {
        $rule = Rule::shape(['a' => Rule::int()]);

        $this->assertSame(['a' => 1], self::valueOf($rule, ['a' => '1', 'b' => 'ignored']));
    }

    public function testShapeRefusesToBeDeclaredWithNoKey(): void
    {
        $this->assertSame(
            'shape() needs at least one key.',
            $this->assertThrows(InvalidArgumentException::class, static fn (): mixed => Rule::shape([]))->getMessage(),
        );
    }

    public function testShapeResolvesAComparisonBetweenItsOwnKeys(): void
    {
        $rule      = Rule::shape([
            'password' => Rule::string(),
            'confirm'  => Rule::string()->same('password'),
        ]);
        $validator = new Validator(['v' => $rule]);

        $this->assertFalse($validator->validate(['v' => ['password' => 'a', 'confirm' => 'a']])->failed());

        $errors = $validator->validate(['v' => ['password' => 'a', 'confirm' => 'b']])->errors();
        $this->assertSame(['v.confirm'], array_keys($errors));
        self::assertErrorSame(
            new ValidationError('same', ['other' => 'password'], 'The confirm field must match password.'),
            $errors['v.confirm'][0],
        );
    }

    public function testAComparisonInsideAShapeNamesTheLabelOfTheKeyItComparesWith(): void
    {
        $rule   = Rule::shape([
            'password' => Rule::string()->label('the password'),
            'confirm'  => Rule::string()->same('password'),
        ]);
        $errors = new Validator(['v' => $rule])->validate(['v' => ['password' => 'a', 'confirm' => 'b']])->errors();

        $this->assertSame('The confirm field must match the password.', $errors['v.confirm'][0]->message);
    }

    public function testShapeRefusesAComparisonNamingAKeyItDoesNotDeclare(): void
    {
        $this->assertSame(
            'Field "confirm" compares with "password", which has no rule.',
            $this->assertThrows(
                InvalidArgumentException::class,
                static fn (): mixed => Rule::shape(['confirm' => Rule::string()->same('password')]),
            )->getMessage(),
        );
    }

    // ---------------------------------------------------------------
    // Nesting
    // ---------------------------------------------------------------

    public function testAListOfShapesKeysTheErrorByTheWholePath(): void
    {
        $rule   = Rule::list(Rule::shape(['price' => Rule::int()]));
        $errors = new Validator(['items' => $rule])->validate(['items' => [['price' => 1], ['price' => 'x']]])->errors();

        $this->assertSame(['items.1.price'], array_keys($errors));
        self::assertErrorSame(
            new ValidationError('int', [], 'The price field must be an integer.'),
            $errors['items.1.price'][0],
        );
    }

    public function testAShapeOfListsKeysTheErrorByTheWholePath(): void
    {
        $rule   = Rule::shape(['tags' => Rule::list(Rule::int())]);
        $errors = new Validator(['v' => $rule])->validate(['v' => ['tags' => ['x']]])->errors();

        $this->assertSame(['v.tags.0'], array_keys($errors));
    }

    public function testNestingHasNoDeclaredLimit(): void
    {
        $rule   = Rule::list(Rule::list(Rule::list(Rule::int())));
        $errors = new Validator(['v' => $rule])->validate(['v' => [[[1, 'x']]]])->errors();

        $this->assertSame(['v.0.0.1'], array_keys($errors));
    }

    public function testTheLabelOfAnElementReplacesItsStepOfThePath(): void
    {
        $rule   = Rule::list(Rule::shape(['price' => Rule::int()->label('unit price')]))->label('order lines');
        $errors = new Validator(['items' => $rule])->validate(['items' => [['price' => 'x']]])->errors();

        $this->assertSame(['items.0.price'], array_keys($errors));
        $this->assertSame('The unit price field must be an integer.', $errors['items.0.price'][0]->message);
    }

    public function testTheLabelOfAListElementNamesItInTheMessage(): void
    {
        $rule   = Rule::list(Rule::string()->label('tag')->minLength(3));
        $errors = new Validator(['v' => $rule])->validate(['v' => ['ok!', 'ab']])->errors();

        $this->assertSame(['v.1'], array_keys($errors));
        $this->assertSame('The tag field must be at least 3 characters.', $errors['v.1'][0]->message);
    }

    public function testAListElementWithoutALabelIsNamedByItsIndex(): void
    {
        $errors = new Validator(['v' => Rule::list(Rule::int())])->validate(['v' => ['x']])->errors();

        $this->assertSame('The 0 field must be an integer.', $errors['v.0'][0]->message);
    }

    public function testTheMessageOfAnElementAppliesToItsOwnFailures(): void
    {
        $rule   = Rule::list(Rule::int()->message('Every quantity has to be a whole number.'));
        $errors = new Validator(['v' => $rule])->validate(['v' => ['x']])->errors();

        $this->assertSame('Every quantity has to be a whole number.', $errors['v.0'][0]->message);
    }

    public function testAShapeRefusesAKeyThatIsNotAName(): void
    {
        $this->assertSame(
            'shape() needs named keys, and 0 is a number: PHP turns a numeric key into an int, which cannot name a field.',
            $this->assertThrows(
                InvalidArgumentException::class,
                static fn (): mixed => Rule::shape(['0' => Rule::int()]),
            )->getMessage(),
        );
    }

    public function testAnElementOfAListCannotCompareWithAnotherField(): void
    {
        $this->assertSame(
            'The element of list() cannot compare with another field: there is no set of siblings to name.',
            $this->assertThrows(
                InvalidArgumentException::class,
                static fn (): mixed => Rule::list(Rule::string()->same('other')),
            )->getMessage(),
        );
    }

    public function testTheMessageOfOneRuleWinsOverTheMessageOfTheElement(): void
    {
        $rule   = Rule::list(Rule::int()->min(10, 'At least ten.')->message('Not a whole number.'));
        $errors = new Validator(['v' => $rule])->validate(['v' => [1]])->errors();

        $this->assertSame('At least ten.', $errors['v.0'][0]->message);
    }

    public function testAShapeHoldsEveryDeclaredKeyWhetherOrNotItCameIn(): void
    {
        $rule = Rule::shape(['a' => Rule::int(), 'b' => Rule::int()]);

        $this->assertSame(['a' => 1, 'b' => null], self::valueOf($rule, ['a' => 1]));
    }

    public function testAKeyOfAShapeThatWasNotSubmittedTakesItsDeclaredDefault(): void
    {
        $rule = Rule::shape(['a' => Rule::int(), 'b' => Rule::int()->default(0)]);

        $this->assertSame(['a' => 1, 'b' => 0], self::valueOf($rule, ['a' => 1]));
    }

    public function testTheDefaultOfAListMustBeAList(): void
    {
        $this->assertSame(
            'The default of list() must be a list, and this one has other keys.',
            $this->assertThrows(
                InvalidArgumentException::class,
                static fn (): mixed => Rule::list(Rule::int())->default(['k' => 1]),
            )->getMessage(),
        );
    }

    public function testTheDefaultOfAShapeFillsInTheKeysItLeavesOut(): void
    {
        $rule = Rule::shape(['a' => Rule::int(), 'b' => Rule::int()])->default(['a' => 1]);

        $this->assertSame(['a' => 1, 'b' => null], self::valueOf($rule, null));
    }

    public function testAKeyLeftOutOfTheDefaultTakesItsOwnDefault(): void
    {
        $rule = Rule::shape(['a' => Rule::int(), 'b' => Rule::int()->default(0)])->default(['a' => 1]);

        $this->assertSame(['a' => 1, 'b' => 0], self::valueOf($rule, null));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function defaultsSayingNothing(): iterable
    {
        yield 'key left out' => [[]];
        yield 'key set to null' => [['a' => null]];
        yield 'key set to the empty string' => [['a' => '']];
    }

    /**
     * @param array<string, mixed> $default
     */
    #[DataProvider('defaultsSayingNothing')]
    public function testADefaultSayingNothingForARequiredKeyIsRefused(array $default): void
    {
        $this->assertSame(
            'The default of shape() has no value for "a", which is required.',
            $this->assertThrows(
                InvalidArgumentException::class,
                static fn (): mixed => Rule::shape(['a' => Rule::string()->required()])->default($default),
            )->getMessage(),
        );
    }

    /**
     * @param array<string, mixed> $default
     */
    #[DataProvider('defaultsSayingNothing')]
    public function testADefaultSayingNothingForAKeyGivesWhatValidatingWouldHaveGiven(array $default): void
    {
        $fields = ['a' => Rule::string()->default('d'), 'b' => Rule::string()];

        $viaDefault = self::valueOf(Rule::shape($fields)->default($default), null);
        $viaInput   = self::valueOf(Rule::shape($fields), $default);

        $this->assertSame($viaInput, $viaDefault);
        $this->assertSame(['a' => 'd', 'b' => null], $viaDefault);
    }

    public function testTheDefaultOfAShapeMayHoldTheKeysThatAreRequired(): void
    {
        $rule = Rule::shape([
            'a' => Rule::string(),
            'b' => Rule::string()->required(),
        ])->default(['a' => 'x', 'b' => 'y']);

        $this->assertSame(['a' => 'x', 'b' => 'y'], self::valueOf($rule, null));
    }

    public function testADefaultKeepsTheValuesThatOnlyLookEmpty(): void
    {
        $rule = Rule::shape(['a' => Rule::bool(), 'b' => Rule::int()])->default(['a' => false, 'b' => 0]);

        $this->assertSame(['a' => false, 'b' => 0], self::valueOf($rule, null));
    }

    public function testTheDefaultOfAShapeCannotHoldAnUndeclaredKey(): void
    {
        $this->assertSame(
            'The default of shape() holds keys it does not declare: zzz.',
            $this->assertThrows(
                InvalidArgumentException::class,
                static fn (): mixed => Rule::shape(['a' => Rule::int()])->default(['zzz' => 1]),
            )->getMessage(),
        );
    }

    // ---------------------------------------------------------------
    // ArraySanitize
    // ---------------------------------------------------------------

    public function testRemoveEmptyDropsNullAndTheEmptyStringAndRenumbersAList(): void
    {
        $this->assertSame(['a', 'b'], self::valueOf(Rule::array(ArraySanitize::RemoveEmpty), ['a', null, '', 'b']));
    }

    public function testRemoveEmptyKeepsTheValuesThatOnlyLookEmpty(): void
    {
        $this->assertSame([0, '0', false, []], self::valueOf(Rule::array(ArraySanitize::RemoveEmpty), [0, '0', false, []]));
    }

    public function testRemoveEmptyKeepsTheKeysOfAMap(): void
    {
        $this->assertSame(['b' => 2], self::valueOf(Rule::array(ArraySanitize::RemoveEmpty), ['a' => null, 'b' => 2]));
    }

    public function testAClosureCanSanitizeTheArray(): void
    {
        $rule = Rule::array(static fn (array $value): array => array_reverse($value));

        $this->assertSame(['b', 'a'], self::valueOf($rule, ['a', 'b']));
    }

    public function testASanitizerClosureThatDoesNotReturnAnArrayIsRefused(): void
    {
        // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
        $rule = Rule::array(static fn (array $value): mixed => 'not an array');

        $this->assertSame(
            'An array sanitizer closure must return an array, got string.',
            $this->assertThrows(
                UnexpectedValueException::class,
                static fn (): mixed => new Validator(['v' => $rule])->validate(['v' => ['a']]),
            )->getMessage(),
        );
    }

    public function testSanitizersRunInTheDeclaredOrder(): void
    {
        $rule = Rule::array(
            static fn (array $value): array => [...$value, ''],
            ArraySanitize::RemoveEmpty,
        );

        $this->assertSame(['a'], self::valueOf($rule, ['a']));
    }

    public function testASanitizerDeclaredAfterACaseStillRuns(): void
    {
        $rule = Rule::array(
            ArraySanitize::RemoveEmpty,
            static fn (array $value): array => [...$value, 'added'],
        );

        $this->assertSame(['a', 'added'], self::valueOf($rule, ['a', null]));
    }

    public function testSanitizersDoNotRunOnAValueThatIsNotAnArray(): void
    {
        $this->assertSame(['array'], self::failedRules(Rule::array(ArraySanitize::RemoveEmpty), 'no'));
    }
}
