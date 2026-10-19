<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Clock\FrozenClock;
use Sloop\Support\TimeOfDay;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Validation\Stub\Payment;
use Sloop\Validation\Chars;
use Sloop\Validation\FieldRule;
use Sloop\Validation\LengthUnit;
use Sloop\Validation\Rule;
use Sloop\Validation\ValidationMessages;
use Sloop\Validation\ValidationResult;
use Sloop\Validation\Validator;

final class ErrorFlagTest extends TestCase
{
    use ThrowsAssertions;

    protected function setUp(): void
    {
        ValidationMessages::reset();
    }

    protected function tearDown(): void
    {
        ValidationMessages::reset();
    }

    /**
     * @param array<string, FieldRule<covariant mixed>> $rules
     * @param array<string, mixed>                      $data
     */
    private static function validate(array $rules, array $data): ValidationResult
    {
        return new Validator($rules, new FrozenClock(new DateTimeImmutable('2026-10-19 12:00:00 UTC')))->validate($data);
    }

    // -------------------------------------------------------
    // Every rule taking a message takes a flag
    // -------------------------------------------------------

    /**
     * @return iterable<string, array{\Closure(): array<string, FieldRule<covariant mixed>>, array<string, mixed>, string}>
     */
    public static function flaggedRules(): iterable
    {
        $f      = 'flagged';
        $png    = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 16);
        $upload = new UploadedFile(Stream::create($png), \strlen($png), UPLOAD_ERR_OK, 'a.png', 'image/png');
        $date   = new DateTimeImmutable('2026-01-10');
        $noon   = TimeOfDay::of(12, 0);

        yield 'required' => [static fn (): array => ['v' => Rule::string()->required(flag: $f)], [], 'required'];
        yield 'same' => [static fn (): array => ['v' => Rule::string()->same('o', flag: $f), 'o' => Rule::string()], ['v' => 'a', 'o' => 'b'], 'same'];
        yield 'different' => [static fn (): array => ['v' => Rule::string()->different('o', flag: $f), 'o' => Rule::string()], ['v' => 'a', 'o' => 'a'], 'different'];
        yield 'requiredWith' => [static fn (): array => ['v' => Rule::string()->requiredWith('o', flag: $f), 'o' => Rule::string()], ['o' => 'a'], 'requiredWith'];
        yield 'requiredIf' => [static fn (): array => ['v' => Rule::string()->requiredIf('o', 'a', flag: $f), 'o' => Rule::string()], ['o' => 'a'], 'requiredIf'];
        yield 'using' => [static fn (): array => ['v' => Rule::string()->using('known', static fn (string $v): bool => false, 'x', flag: $f)], ['v' => 'a'], 'known'];

        yield 'string minLength' => [static fn (): array => ['v' => Rule::string()->minLength(3, flag: $f)], ['v' => 'ab'], 'minLength'];
        yield 'string maxLength' => [static fn (): array => ['v' => Rule::string()->maxLength(1, flag: $f)], ['v' => 'ab'], 'maxLength'];
        yield 'string exactLength' => [static fn (): array => ['v' => Rule::string()->exactLength(3, flag: $f)], ['v' => 'ab'], 'exactLength'];
        yield 'string blockSize' => [static fn (): array => ['v' => Rule::string()->blockSize(4, flag: $f)], ['v' => 'abc'], 'blockSize'];
        yield 'string regex' => [static fn (): array => ['v' => Rule::string()->regex('/\A\d+\z/', flag: $f)], ['v' => 'ab'], 'regex'];
        yield 'string in' => [static fn (): array => ['v' => Rule::string()->in(['x'], flag: $f)], ['v' => 'ab'], 'in'];
        yield 'string notIn' => [static fn (): array => ['v' => Rule::string()->notIn(['ab'], flag: $f)], ['v' => 'ab'], 'notIn'];
        yield 'string notContains' => [static fn (): array => ['v' => Rule::string()->notContains(['b'], flag: $f)], ['v' => 'ab'], 'notContains'];
        yield 'string notContainsField' => [
            static fn (): array => [
                'v' => Rule::string()->maxLength(10, unit: LengthUnit::Codepoints)->notContainsField('o', flag: $f),
                'o' => Rule::string()->maxLength(10, unit: LengthUnit::Codepoints),
            ],
            ['v' => 'abc', 'o' => 'b'],
            'notContainsField',
        ];
        yield 'string chars' => [static fn (): array => ['v' => Rule::string()->chars([Chars::Numeric], flag: $f)], ['v' => 'ab'], 'chars'];
        yield 'string notChars' => [static fn (): array => ['v' => Rule::string()->notChars(['b'], flag: $f)], ['v' => 'ab'], 'notChars'];
        yield 'string minCharClasses' => [static fn (): array => ['v' => Rule::string()->minCharClasses(2, [Chars::Alpha, Chars::Numeric], flag: $f)], ['v' => 'ab'], 'minCharClasses'];
        yield 'string email' => [static fn (): array => ['v' => Rule::string()->email(flag: $f)], ['v' => 'ab'], 'email'];
        yield 'string url' => [static fn (): array => ['v' => Rule::string()->url(flag: $f)], ['v' => 'ab'], 'url'];
        yield 'string ip' => [static fn (): array => ['v' => Rule::string()->ip(flag: $f)], ['v' => 'ab'], 'ip'];

