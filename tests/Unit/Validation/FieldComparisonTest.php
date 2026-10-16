<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Support\TimeOfDay;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\DateRule;
use Sloop\Validation\DateTimeRule;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;
use Sloop\Validation\Sanitize;
use Sloop\Validation\TimeRule;
use Sloop\Validation\ValidationError;
use Sloop\Validation\ValidationMessages;
use Sloop\Validation\Validator;

final class FieldComparisonTest extends TestCase
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
     * Names of the rules that failed on the field `end`, with `start` beside it.
     *
     * @param  FieldRule<covariant mixed> $start
     * @param  FieldRule<covariant mixed> $end
     * @param  array<string, mixed>       $data
     * @return list<string>
     */
    private static function endFailures(FieldRule $start, FieldRule $end, array $data): array
    {
        $errors = new Validator(['start' => $start, 'end' => $end])->validate($data)->errors();

        return array_map(static fn (ValidationError $e): string => $e->rule, $errors['end'] ?? []);
    }

    /**
     * The rule with the order comparison named by $method against `start`.
     *
     * @return FieldRule<covariant mixed>
     */
    private static function ordered(DateRule|DateTimeRule|TimeRule $rule, string $method): FieldRule
    {
        return match ($method) {
            'afterField'         => $rule->afterField('start'),
            'afterOrEqualField'  => $rule->afterOrEqualField('start'),
            'beforeField'        => $rule->beforeField('start'),
            'beforeOrEqualField' => $rule->beforeOrEqualField('start'),
            default              => self::fail('Unknown method ' . $method . '.'),
        };
    }

    // ---------------------------------------------------------------
    // afterField() / afterOrEqualField() / beforeField() / beforeOrEqualField()
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function orderCases(): iterable
    {
        foreach (['afterField' => [false, false, true], 'afterOrEqualField' => [false, true, true], 'beforeField' => [true, false, false], 'beforeOrEqualField' => [true, true, false]] as $method => [$earlier, $equal, $later]) {
            yield $method . ' with an earlier end' => [$method, '2026-01-02', '2026-01-01', $earlier];
            yield $method . ' with the same end'   => [$method, '2026-01-02', '2026-01-02', $equal];
            yield $method . ' with a later end'    => [$method, '2026-01-02', '2026-01-03', $later];
        }
    }

    #[DataProvider('orderCases')]
    public function testDatesAreOrderedAgainstTheOtherField(string $method, string $start, string $end, bool $passes): void
    {
        $rule = self::ordered(Rule::date(), $method);

        $this->assertSame($passes ? [] : [$method], self::endFailures(Rule::date(), $rule, ['start' => $start, 'end' => $end]));
    }

    #[DataProvider('orderCases')]
    public function testDateTimesAreOrderedAgainstTheOtherField(string $method, string $start, string $end, bool $passes): void
    {
        $rule = self::ordered(Rule::dateTime(), $method);

        $data = ['start' => $start . 'T10:00:00+09:00', 'end' => $end . 'T10:00:00+09:00'];

        $this->assertSame($passes ? [] : [$method], self::endFailures(Rule::dateTime(), $rule, $data));
    }

    #[DataProvider('orderCases')]
    public function testTimesAreOrderedAgainstTheOtherField(string $method, string $start, string $end, bool $passes): void
    {
        $rule = self::ordered(Rule::time(), $method);

        // The day of the month stands in for the hour, so the three cases keep their order.
        $data = ['start' => substr($start, -2) . ':00', 'end' => substr($end, -2) . ':00'];

        $this->assertSame($passes ? [] : [$method], self::endFailures(Rule::time(), $rule, $data));
    }

    public function testDateTimesAreOrderedByTheInstantWhateverTheirOffsets(): void
    {
        $failures = self::endFailures(
            Rule::dateTime(),
            Rule::dateTime()->afterField('start'),
            ['start' => '2026-01-02T00:30:00+09:00', 'end' => '2026-01-01T16:00:00+00:00'],
        );

        $this->assertSame([], $failures);
    }

    public function testTimesAreOrderedBelowTheSecond(): void
    {
        $start = Rule::time('H:i:s.u');
        $end   = Rule::time('H:i:s.u')->afterField('start');

        $this->assertSame([], self::endFailures($start, $end, ['start' => '09:30:00.000000', 'end' => '09:30:00.000001']));
        $this->assertSame(['afterField'], self::endFailures($start, $end, ['start' => '09:30:00.500000', 'end' => '09:30:00.000000']));
    }

    public function testFieldsWritingLessComparedWithThePartsTheirFormatLeavesOut(): void
    {
        // '2026-03' is 1 March, which comes before 15 March.
        $failures = self::endFailures(Rule::date('Y-m-d'), Rule::date('Y-m')->afterOrEqualField('start'), ['start' => '2026-03-15', 'end' => '2026-03']);

        $this->assertSame(['afterOrEqualField'], $failures);
    }

    public function testTimesWritingLessComparedWithThePartsTheirFormatLeavesOut(): void
    {
        // '09:30' under H:i is 09:30:00, which comes before 09:30:30.
        $start = Rule::time('H:i:s');

        $this->assertSame(['afterField'], self::endFailures($start, Rule::time('H:i')->afterField('start'), ['start' => '09:30:30', 'end' => '09:30']));
        $this->assertSame([], self::endFailures($start, Rule::time('H:i')->beforeField('start'), ['start' => '09:30:30', 'end' => '09:30']));
    }

    public function testOrderComparisonsAreRefusedInsideWhen(): void
    {
        $changed = 'when() can only add rules on the value; the closure changed something else about the field.';
        $date    = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::date()->when('kind', 'x', static fn (DateRule $r): DateRule => $r->before(new DateTimeImmutable('2030-01-01'))->afterField('start')));
        $time    = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::time()->when('kind', 'x', static fn (TimeRule $r): TimeRule => $r->after(TimeOfDay::of(9, 0))->beforeOrEqualField('start')));

        $this->assertSame([$changed, $changed], [$date->getMessage(), $time->getMessage()]);
    }

    public function testFailureNamesTheOtherFieldByItsLabel(): void
    {
        $result = new Validator([
            'start' => Rule::date()->label('Start date'),
            'end'   => Rule::date()->afterField('start'),
        ])->validate(['start' => '2026-01-02', 'end' => '2026-01-01']);

        self::assertErrorsSame(
            ['end' => [new ValidationError('afterField', ['other' => 'start'], 'The end field must be after Start date.')]],
            $result->errors(),
        );
    }

    /**
     * @return iterable<string, array{Closure(): FieldRule<covariant mixed>, string, string}>
     */
    public static function defaultMessages(): iterable
    {
        yield 'afterField'         => [static fn (): FieldRule => Rule::date()->afterField('start'), '2026-01-01', 'The end field must be after start.'];
        yield 'afterOrEqualField'  => [static fn (): FieldRule => Rule::date()->afterOrEqualField('start'), '2026-01-01', 'The end field must not be before start.'];
        yield 'beforeField'        => [static fn (): FieldRule => Rule::date()->beforeField('start'), '2026-01-03', 'The end field must be before start.'];
        yield 'beforeOrEqualField' => [static fn (): FieldRule => Rule::date()->beforeOrEqualField('start'), '2026-01-03', 'The end field must not be after start.'];
    }

    /**
     * @param Closure(): FieldRule<covariant mixed> $rule
     */
    #[DataProvider('defaultMessages')]
    public function testEachOrderRuleHasItsOwnMessage(Closure $rule, string $end, string $message): void
    {
        $errors = new Validator(['start' => Rule::date(), 'end' => $rule()])->validate(['start' => '2026-01-02', 'end' => $end])->errors();

        $this->assertSame($message, $errors['end'][0]->message);
    }

    public function testMessageArgumentReplacesTheDefault(): void
    {
        $errors = new Validator([
            'start' => Rule::time(),
            'end'   => Rule::time()->afterField('start', message: '{label} after {other}'),
        ])->validate(['start' => '10:00', 'end' => '09:00'])->errors();

        $this->assertSame('end after start', $errors['end'][0]->message);
    }

    public function testMalformedMessageIsRefusedWhenDeclared(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::date()->afterField('start', message: '{label'));
    }

    // ---------------------------------------------------------------
    // The state of the other field
    // ---------------------------------------------------------------

    public function testComparisonIsSkippedWhenTheOtherFieldFailed(): void
    {
        $result = new Validator([
            'start' => Rule::date()->required(),
            'end'   => Rule::date()->afterField('start'),
        ])->validate(['end' => '2026-01-01']);

        $this->assertSame(['start'], array_keys($result->errors()));
    }

    public function testComparisonIsSkippedWhenTheOtherFieldIsEmptyWithoutADefault(): void
    {
        $this->assertSame([], self::endFailures(Rule::date(), Rule::date()->afterField('start'), ['end' => '2026-01-01']));
        $this->assertSame([], self::endFailures(Rule::time(), Rule::time()->beforeField('start'), ['end' => '09:00']));
        $this->assertSame([], self::endFailures(Rule::string(), Rule::string()->notContainsField('start'), ['end' => 'x']));
    }

    public function testComparisonUsesTheDefaultOfAnEmptyOtherField(): void
    {
        $start = Rule::date()->default(new DateTimeImmutable('2026-01-02'));

        $this->assertSame(['afterField'], self::endFailures($start, Rule::date()->afterField('start'), ['end' => '2026-01-01']));
        $this->assertSame([], self::endFailures($start, Rule::date()->afterField('start'), ['end' => '2026-01-03']));
        $this->assertSame(['beforeField'], self::endFailures(Rule::time()->default(TimeOfDay::of(9, 0)), Rule::time()->beforeField('start'), ['end' => '10:00']));
        $this->assertSame(['notContainsField'], self::endFailures(Rule::string()->default('adm'), Rule::string()->notContainsField('start'), ['end' => 'xadmx']));
    }

    public function testComparisonIsSkippedWhenThisFieldIsEmpty(): void
    {
        $this->assertSame([], self::endFailures(Rule::date(), Rule::date()->afterField('start'), ['start' => '2026-01-02']));
    }

    public function testComparisonIsSkippedWhenThisFieldFellBackToItsDefault(): void
    {
        $end = Rule::date()->default(new DateTimeImmutable('2026-01-01'))->afterField('start');

        $this->assertSame([], self::endFailures(Rule::date(), $end, ['start' => '2026-01-02']));
    }

    public function testComparisonRunsAlongsideTheFieldsOtherFailures(): void
    {
        $end = Rule::date()->before(new DateTimeImmutable('2026-01-01'))->afterField('start');

        $this->assertSame(['before', 'afterField'], self::endFailures(Rule::date(), $end, ['start' => '2026-01-10', 'end' => '2026-01-05']));
    }

    public function testFieldComparisonsWorkBetweenTheKeysOfAShape(): void
    {
        $validator = new Validator(['period' => Rule::shape([
            'start' => Rule::date(),
            'end'   => Rule::date()->afterField('start'),
        ])]);

        $this->assertFalse($validator->validate(['period' => ['start' => '2026-01-01', 'end' => '2026-01-02']])->failed());
        $this->assertSame(['period.end'], array_keys($validator->validate(['period' => ['start' => '2026-01-02', 'end' => '2026-01-01']])->errors()));
    }

    // ---------------------------------------------------------------
    // notContainsField()
    // ---------------------------------------------------------------

    public function testNotContainsFieldFailsWhenTheValueContainsTheOtherField(): void
    {
        $validator = new Validator([
            'username' => Rule::string()->label('User name'),
            'password' => Rule::string()->notContainsField('username'),
        ]);

        self::assertErrorsSame(
            ['password' => [new ValidationError('notContainsField', ['other' => 'username'], 'The password field must not contain User name.')]],
            $validator->validate(['username' => 'alice', 'password' => 'xalicex'])->errors(),
        );
        $this->assertFalse($validator->validate(['username' => 'alice', 'password' => 'xAlicex'])->failed());
        $this->assertFalse($validator->validate(['username' => 'alice', 'password' => 'alic'])->failed());
    }

    public function testNotContainsFieldComparesWithTheSanitizedValueOfTheOtherField(): void
    {
        $failures = self::endFailures(Rule::string(Sanitize::Trim), Rule::string()->notContainsField('start'), ['start' => '  bob  ', 'end' => 'xbobx']);

        $this->assertSame(['notContainsField'], $failures);
    }

    public function testNotContainsFieldIgnoresCaseWhenAsked(): void
    {
        $failures = self::endFailures(Rule::string(), Rule::string()->notContainsField('start', ignoreCase: true), ['start' => 'alice', 'end' => 'xALICEx']);

        $this->assertSame(['notContainsField'], $failures);
    }

    public function testNotContainsFieldMatchesTheWayNotContainsDoes(): void
    {
        // A decomposed é holds the precomposed one, and a combining mark after it does not hide it.
        $this->assertSame(['notContainsField'], self::endFailures(Rule::string(), Rule::string()->notContainsField('start'), ['start' => "r\u{E9}sum\u{E9}", 'end' => "re\u{301}sume\u{301}"]));
        $this->assertSame(['notContainsField'], self::endFailures(Rule::string(), Rule::string()->notContainsField('start'), ['start' => 'admin', 'end' => "admin\u{303}"]));
        $this->assertSame([], self::endFailures(Rule::string(), Rule::string()->notContainsField('start'), ['start' => 'e', 'end' => "\u{E9}"]));
    }

    public function testNotContainsFieldPassesWhenTheOtherFieldDefaultsToAnEmptyString(): void
    {
        $this->assertSame([], self::endFailures(Rule::string()->default(''), Rule::string()->notContainsField('start'), ['end' => 'x']));
    }

    public function testNotContainsFieldFailsUnsearchedWhenTheOtherFieldHasALongRunOfCombiningMarks(): void
    {
        $longest = 'a' . str_repeat("\u{301}", 30);

        $this->assertSame([], self::endFailures(Rule::string(), Rule::string()->notContainsField('start'), ['start' => $longest, 'end' => 'x']));
        $this->assertSame(['notContainsField'], self::endFailures(Rule::string(), Rule::string()->notContainsField('start'), ['start' => $longest . "\u{301}", 'end' => 'x']));
    }

    public function testNotContainsFieldChecksItsOwnValueForALongRunOfCombiningMarks(): void
    {
        $failures = self::endFailures(Rule::string(), Rule::string()->notContainsField('start'), ['start' => 'b', 'end' => 'a' . str_repeat("\u{301}", 31)]);

        $this->assertSame(['combiningRun'], $failures);
    }

    public function testNotContainsFieldMessageArgumentReplacesTheDefault(): void
    {
        $errors = new Validator([
            'start' => Rule::string(),
            'end'   => Rule::string()->notContainsField('start', message: 'no {other}'),
        ])->validate(['start' => 'a', 'end' => 'a'])->errors();

        $this->assertSame('no start', $errors['end'][0]->message);
    }

    // ---------------------------------------------------------------
    // Declarations refused
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{Closure(): FieldRule<covariant mixed>, string}>
     */
    public static function selfComparisons(): iterable
    {
        yield 'same'               => [static fn (): FieldRule => Rule::string()->same('v'), 'same'];
        yield 'different'          => [static fn (): FieldRule => Rule::string()->different('v'), 'different'];
        yield 'afterField'         => [static fn (): FieldRule => Rule::date()->afterField('v'), 'afterField'];
        yield 'afterOrEqualField'  => [static fn (): FieldRule => Rule::dateTime()->afterOrEqualField('v'), 'afterOrEqualField'];
        yield 'beforeField'        => [static fn (): FieldRule => Rule::time()->beforeField('v'), 'beforeField'];
        yield 'beforeOrEqualField' => [static fn (): FieldRule => Rule::date()->beforeOrEqualField('v'), 'beforeOrEqualField'];
        yield 'notContainsField'   => [static fn (): FieldRule => Rule::string()->notContainsField('v'), 'notContainsField'];
    }

    /**
     * @param Closure(): FieldRule<covariant mixed> $rule
     */
    #[DataProvider('selfComparisons')]
    public function testComparingAFieldWithItselfIsRefused(Closure $rule, string $method): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator(['v' => $rule()]));

        $this->assertSame('Field "v" compares with itself in ' . $method . '(), which gives the same result for every input.', $e->getMessage());
    }

    public function testComparingAKeyOfAShapeWithItselfIsRefused(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::shape(['a' => Rule::string()->same('a')]));
    }

    public function testComparingAFieldAddedWithWithWithItselfIsRefused(): void
    {
        $base = new Validator(['a' => Rule::string()]);

        $this->assertThrows(InvalidArgumentException::class, static fn () => $base->with('b', Rule::string()->different('b')));
    }

    /**
     * @return iterable<string, array{FieldRule<covariant mixed>, FieldRule<covariant mixed>, string}>
     */
    public static function mismatchedTypes(): iterable
    {
        yield 'date against dateTime'   => [Rule::date()->afterField('start'), Rule::dateTime(), 'afterField(), which needs a date() field'];
        yield 'dateTime against date'   => [Rule::dateTime()->beforeField('start'), Rule::date(), 'beforeField(), which needs a dateTime() field'];
        yield 'time against date'       => [Rule::time()->afterOrEqualField('start'), Rule::date(), 'afterOrEqualField(), which needs a time() field'];
        yield 'date against string'     => [Rule::date()->beforeOrEqualField('start'), Rule::string(), 'beforeOrEqualField(), which needs a date() field'];
        yield 'string against int'      => [Rule::string()->notContainsField('start'), Rule::int(), 'notContainsField(), which needs a string() field'];
        yield 'string against date'     => [Rule::string()->notContainsField('start'), Rule::date(), 'notContainsField(), which needs a string() field'];
        yield 'date against file'       => [Rule::date()->afterField('start'), Rule::file(), 'afterField(), which needs a date() field'];
    }

    /**
     * @param FieldRule<covariant mixed> $end
     * @param FieldRule<covariant mixed> $start
     */
    #[DataProvider('mismatchedTypes')]
    public function testComparingWithAFieldOfAnotherTypeIsRefused(FieldRule $end, FieldRule $start, string $message): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator(['start' => $start, 'end' => $end]));

        $this->assertSame('Field "end" compares with "start" in ' . $message . '.', $e->getMessage());
    }

    public function testComparingWithAFieldWithoutRulesIsRefused(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => new Validator(['end' => Rule::date()->afterField('start')]));

        $this->assertSame('Field "end" compares with "start", which has no rule.', $e->getMessage());
    }

    public function testFieldComparisonsAreRefusedOnTheElementsOfAList(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::list(Rule::date()->afterField('start')));
    }
}
