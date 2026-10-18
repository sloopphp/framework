<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Sloop\Clock\FrozenClock;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\DateRule;
use Sloop\Validation\DateTimeRule;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;
use Sloop\Validation\ValidationError;
use Sloop\Validation\Validator;

final class CurrentTimeTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    private string $previousTimeZone;

    protected function setUp(): void
    {
        $this->previousTimeZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimeZone);
    }

    private static function clockAt(string $time, string $zone): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable($time, new DateTimeZone($zone)));
    }

    /**
     * Names of the rules that failed for the value, validated against the clock.
     *
     * @param  FieldRule<covariant mixed> $rule
     * @return list<string>
     */
    private static function failedAt(ClockInterface $clock, FieldRule $rule, mixed $input): array
    {
        $errors = new Validator(['v' => $rule], $clock)->validate(['v' => $input])->errors();

        return array_map(static fn (ValidationError $e): string => $e->rule, $errors['v'] ?? []);
    }

    private static function formatted(mixed $value): string
    {
        self::assertInstanceOf(DateTimeImmutable::class, $value);

        return $value->format('Y-m-d\TH:i:sP');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function listed(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * The rule with the comparison with the current time of the given name.
     */
    private static function comparedWithNow(DateRule|DateTimeRule $rule, string $method): DateRule|DateTimeRule
    {
        return match ($method) {
            'afterNow'         => $rule->afterNow(),
            'afterOrEqualNow'  => $rule->afterOrEqualNow(),
            'beforeNow'        => $rule->beforeNow(),
            'beforeOrEqualNow' => $rule->beforeOrEqualNow(),
            default            => throw new LogicException('No comparison with the current time named ' . $method . '.'),
        };
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function dateTimesAroundNow(): iterable
    {
        // Each rule on its own, against the instant itself and a microsecond either side.
        yield 'afterNow, a microsecond later' => ['afterNow', '2026-10-18T06:00:00.000001Z', []];
        yield 'afterNow, the instant' => ['afterNow', '2026-10-18T06:00:00Z', ['afterNow']];
        yield 'afterOrEqualNow, the instant' => ['afterOrEqualNow', '2026-10-18T15:00:00+09:00', []];
        yield 'afterOrEqualNow, a microsecond earlier' => ['afterOrEqualNow', '2026-10-18T05:59:59.999999Z', ['afterOrEqualNow']];
        yield 'beforeNow, a microsecond earlier' => ['beforeNow', '2026-10-18T05:59:59.999999Z', []];
        yield 'beforeNow, the instant' => ['beforeNow', '2026-10-18T06:00:00Z', ['beforeNow']];
        yield 'beforeOrEqualNow, the instant' => ['beforeOrEqualNow', '2026-10-18T06:00:00Z', []];
        yield 'beforeOrEqualNow, a microsecond later' => ['beforeOrEqualNow', '2026-10-18T06:00:00.000001Z', ['beforeOrEqualNow']];
    }

    /**
     * @param list<string> $failures
     */
    #[DataProvider('dateTimesAroundNow')]
    public function testDateTimeComparesWithTheInstantTheClockReads(string $method, string $input, array $failures): void
    {
        $clock = self::clockAt('2026-10-18T06:00:00', 'UTC');

        $this->assertSame($failures, self::failedAt($clock, self::comparedWithNow(Rule::dateTime(), $method), $input));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function datesAroundToday(): iterable
    {
        yield 'afterNow, tomorrow' => ['afterNow', '2026-10-19', []];
        yield 'afterNow, today' => ['afterNow', '2026-10-18', ['afterNow']];
        yield 'afterOrEqualNow, today' => ['afterOrEqualNow', '2026-10-18', []];
        yield 'afterOrEqualNow, yesterday' => ['afterOrEqualNow', '2026-10-17', ['afterOrEqualNow']];
        yield 'beforeNow, yesterday' => ['beforeNow', '2026-10-17', []];
        yield 'beforeNow, today' => ['beforeNow', '2026-10-18', ['beforeNow']];
        yield 'beforeOrEqualNow, today' => ['beforeOrEqualNow', '2026-10-18', []];
        yield 'beforeOrEqualNow, tomorrow' => ['beforeOrEqualNow', '2026-10-19', ['beforeOrEqualNow']];
    }

    /**
     * @param list<string> $failures
     */
    #[DataProvider('datesAroundToday')]
    public function testDateComparesWithTodayWhereTheClockReads(string $method, string $input, array $failures): void
    {
        // Late in the day, so that the time of day would put today on the wrong side if it were kept.
        $clock = self::clockAt('2026-10-18T23:30:00', 'Asia/Tokyo');

        $this->assertSame($failures, self::failedAt($clock, self::comparedWithNow(Rule::date(), $method), $input));
    }

    public function testTodayIsTheDateInTheTimeZoneOfTheClock(): void
    {
        // The same instant is 19 October in Tokyo and still 18 October in UTC.
        $rule = Rule::date()->afterOrEqualNow();

        $this->assertSame(['afterOrEqualNow'], self::failedAt(self::clockAt('2026-10-19T08:30:00', 'Asia/Tokyo'), $rule, '2026-10-18'));
        $this->assertSame([], self::failedAt(self::clockAt('2026-10-18T23:30:00', 'UTC'), $rule, '2026-10-18'));
    }

    public function testFailureOfADateNamesToday(): void
    {
        $errors = new Validator(['v' => Rule::date()->afterNow()], self::clockAt('2026-10-18T23:30:00', 'Asia/Tokyo'))
            ->validate(['v' => '2026-10-18'])
            ->errors();

        self::assertErrorsSame(
            ['v' => [new ValidationError('afterNow', ['limit' => '2026-10-18'], 'The v field must be after 2026-10-18.')]],
            $errors,
        );
    }

    public function testFailureNamesTodayAsTheDateInTheZoneOfTheClock(): void
    {
        // 08:30 on 19 October in Tokyo is still the 18th in UTC, the PHP default here.
        date_default_timezone_set('UTC');
        $error = new Validator(['v' => Rule::date()->afterNow()], self::clockAt('2026-10-19T08:30:00', 'Asia/Tokyo'))
            ->validate(['v' => '2026-10-19'])
            ->errors()['v'][0];

        $this->assertSame(['limit' => '2026-10-19'], $error->params);
    }

    public function testFailureNamesALimitAsItsDateInTheZoneOfTheClock(): void
    {
        // 23:00 UTC on 1 January is the 2nd in Tokyo and the 1st in UTC, the PHP default here.
        date_default_timezone_set('UTC');
        $error = new Validator(['v' => Rule::date()->after(new DateTimeImmutable('2026-01-01T23:00:00Z'))], self::clockAt('2026-10-18T06:00:00', 'Asia/Tokyo'))
            ->validate(['v' => '2026-01-02'])
            ->errors()['v'][0];

        $this->assertSame(['limit' => '2026-01-02'], $error->params);
    }

    public function testOneRuleFollowsTheClockFromOneValidationToTheNext(): void
    {
        $rule = Rule::date()->afterNow();

        $this->assertSame([], self::failedAt(self::clockAt('2026-10-18T06:00:00', 'UTC'), $rule, '2026-10-19'));
        $this->assertSame(['afterNow'], self::failedAt(self::clockAt('2026-10-19T06:00:00', 'UTC'), $rule, '2026-10-19'));
        // The same instant read in another zone, where it is already the next day.
        $this->assertSame([], self::failedAt(self::clockAt('2026-10-18T16:00:00', 'UTC'), $rule, '2026-10-19'));
        $this->assertSame(['afterNow'], self::failedAt(self::clockAt('2026-10-19T06:00:00', 'Pacific/Kiritimati'), $rule, '2026-10-19'));
    }

    public function testFailureOfADateTimeNamesTheInstantInUtc(): void
    {
        $errors = new Validator(['v' => Rule::dateTime()->beforeOrEqualNow()], self::clockAt('2026-10-18T15:00:00', 'Asia/Tokyo'))
            ->validate(['v' => '2026-10-18T07:00:00Z'])
            ->errors();

        self::assertErrorsSame(
            ['v' => [new ValidationError('beforeOrEqualNow', ['limit' => '2026-10-18T06:00:00+00:00'], 'The v field must not be after 2026-10-18T06:00:00+00:00.')]],
            $errors,
        );
    }

    public function testFailuresOfTheOtherRulesNameTheTimeAsWell(): void
    {
        $clock = self::clockAt('2026-10-18T06:00:00', 'UTC');
        $value = static fn (string $method, string $input): ValidationError => new Validator(['v' => self::comparedWithNow(Rule::dateTime(), $method)], $clock)
            ->validate(['v' => $input])
            ->errors()['v'][0];

        self::assertErrorSame(new ValidationError('afterNow', ['limit' => '2026-10-18T06:00:00+00:00'], 'The v field must be after 2026-10-18T06:00:00+00:00.'), $value('afterNow', '2026-10-18T05:00:00Z'));
        self::assertErrorSame(new ValidationError('afterOrEqualNow', ['limit' => '2026-10-18T06:00:00+00:00'], 'The v field must not be before 2026-10-18T06:00:00+00:00.'), $value('afterOrEqualNow', '2026-10-18T05:00:00Z'));
        self::assertErrorSame(new ValidationError('beforeNow', ['limit' => '2026-10-18T06:00:00+00:00'], 'The v field must be before 2026-10-18T06:00:00+00:00.'), $value('beforeNow', '2026-10-18T07:00:00Z'));
    }

    public function testTheClockIsReadOnceForEveryFieldOfOneInput(): void
    {
        $clock     = new class () implements ClockInterface {
            public int $reads = 0;

            public function now(): DateTimeImmutable
            {
                // Each reading a day later, so that a second reading changes the verdict.
                return new DateTimeImmutable('2026-10-18T06:00:00Z')->modify('+' . $this->reads++ . ' day');
            }
        };
        $validator = new Validator([
            'a' => Rule::date()->afterOrEqualNow(),
            'b' => Rule::date()->beforeOrEqualNow(),
        ], $clock);

        $this->assertFalse($validator->validate(['a' => '2026-10-18', 'b' => '2026-10-18'])->failed());
        $this->assertSame(1, $clock->reads);
    }

    public function testTheTimeReachesElementsKeysAndWhen(): void
    {
        $validator = new Validator([
            'list'  => Rule::list(Rule::date()->afterNow()),
            'shape' => Rule::shape(['d' => Rule::date()->afterNow()]),
            'k'     => Rule::string(),
            'when'  => Rule::date()->when('k', 'x', static fn (FieldRule $r): FieldRule => $r->afterNow()),
        ], self::clockAt('2026-10-18T06:00:00', 'UTC'));

        $errors = $validator->validate(['list' => ['2026-10-18'], 'shape' => ['d' => '2026-10-18'], 'k' => 'x', 'when' => '2026-10-18'])->errors();

        $this->assertSame(['list.0', 'shape.d', 'when'], array_keys($errors));
        $this->assertSame('afterNow', $errors['list.0'][0]->rule);
        $this->assertSame('afterNow', $errors['shape.d'][0]->rule);
        $this->assertSame('afterNow', $errors['when'][0]->rule);
    }

    public function testWithKeepsTheClock(): void
    {
        $validator = new Validator([], self::clockAt('2026-10-18T06:00:00', 'UTC'))->with('v', Rule::date()->afterNow());

        $this->assertSame(['v'], array_keys($validator->validate(['v' => '2026-10-18'])->errors()));
    }

    public function testWithoutAClockTheSystemClockIsRead(): void
    {
        $validator = new Validator(['v' => Rule::dateTime()->afterNow()]);

        $this->assertFalse($validator->validate(['v' => '2999-01-01T00:00:00Z'])->failed());
        $this->assertTrue($validator->validate(['v' => '2000-01-01T00:00:00Z'])->failed());
    }

    public function testDateIsTheStartOfTheDateInTheTimeZoneOfTheClock(): void
    {
        date_default_timezone_set('UTC');
        $result = new Validator(['v' => Rule::date()], self::clockAt('2026-10-18T06:00:00', 'Asia/Tokyo'))->validate(['v' => '2026-01-02']);

        $value = $result->values()['v'];
        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-01-02T00:00:00+09:00', $value->format('Y-m-d\TH:i:sP'));
    }

    public function testDateIsTheStartOfTheDateWhereTheClockSkipsMidnight(): void
    {
        // Chile moved to summer time at 00:00 on 14 August 2016, so that day
        // has no 00:00 and starts at 01:00, here read while the PHP default is UTC.
        date_default_timezone_set('UTC');
        $result = new Validator(['v' => Rule::date()], self::clockAt('2016-08-14T12:00:00', 'America/Santiago'))->validate(['v' => '2016-08-14']);

        $value = $result->values()['v'];
        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2016-08-14T01:00:00-03:00', $value->format('Y-m-d\TH:i:sP'));
    }

    public function testLimitDeclaredInOneTimeZoneIsReadInTheZoneOfTheValidation(): void
    {
        // 12:00 UTC on 2 January is 2 January in Tokyo, UTC and Los Angeles alike,
        // so the 2nd is never after it, wherever the rule was declared.
        date_default_timezone_set('Asia/Tokyo');
        $rule = Rule::date()->after(new DateTimeImmutable('2026-01-02T12:00:00Z'));
        date_default_timezone_set('America/Los_Angeles');

        $error = self::onlyError($rule, '2026-01-02');

        $this->assertSame(['after', ['limit' => '2026-01-02']], [$error->rule, $error->params]);
    }

    public function testLimitIsReducedToItsDateInTheZoneOfTheClock(): void
    {
        // 23:00 UTC on 1 January is already 2 January in Tokyo.
        $rule = Rule::date()->after(new DateTimeImmutable('2026-01-01T23:00:00Z'));

        // UTC first: a limit kept from the UTC validation would let the Tokyo one pass.
        $this->assertSame([], self::failedAt(self::clockAt('2026-10-18T06:00:00', 'UTC'), $rule, '2026-01-02'));
        $this->assertSame(['after'], self::failedAt(self::clockAt('2026-10-18T06:00:00', 'Asia/Tokyo'), $rule, '2026-01-02'));
    }

    public function testLimitTheFormatCannotReadInTheZoneOfTheValidationIsRefusedThere(): void
    {
        // 20:00 UTC on 31 December 1969 is 1970 in Tokyo, which `y` reads back,
        // and 1969 in UTC, which `y` reads as 2069.
        date_default_timezone_set('Asia/Tokyo');
        $validator = new Validator(['v' => Rule::date('y-m-d')->after(new DateTimeImmutable('1969-12-31T20:00:00Z'))], self::clockAt('2026-10-18T06:00:00', 'UTC'));

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => $validator->validate(['v' => '26-10-18']));
        $this->assertSame('date() reads dates with \'y-m-d\', which does not read back the local date 1969-12-31.', $e->getMessage());
    }

    public function testDefaultIsTheDateItFallsOnInTheZoneOfTheClock(): void
    {
        $rule  = Rule::date()->default(new DateTimeImmutable('2026-01-02T00:00:00+09:00'));
        $value = static fn (string $zone): string => self::formatted(
            new Validator(['v' => $rule], self::clockAt('2026-10-18T06:00:00', $zone))->validate([])->values()['v'],
        );

        // The rule is shared, so the second zone shows the first one's outcome is not reused.
        $this->assertSame('2026-01-02T00:00:00+09:00', $value('Asia/Tokyo'));
        $this->assertSame('2026-01-01T00:00:00+00:00', $value('UTC'));
    }

    public function testDefaultOfAContainerIsTheDateItFallsOnInTheZoneOfTheClock(): void
    {
        $instant = new DateTimeImmutable('2026-01-02T00:00:00+09:00');
        $values  = new Validator([
            'list'  => Rule::list(Rule::date())->default([$instant]),
            'shape' => Rule::shape(['d' => Rule::date()])->default(['d' => $instant]),
            'empty' => Rule::list(Rule::date()->default($instant))->default([null]),
        ], self::clockAt('2026-10-18T06:00:00', 'UTC'))->validate([])->values();

        $this->assertSame('2026-01-01T00:00:00+00:00', self::formatted(self::listed($values['list'])[0]));
        $this->assertSame('2026-01-01T00:00:00+00:00', self::formatted(self::listed($values['shape'])['d']));
        $this->assertSame('2026-01-01T00:00:00+00:00', self::formatted(self::listed($values['empty'])[0]));
    }

    public function testDefaultsOfAContainerCompareByTheDateInTheZoneOfTheClock(): void
    {
        // The default falls on 1 January in UTC and on 2 January in Tokyo.
        $rules = [
            'a' => Rule::list(Rule::date())->default([new DateTimeImmutable('2026-01-01T20:00:00Z')]),
            'b' => Rule::list(Rule::date())->same('a'),
        ];
        $input = ['b' => ['2026-01-01']];

        $this->assertFalse(new Validator($rules, self::clockAt('2026-10-18T06:00:00', 'UTC'))->validate($input)->failed());
        $this->assertTrue(new Validator($rules, self::clockAt('2026-10-18T06:00:00', 'Asia/Tokyo'))->validate($input)->failed());
    }
}