        yield 'int min' => [static fn (): array => ['v' => Rule::int()->min(5, flag: $f)], ['v' => 1], 'min'];
        yield 'int max' => [static fn (): array => ['v' => Rule::int()->max(0, flag: $f)], ['v' => 1], 'max'];
        yield 'int between' => [static fn (): array => ['v' => Rule::int()->between(2, 3, flag: $f)], ['v' => 1], 'between'];
        yield 'int in' => [static fn (): array => ['v' => Rule::int()->in([2], flag: $f)], ['v' => 1], 'in'];
        yield 'int notIn' => [static fn (): array => ['v' => Rule::int()->notIn([1], flag: $f)], ['v' => 1], 'notIn'];

        yield 'float min' => [static fn (): array => ['v' => Rule::float()->min(5, flag: $f)], ['v' => 1.5], 'min'];
        yield 'float max' => [static fn (): array => ['v' => Rule::float()->max(0, flag: $f)], ['v' => 1.5], 'max'];
        yield 'float between' => [static fn (): array => ['v' => Rule::float()->between(2, 3, flag: $f)], ['v' => 1.5], 'between'];
        yield 'float in' => [static fn (): array => ['v' => Rule::float()->in([2.5], flag: $f)], ['v' => 1.5], 'in'];
        yield 'float notIn' => [static fn (): array => ['v' => Rule::float()->notIn([1.5], flag: $f)], ['v' => 1.5], 'notIn'];

        yield 'decimal min' => [static fn (): array => ['v' => Rule::decimal(5, 2)->min(5, flag: $f)], ['v' => '1.00'], 'min'];
        yield 'decimal max' => [static fn (): array => ['v' => Rule::decimal(5, 2)->max(0, flag: $f)], ['v' => '1.00'], 'max'];
        yield 'decimal between' => [static fn (): array => ['v' => Rule::decimal(5, 2)->between(2, 3, flag: $f)], ['v' => '1.00'], 'between'];

        yield 'enum in' => [static fn (): array => ['v' => Rule::enum(Payment::class)->in([Payment::Card], flag: $f)], ['v' => 'cash'], 'in'];
        yield 'enum notIn' => [static fn (): array => ['v' => Rule::enum(Payment::class)->notIn([Payment::Cash], flag: $f)], ['v' => 'cash'], 'notIn'];

        yield 'date before' => [static fn (): array => ['v' => Rule::date()->before($date, flag: $f)], ['v' => '2026-01-10'], 'before'];
        yield 'date beforeOrEqual' => [static fn (): array => ['v' => Rule::date()->beforeOrEqual($date, flag: $f)], ['v' => '2026-01-11'], 'beforeOrEqual'];
        yield 'date after' => [static fn (): array => ['v' => Rule::date()->after($date, flag: $f)], ['v' => '2026-01-10'], 'after'];
        yield 'date afterOrEqual' => [static fn (): array => ['v' => Rule::date()->afterOrEqual($date, flag: $f)], ['v' => '2026-01-09'], 'afterOrEqual'];
        yield 'date afterNow' => [static fn (): array => ['v' => Rule::date()->afterNow(flag: $f)], ['v' => '2026-10-19'], 'afterNow'];
        yield 'date afterOrEqualNow' => [static fn (): array => ['v' => Rule::date()->afterOrEqualNow(flag: $f)], ['v' => '2026-10-18'], 'afterOrEqualNow'];
        yield 'date beforeNow' => [static fn (): array => ['v' => Rule::date()->beforeNow(flag: $f)], ['v' => '2026-10-19'], 'beforeNow'];
        yield 'date beforeOrEqualNow' => [static fn (): array => ['v' => Rule::date()->beforeOrEqualNow(flag: $f)], ['v' => '2026-10-20'], 'beforeOrEqualNow'];
        yield 'date afterField' => [static fn (): array => ['v' => Rule::date()->afterField('o', flag: $f), 'o' => Rule::date()], ['v' => '2026-01-10', 'o' => '2026-01-10'], 'afterField'];
        yield 'date afterOrEqualField' => [static fn (): array => ['v' => Rule::date()->afterOrEqualField('o', flag: $f), 'o' => Rule::date()], ['v' => '2026-01-09', 'o' => '2026-01-10'], 'afterOrEqualField'];
        yield 'date beforeField' => [static fn (): array => ['v' => Rule::date()->beforeField('o', flag: $f), 'o' => Rule::date()], ['v' => '2026-01-10', 'o' => '2026-01-10'], 'beforeField'];
        yield 'date beforeOrEqualField' => [static fn (): array => ['v' => Rule::date()->beforeOrEqualField('o', flag: $f), 'o' => Rule::date()], ['v' => '2026-01-11', 'o' => '2026-01-10'], 'beforeOrEqualField'];

