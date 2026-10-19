<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use BcMath\Number;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Support\TimeOfDay;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Validation\Stub\Payment;
use Sloop\Tests\Unit\Validation\Stub\Recorder;
use Sloop\Tests\Unit\Validation\Stub\UserDirectory;
use Sloop\Validation\DecimalRule;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;
use Sloop\Validation\Sanitize;
use Sloop\Validation\StringRule;
use Sloop\Validation\ValidationError;
use Sloop\Validation\ValidationMessages;
use Sloop\Validation\Validator;
use UnexpectedValueException;

final class UsingTest extends TestCase
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
     * @param FieldRule<covariant mixed> $rule
     */
    private static function onlyErrorAt(FieldRule $rule, mixed $input, string $key): ValidationError
    {
        $errors = new Validator(['v' => $rule])->validate(['v' => $input])->errors();
        self::assertSame([$key], array_keys($errors));
        self::assertCount(1, $errors[$key]);

        return $errors[$key][0];
    }

    // -------------------------------------------------------
    // Outcome
    // -------------------------------------------------------

    public function testUsingPassesAValueTheClosureAccepts(): void
    {
        $rule = Rule::string()->using('even', static fn (string $v): bool => \strlen($v) % 2 === 0, message: 'odd');

        self::assertSame('ab', self::valueOf($rule, 'ab'));
    }

    public function testUsingFailsUnderItsNameWithNoParameters(): void
    {
        $rule = Rule::string()->label('Code')->using('even', static fn (string $v): bool => \strlen($v) % 2 === 0, message: '{label} must have an even length.');

        self::assertErrorSame(new ValidationError('even', [], 'Code must have an even length.'), self::onlyError($rule, 'abc'));
    }

    public function testUsingTakesAServiceMethodAsAFirstClassCallable(): void
    {
        $users = new UserDirectory(['alice']);
        $rule  = Rule::string()->using('known', $users->exists(...), message: 'unknown');

        self::assertSame([], self::failedRules($rule, 'alice'));
        self::assertSame(['known'], self::failedRules($rule, 'bob'));
        self::assertSame(['alice', 'bob'], $users->asked);
    }

    public function testUsingTakesAStaticMethodAsAFirstClassCallable(): void
    {
        $rule = Rule::string()->using('notReserved', UserDirectory::isNotReserved(...), message: 'reserved');

        self::assertSame(['notReserved'], self::failedRules($rule, 'admin'));
        self::assertSame([], self::failedRules($rule, 'alice'));
    }

    // -------------------------------------------------------
    // The value it receives
    // -------------------------------------------------------

    public function testUsingReceivesTheSanitizedValue(): void
    {
        $seen = new Recorder(true);
        $rule = Rule::string(Sanitize::Trim)->using('seen', $seen->test(...), message: 'x');

        self::valueOf($rule, '  abc  ');

        self::assertSame(['abc'], $seen->seen);
    }

    public function testUsingReceivesTheValueOfTheDeclaredType(): void
    {
        $seen = new Recorder(true);
        $rule = Rule::int()->using('seen', $seen->test(...), message: 'x');

        self::valueOf($rule, '042');

        self::assertSame([42], $seen->seen);
    }

    public function testUsingReceivesADecimalAsValuesHandsItOut(): void
    {
        $seen = new Recorder(true);
        $rule = Rule::decimal(8, 2)->using('seen', $seen->test(...), message: 'x');

        self::valueOf($rule, '12.5');

        self::assertSame(['12.50'], $seen->seen);
    }

    public function testUsingReceivesTheNumberOfADecimalDeclaredAsNumber(): void
    {
        $seen = new Recorder(true);
        $rule = Rule::decimal(8, 2)->asNumber()->using('seen', $seen->test(...), message: 'x');

        self::valueOf($rule, '12.5');

        self::assertCount(1, $seen->seen);
        self::assertInstanceOf(Number::class, $seen->seen[0]);
        self::assertSame('12.50', (string) $seen->seen[0]);
    }

    public function testUsingReceivesADateTimeInUtc(): void
    {
        $seen = new Recorder(true);
        $rule = Rule::dateTime()->using('seen', $seen->test(...), message: 'x');

        self::valueOf($rule, '2026-10-18T10:00:00+09:00');

        self::assertCount(1, $seen->seen);
        self::assertInstanceOf(DateTimeImmutable::class, $seen->seen[0]);
        self::assertSame('2026-10-18T01:00:00+00:00', $seen->seen[0]->format(DATE_ATOM));
    }

    public function testUsingAddedByWhenReceivesTheValueAsValuesHandsItOut(): void
    {
        $seen  = new Recorder(true);
        $rules = [
            'kind'   => Rule::string(),
            'amount' => Rule::decimal(8, 2)->when('kind', 'x', static fn (DecimalRule $r): DecimalRule => $r->using('seen', $seen->test(...), message: 'x')),
        ];

        new Validator($rules)->validate(['kind' => 'x', 'amount' => '3']);

        self::assertSame(['3.00'], $seen->seen);
    }

    public function testUsingOnAListReceivesTheValidatedElements(): void
    {
        $seen = new Recorder(true);
        $rule = Rule::list(Rule::int())->using('seen', $seen->test(...), message: 'x');

        self::valueOf($rule, ['1', '02']);

        self::assertSame([[1, 2]], $seen->seen);
    }

    public function testUsingOnAnElementRunsForEachElement(): void
    {
        $rule = Rule::list(Rule::int()->using('positive', static fn (int $v): bool => $v > 0, message: 'x'));

        $errors = new Validator(['v' => $rule])->validate(['v' => ['1', '-2', '3']])->errors();

        self::assertSame(['v.1'], array_keys($errors));
        self::assertSame('positive', $errors['v.1'][0]->rule);
    }

    public function testUsingOnAShapeReceivesTheValidatedKeys(): void
    {
        $seen = new Recorder(true);
        $rule = Rule::shape(['n' => Rule::int()])->using('seen', $seen->test(...), message: 'x');

        self::valueOf($rule, ['n' => '7']);

        self::assertSame([['n' => 7]], $seen->seen);
    }

    // -------------------------------------------------------
    // When it does not run
    // -------------------------------------------------------

    /**
     * @return array<string, array{mixed}>
     */
    public static function emptyInputs(): array
    {
        return [
            'null'         => [null],
            'empty string' => [''],
        ];
    }

    #[DataProvider('emptyInputs')]
    public function testUsingDoesNotRunOnAnEmptyValue(mixed $input): void
    {
        $seen = new Recorder(false);
        $rule = Rule::string()->using('seen', $seen->test(...), message: 'x');

        self::assertNull(self::valueOf($rule, $input));
        self::assertSame([], $seen->seen);
    }

    public function testUsingDoesNotRunOnTheDefault(): void
    {
        $seen = new Recorder(false);
        $rule = Rule::string()->default('fallback')->using('seen', $seen->test(...), message: 'x');

        self::assertSame('fallback', self::valueOf($rule, null));
        self::assertSame([], $seen->seen);
    }

    public function testUsingDoesNotRunAfterTheTypeCheckFails(): void
    {
        $seen = new Recorder(false);
        $rule = Rule::int()->using('seen', $seen->test(...), message: 'x');

        self::assertSame(['int'], self::failedRules($rule, 'abc'));
        self::assertSame([], $seen->seen);
    }

    public function testUsingDoesNotRunAfterASizeRuleFails(): void
    {
        $seen = new Recorder(false);
        $rule = Rule::list(Rule::string()->using('seen', $seen->test(...), message: 'x'))->maxCount(2);

        self::assertSame(['maxCount'], self::failedRules($rule, ['a', 'b', 'c']));
        self::assertSame([], $seen->seen);
    }

    public function testUsingDoesNotRunWhileAnotherRuleOfTheFieldFails(): void
    {
        $seen = new Recorder(false);
        $rule = Rule::string()->email()->using('seen', $seen->test(...), message: 'x');

        self::assertSame(['email'], self::failedRules($rule, 'not-an-email'));
        self::assertSame([], $seen->seen);
    }

    public function testUsingDoesNotRunWhileAnElementFails(): void
    {
        $seen = new Recorder(false);
        $rule = Rule::list(Rule::int())->using('seen', $seen->test(...), message: 'x');

        $errors = new Validator(['v' => $rule])->validate(['v' => ['1', 'x']])->errors();

        self::assertSame(['v.1'], array_keys($errors));
        self::assertSame([], $seen->seen);
    }

    public function testUsingDoesNotRunWhileARuleOnTheElementsFails(): void
    {
        $seen = new Recorder(false);
        $rule = Rule::list(Rule::int())->distinct()->using('seen', $seen->test(...), message: 'x');

        self::assertSame(['distinct'], self::failedRules($rule, ['1', '1']));
        self::assertSame([], $seen->seen);
    }

    public function testUsingRunsWhenEveryOtherRuleOfTheFieldPasses(): void
    {
        $seen = new Recorder(false);
        $rule = Rule::string()->email()->using('seen', $seen->test(...), message: 'x');

        self::assertSame(['seen'], self::failedRules($rule, 'a@example.com'));
        self::assertSame(['a@example.com'], $seen->seen);
    }

    public function testSeveralUsingOnOneFieldDoNotStopOneAnother(): void
    {
        $rule = Rule::string()
            ->using('first', static fn (string $v): bool => false, message: 'x')
            ->using('second', static fn (string $v): bool => false, message: 'y');

        self::assertSame(['first', 'second'], self::failedRules($rule, 'a'));
    }

    // -------------------------------------------------------
    // when()
    // -------------------------------------------------------

    public function testUsingCanBeAddedByWhen(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()->when('kind', 'user', static fn (StringRule $r): StringRule => $r->using('known', static fn (string $v): bool => $v === 'alice', message: 'x')),
        ];

        self::assertSame(['name'], array_keys(new Validator($rules)->validate(['kind' => 'user', 'name' => 'bob'])->errors()));
        self::assertSame([], new Validator($rules)->validate(['kind' => 'team', 'name' => 'bob'])->errors());
    }

    public function testUsingAddedByWhenDoesNotRunWhileARuleAddedByTheSameWhenFails(): void
    {
        $seen  = new Recorder(false);
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()->when('kind', 'user', static fn (StringRule $r): StringRule => $r->in(['alice'])->using('seen', $seen->test(...), message: 'x')),
        ];

        $errors = new Validator($rules)->validate(['kind' => 'user', 'name' => 'bob'])->errors();

        self::assertSame(['in'], array_map(static fn (ValidationError $e): string => $e->rule, $errors['name']));
        self::assertSame([], $seen->seen);
    }

    public function testUsingAddedByWhenIsNotStoppedByARuleAddedByAnotherWhen(): void
    {
        $seen  = new Recorder(true);
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()
                ->when('kind', 'user', static fn (StringRule $r): StringRule => $r->in(['alice']))
                ->when('kind', 'user', static fn (StringRule $r): StringRule => $r->using('seen', $seen->test(...), message: 'x')),
        ];

        $errors = new Validator($rules)->validate(['kind' => 'user', 'name' => 'bob'])->errors();

        self::assertSame(['in'], array_map(static fn (ValidationError $e): string => $e->rule, $errors['name']));
        self::assertSame(['bob'], $seen->seen);
    }

    public function testUsingAddedByWhenDoesNotRunWhileARuleOfTheFieldItselfFails(): void
    {
        $seen  = new Recorder(false);
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()->maxLength(2)->when('kind', 'user', static fn (StringRule $r): StringRule => $r->using('seen', $seen->test(...), message: 'x')),
        ];

        $errors = new Validator($rules)->validate(['kind' => 'user', 'name' => 'bob'])->errors();

        self::assertSame(['maxLength'], array_map(static fn (ValidationError $e): string => $e->rule, $errors['name']));
        self::assertSame([], $seen->seen);
    }

    public function testUsingAddedByWhenIsNotStoppedByAUsingOnTheFieldItself(): void
    {
        $seen  = new Recorder(true);
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()
                ->using('direct', static fn (string $v): bool => false, message: 'x')
                ->when('kind', 'user', static fn (StringRule $r): StringRule => $r->using('inWhen', $seen->test(...), message: 'y')),
        ];

        $errors = new Validator($rules)->validate(['kind' => 'user', 'name' => 'bob'])->errors();

        self::assertSame(['direct'], array_map(static fn (ValidationError $e): string => $e->rule, $errors['name']));
        self::assertSame(['bob'], $seen->seen);
    }

    public function testUsingIsNotStoppedByAComparisonWithAnotherField(): void
    {
        $seen  = new Recorder(false);
        $rules = [
            'password' => Rule::string(),
            'confirm'  => Rule::string()->same('password')->using('seen', $seen->test(...), message: 'x'),
        ];

        $errors = new Validator($rules)->validate(['password' => 'a', 'confirm' => 'b'])->errors();

        self::assertSame(['seen', 'same'], array_map(static fn (ValidationError $e): string => $e->rule, $errors['confirm']));
        self::assertSame(['b'], $seen->seen);
    }

    public function testUsingOnTheFieldItselfIsNotStoppedByARuleAddedByWhen(): void
    {
        $rules = [
            'kind' => Rule::string(),
            'name' => Rule::string()
                ->using('known', static fn (string $v): bool => false, message: 'x')
                ->when('kind', 'user', static fn (StringRule $r): StringRule => $r->in(['alice'])),
        ];

        $errors = new Validator($rules)->validate(['kind' => 'user', 'name' => 'bob'])->errors();

        self::assertSame(['known', 'in'], array_map(static fn (ValidationError $e): string => $e->rule, $errors['name']));
    }

    // -------------------------------------------------------
    // Messages
    // -------------------------------------------------------

    public function testUsingTakesTheFieldMessage(): void
    {
        $rule = Rule::string()->message('Bad {label}.')->using('known', static fn (string $v): bool => false);

        self::assertSame('Bad v.', self::onlyError($rule, 'a')->message);
    }

    /**
     * @return iterable<string, array{Closure(): FieldRule<covariant mixed>}>
     */
    public static function usingWithoutAnyMessage(): iterable
    {
        yield 'on the field' => [static fn (): FieldRule => Rule::string()->using('nameTaken', static fn (string $v): bool => false)];
        yield 'on a field that also has a when()' => [static fn (): FieldRule => Rule::string()->using('nameTaken', static fn (string $v): bool => false)->when('v', 'x', static fn (StringRule $r): StringRule => $r->in(['a']))];
        yield 'added by when()' => [static fn (): FieldRule => Rule::string()->when('v', 'x', static fn (StringRule $r): StringRule => $r->using('nameTaken', static fn (string $v): bool => false))];
        yield 'on an element' => [static fn (): FieldRule => Rule::list(Rule::string()->using('nameTaken', static fn (string $v): bool => false))];
        yield 'on a key' => [static fn (): FieldRule => Rule::shape(['k' => Rule::string()->using('nameTaken', static fn (string $v): bool => false)])];
        yield 'on a key of an element' => [static fn (): FieldRule => Rule::list(Rule::shape(['k' => Rule::string()->using('nameTaken', static fn (string $v): bool => false)]))];
    }

    /**
     * @param Closure(): FieldRule<covariant mixed> $declare
     */
    #[DataProvider('usingWithoutAnyMessage')]
    public function testValidatorRefusesAUsingWithoutAnyMessageWhenBuilt(Closure $declare): void
    {
        $rule = $declare();

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator(['v' => $rule]));

        self::assertSame('Field "v" has a using("nameTaken") rule without a message; give it with message: or with message() on the field.', $e->getMessage());
    }

    public function testAShapeTakesAKeyUsingWithoutAMessageWhenTheShapeGivesOne(): void
    {
        $rule = Rule::shape(['k' => Rule::string()->using('nameTaken', static fn (string $v): bool => false)])->message('Bad {label}.');

        self::assertSame('Bad k.', self::onlyErrorAt($rule, ['k' => 'a'], 'v.k')->message);
    }

    public function testAListTakesAnElementUsingWithoutAMessageWhenTheListGivesOne(): void
    {
        $rule = Rule::list(Rule::string()->using('nameTaken', static fn (string $v): bool => false))->message('Bad {label}.');

        self::assertSame('Bad 0.', self::onlyErrorAt($rule, ['a'], 'v.0')->message);
    }

    public function testAnElementTakesItsOwnMessageForItsUsing(): void
    {
        $rule = Rule::list(Rule::string()->message('Bad element.')->using('nameTaken', static fn (string $v): bool => false));

        self::assertSame('Bad element.', self::onlyErrorAt($rule, ['a'], 'v.0')->message);
    }

    public function testUsingRefusesAMalformedMessage(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::string()->using('known', static fn (string $v): bool => true, message: "'{label}'"));
    }

    // -------------------------------------------------------
    // Declaration
    // -------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function malformedNames(): array
    {
        return [
            'empty'             => [''],
            'leading digit'     => ['1known'],
            'hyphen'            => ['name-taken'],
            'dot'               => ['name.taken'],
            'trailing newline'  => ["known\n"],
            'non-ASCII letter'  => ['knöwn'],
        ];
    }

    #[DataProvider('malformedNames')]
    public function testUsingRefusesAMalformedName(string $name): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::string()->using($name, static fn (string $v): bool => true));

        self::assertSame('using() needs a rule name made of a letter followed by letters and digits, got "' . $name . '".', $e->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function frameworkNames(): array
    {
        return [
            'required'  => ['required'],
            'min'       => ['min'],
            'type name' => ['int'],
            'in'        => ['in'],
            'capitalised' => ['Required'],
            'upper case'  => ['MIN'],
            'camel case'  => ['MaxLength'],
        ];
    }

    #[DataProvider('frameworkNames')]
    public function testUsingRefusesANameOfTheFramework(string $name): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::string()->using($name, static fn (string $v): bool => true));

        self::assertSame('using() cannot take the name "' . $name . '", which a rule of the framework uses; give the rule a name of its own.', $e->getMessage());
    }

    public function testUsingAcceptsANameWithDigitsAndCapitals(): void
    {
        $rule = Rule::string()->using('isISO8601', static fn (string $v): bool => false, message: 'x');

        self::assertSame(['isISO8601'], self::failedRules($rule, 'a'));
    }

    public function testUsingLeavesTheRuleItWasCalledOnUnchanged(): void
    {
        $base = Rule::string();
        $base->using('never', static fn (string $v): bool => false, message: 'x');

        self::assertSame([], self::failedRules($base, 'a'));
    }

    // -------------------------------------------------------
    // What the closure returns or throws
    // -------------------------------------------------------

    public function testUsingRefusesAResultThatIsNotABool(): void
    {
        $rule = Rule::string()->using('known', static fn (string $v): int => 1, message: 'x');

        $e = $this->assertThrows(UnexpectedValueException::class, static fn () => new Validator(['v' => $rule])->validate(['v' => 'a']));

        self::assertSame('The closure given to using("known") must return a bool, got int.', $e->getMessage());
    }

    public function testUsingLetsAnExceptionOfTheClosurePass(): void
    {
        $thrown = new RuntimeException('database is down');
        $rule   = Rule::string()->using('known', static fn (string $v): bool => throw $thrown, message: 'x');

        $e = $this->assertThrows(RuntimeException::class, static fn () => new Validator(['v' => $rule])->validate(['v' => 'a']));

        self::assertSame($thrown, $e);
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>, mixed, Closure(mixed): bool}>
     */
    public static function valuesOfEachType(): iterable
    {
        yield 'bool' => [Rule::bool(), 'true', static fn (mixed $v): bool => $v === true];
        yield 'float' => [Rule::float(), '1.5', static fn (mixed $v): bool => $v === 1.5];
        yield 'enum' => [Rule::enum(Payment::class), 'card', static fn (mixed $v): bool => $v === Payment::Card];
        yield 'date' => [Rule::date(), '2026-10-18', static fn (mixed $v): bool => $v instanceof DateTimeImmutable && $v->format('Y-m-d') === '2026-10-18'];
        yield 'time' => [Rule::time(), '09:30', static fn (mixed $v): bool => $v instanceof TimeOfDay && $v->format('H:i') === '09:30'];
        yield 'array' => [Rule::array(), ['a' => 1], static fn (mixed $v): bool => $v === ['a' => 1]];
    }

    /**
     * @param FieldRule<covariant mixed> $rule
     * @param Closure(mixed): bool       $expected
     */
    #[DataProvider('valuesOfEachType')]
    public function testUsingReceivesTheValueOfEachTypeAsValuesHandsItOut(FieldRule $rule, mixed $input, Closure $expected): void
    {
        $seen = new Recorder(true);

        $value = self::valueOf($rule->using('seen', $seen->test(...), message: 'x'), $input);

        self::assertCount(1, $seen->seen);
        self::assertTrue($expected($seen->seen[0]));
        self::assertEquals($value, $seen->seen[0]);
    }

    public function testUsingIsNotAppliedToTheDefaultOfAList(): void
    {
        $seen = new Recorder(false);
        $rule = Rule::list(Rule::string()->using('element', $seen->test(...), message: 'x'))
            ->default(['a'])
            ->using('list', $seen->test(...), message: 'y');

        self::assertSame(['a'], self::valueOf($rule, null));
        self::assertSame([], $seen->seen);
    }

    public function testUsingIsNotAppliedToTheDefaultOfAShapeOrOfItsKeys(): void
    {
        $seen = new Recorder(false);
        $rule = Rule::shape([
            'a' => Rule::string()->default('x')->using('key', $seen->test(...), message: 'x'),
            'b' => Rule::string()->using('other', $seen->test(...), message: 'x'),
        ])->default(['b' => 'y']);

        self::assertSame(['a' => 'x', 'b' => 'y'], self::valueOf($rule, null));
        self::assertSame([], $seen->seen);
    }

    public function testUsingOnAListReceivesTheDefaultOfAnEmptyElement(): void
    {
        $seen = new Recorder(true);
        $rule = Rule::list(Rule::string()->default('z'))->using('seen', $seen->test(...), message: 'x');

        self::valueOf($rule, ['', 'a']);

        self::assertSame([['z', 'a']], $seen->seen);
    }
}