        yield 'time before' => [static fn (): array => ['v' => Rule::time()->before($noon, flag: $f)], ['v' => '12:00'], 'before'];
        yield 'time beforeOrEqual' => [static fn (): array => ['v' => Rule::time()->beforeOrEqual($noon, flag: $f)], ['v' => '12:01'], 'beforeOrEqual'];
        yield 'time after' => [static fn (): array => ['v' => Rule::time()->after($noon, flag: $f)], ['v' => '12:00'], 'after'];
        yield 'time afterOrEqual' => [static fn (): array => ['v' => Rule::time()->afterOrEqual($noon, flag: $f)], ['v' => '11:59'], 'afterOrEqual'];
        yield 'time afterField' => [static fn (): array => ['v' => Rule::time()->afterField('o', flag: $f), 'o' => Rule::time()], ['v' => '12:00', 'o' => '12:00'], 'afterField'];
        yield 'time afterOrEqualField' => [static fn (): array => ['v' => Rule::time()->afterOrEqualField('o', flag: $f), 'o' => Rule::time()], ['v' => '11:59', 'o' => '12:00'], 'afterOrEqualField'];
        yield 'time beforeField' => [static fn (): array => ['v' => Rule::time()->beforeField('o', flag: $f), 'o' => Rule::time()], ['v' => '12:00', 'o' => '12:00'], 'beforeField'];
        yield 'time beforeOrEqualField' => [static fn (): array => ['v' => Rule::time()->beforeOrEqualField('o', flag: $f), 'o' => Rule::time()], ['v' => '12:01', 'o' => '12:00'], 'beforeOrEqualField'];

        yield 'file maxSize' => [static fn (): array => ['v' => Rule::file()->maxSize(1, flag: $f)], ['v' => $upload], 'maxSize'];
        yield 'file mimeTypes' => [static fn (): array => ['v' => Rule::file()->mimeTypes(['image/gif'], flag: $f)], ['v' => $upload], 'mimeTypes'];

        yield 'list minCount' => [static fn (): array => ['v' => Rule::list(Rule::int())->minCount(2, flag: $f)], ['v' => [1]], 'minCount'];
        yield 'list maxCount' => [static fn (): array => ['v' => Rule::list(Rule::int())->maxCount(0, flag: $f)], ['v' => [1]], 'maxCount'];
        yield 'list betweenCount' => [static fn (): array => ['v' => Rule::list(Rule::int())->betweenCount(2, 3, flag: $f)], ['v' => [1]], 'betweenCount'];
        yield 'list exactCount' => [static fn (): array => ['v' => Rule::list(Rule::int())->exactCount(2, flag: $f)], ['v' => [1]], 'exactCount'];
        yield 'list distinct' => [static fn (): array => ['v' => Rule::list(Rule::int())->distinct(flag: $f)], ['v' => [1, 1]], 'distinct'];
        yield 'array distinct' => [static fn (): array => ['v' => Rule::array()->distinct(flag: $f)], ['v' => [1, 1]], 'distinct'];
    }

    /**
     * @param \Closure(): array<string, FieldRule<covariant mixed>> $rules
     * @param array<string, mixed>                                  $data
     */
    #[DataProvider('flaggedRules')]
    public function testFailedRuleRaisesItsFlag(\Closure $rules, array $data, string $rule): void
    {
        $result = self::validate($rules(), $data);

        self::assertSame([$rule], array_map(static fn ($e): string => $e->rule, $result->errors()['v'] ?? []));
        self::assertTrue($result->hasFlag('flagged'));
    }

    // -------------------------------------------------------
    // When a flag is raised
    // -------------------------------------------------------

    public function testFlagIsDownWhenItsRulePasses(): void
    {
        $result = self::validate(['v' => Rule::string()->in(['a'], flag: 'flagged')], ['v' => 'a']);

        self::assertFalse($result->failed());
        self::assertFalse($result->hasFlag('flagged'));
    }

    public function testFlagIsDownWhenRequiredFailsOnAnEmptyValue(): void
    {
        $result = self::validate(['v' => Rule::string()->required()->in(['1.0'], flag: 'invalidVersion')], []);

        self::assertTrue($result->failed());
        self::assertFalse($result->hasFlag('invalidVersion'));
    }

    public function testFlagIsDownWhenTheTypeFails(): void
    {
        $result = self::validate(['v' => Rule::int()->min(5, flag: 'flagged')], ['v' => 'abc']);

        self::assertTrue($result->failed());
        self::assertFalse($result->hasFlag('flagged'));
    }

    public function testFlagIsDownForAnEmptyValueTakingItsDefault(): void
    {
        $result = self::validate(['v' => Rule::int()->min(5, flag: 'flagged')->default(1)], ['v' => '']);

        self::assertSame(['v' => 1], $result->values());
        self::assertFalse($result->hasFlag('flagged'));
    }

    public function testOnlyTheFlagOfTheFailedRuleIsRaised(): void
    {
        $result = self::validate(
            ['v' => Rule::string()->minLength(3, flag: 'short')->in(['abc'], flag: 'unknown')],
            ['v' => 'abcd'],
        );

        self::assertFalse($result->hasFlag('short'));
        self::assertTrue($result->hasFlag('unknown'));
    }

    public function testOneFlagCanBeSharedByRulesOfSeveralFields(): void
    {
        $rules = [
            'a' => Rule::int()->min(1, flag: 'outOfRange'),
            'b' => Rule::string()->in(['x'], flag: 'outOfRange'),
        ];

        self::assertTrue(self::validate($rules, ['a' => 0, 'b' => 'x'])->hasFlag('outOfRange'));
        self::assertTrue(self::validate($rules, ['a' => 1, 'b' => 'y'])->hasFlag('outOfRange'));
        self::assertFalse(self::validate($rules, ['a' => 1, 'b' => 'x'])->hasFlag('outOfRange'));
    }

    public function testFlagOfARuleAddedByWhenIsRaisedWhenThatRuleFails(): void
    {
        $rules = [
            'v' => Rule::string()->when('t', 'corp', static fn ($r) => $r->in(['x'], flag: 'flagged')),
            't' => Rule::string(),
        ];

        self::assertTrue(self::validate($rules, ['v' => 'y', 't' => 'corp'])->hasFlag('flagged'));
        self::assertFalse(self::validate($rules, ['v' => 'y', 't' => 'person'])->hasFlag('flagged'));
    }

    public function testFlagOfAnElementRuleIsRaisedWhenAnElementFails(): void
    {
        $rules = ['v' => Rule::list(Rule::int()->min(1, flag: 'flagged'))];

        $result = self::validate($rules, ['v' => [1, 0]]);

        self::assertSame(['v.1'], array_keys($result->errors()));
        self::assertTrue($result->hasFlag('flagged'));
    }

    public function testFlagOfAShapeKeyRuleIsRaisedWhenThatKeyFails(): void
    {
        $rules = ['v' => Rule::shape(['n' => Rule::int()->min(1, flag: 'flagged')])];

        self::assertTrue(self::validate($rules, ['v' => ['n' => 0]])->hasFlag('flagged'));
        self::assertFalse(self::validate($rules, ['v' => ['n' => 1]])->hasFlag('flagged'));
    }

    public function testFlagOfAShapeNestedInAListIsDeclared(): void
    {
        $rules = ['v' => Rule::list(Rule::shape(['n' => Rule::int()->min(1, flag: 'flagged')]))];

        self::assertFalse(self::validate($rules, ['v' => [['n' => 1]]])->hasFlag('flagged'));
        self::assertTrue(self::validate($rules, ['v' => [['n' => 0]]])->hasFlag('flagged'));
    }

    public function testFlagOfTheFieldItselfIsKnownBesideTheFlagOfAWhen(): void
    {
        $rules = [
            'v' => Rule::int()->min(1, flag: 'own')->when('t', 'x', static fn ($r) => $r->max(5, flag: 'added')),
            't' => Rule::string(),
        ];

        $result = self::validate($rules, ['v' => 0]);

        self::assertTrue($result->hasFlag('own'));
        self::assertFalse($result->hasFlag('added'));
    }

    public function testFlagsOfEveryShapeKeyAreKnown(): void
    {
        $rules = ['v' => Rule::shape([
            'a' => Rule::int()->min(1, flag: 'first'),
            'b' => Rule::int()->min(1, flag: 'second'),
        ])];

        $result = self::validate($rules, ['v' => ['a' => 0, 'b' => 1]]);

        self::assertTrue($result->hasFlag('first'));
        self::assertFalse($result->hasFlag('second'));
    }

    public function testFlagDeclaredInAWhenIsKnownWhenTheWhenDoesNotApply(): void
    {
        $rules = [
            'v' => Rule::string()->when('t', 'corp', static fn ($r) => $r->in(['x'], flag: 'flagged')),
            't' => Rule::string(),
        ];

        self::assertFalse(self::validate($rules, [])->hasFlag('flagged'));
    }

    public function testFieldAddedWithWithBringsItsFlags(): void
    {
        $validator = new Validator([])->with('v', Rule::int()->min(1, flag: 'flagged'));

        self::assertTrue($validator->validate(['v' => 0])->hasFlag('flagged'));
    }

    // -------------------------------------------------------
    // Refused
    // -------------------------------------------------------

    public function testAskingForAFlagNoRuleDeclaresIsRefused(): void
    {
        $result = self::validate(['v' => Rule::string()->in(['a'], flag: 'invalidVersion')], ['v' => 'b']);

        $e = $this->assertThrows(InvalidArgumentException::class, static fn (): bool => $result->hasFlag('invalidVer'));
        self::assertSame('No rule declares the flag "invalidVer".', $e->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedFlags(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with a digit' => ['1flag'];
        yield 'holds a dot' => ['a.b'];
        yield 'holds an underscore' => ['a_b'];
        yield 'holds a trailing newline' => ["ab\n"];
    }

    #[DataProvider('malformedFlags')]
    public function testMalformedFlagNameIsRefusedWhenDeclared(string $flag): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::string()->in(['a'], flag: $flag));
        self::assertSame('A flag needs a name made of a letter followed by letters and digits, got "' . $flag . '".', $e->getMessage());
    }

    /**
     * @return iterable<string, array{\Closure(string): mixed}>
     */
    public static function flagDeclarations(): iterable
    {
        yield 'required' => [static fn (string $f) => Rule::string()->required(flag: $f)];
        yield 'same' => [static fn (string $f) => Rule::string()->same('o', flag: $f)];
        yield 'requiredWith' => [static fn (string $f) => Rule::string()->requiredWith('o', flag: $f)];
        yield 'using' => [static fn (string $f) => Rule::string()->using('known', static fn (string $v): bool => true, flag: $f)];
        yield 'size' => [static fn (string $f) => Rule::list(Rule::int())->maxCount(1, flag: $f)];
        yield 'elements' => [static fn (string $f) => Rule::list(Rule::int())->distinct(flag: $f)];
        yield 'field comparison' => [static fn (string $f) => Rule::date()->afterField('o', flag: $f)];
        yield 'time comparison' => [static fn (string $f) => Rule::time()->before(TimeOfDay::of(1, 0), flag: $f)];
        yield 'current time' => [static fn (string $f) => Rule::date()->afterNow(flag: $f)];
    }

    /**
     * @param \Closure(string): mixed $declare
     */
    #[DataProvider('flagDeclarations')]
    public function testMalformedFlagNameIsRefusedOnEveryKindOfRule(\Closure $declare): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => $declare('a.b'));
    }

    // -------------------------------------------------------
    // firstMessage()
    // -------------------------------------------------------

    public function testFirstMessageIsNullWhenValidationPassed(): void
    {
        self::assertNull(self::validate(['v' => Rule::string()], ['v' => 'a'])->firstMessage());
    }

    public function testFirstMessageIsTheFirstErrorInTheOrderOfErrors(): void
    {
        $result = self::validate(
            [
                'a' => Rule::string()->minLength(3, 'a is short')->in(['xyz'], 'a is unknown'),
                'b' => Rule::string()->required('b is missing'),
            ],
            ['a' => 'ab'],
        );

        self::assertSame(['a', 'b'], array_keys($result->errors()));
        self::assertSame('a is short', $result->firstMessage());
    }

    public function testFirstMessageFollowsTheOrderOfTheFieldsRatherThanOfTheInput(): void
    {
        $result = self::validate(
            ['a' => Rule::int(), 'b' => Rule::int()],
            ['b' => 'x', 'a' => 'y'],
        );

        self::assertSame(
            ValidationMessages::format(ValidationMessages::get('int'), ['label' => 'a']),
            $result->firstMessage(),
        );
    }
}
