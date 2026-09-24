<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Validation\Rule;
use Sloop\Validation\Sanitize;
use Sloop\Validation\Validator;

final class DateTimeRuleTest extends TestCase
{
    use ValidatesOneField;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedOffsets(): iterable
    {
        yield 'extended offset' => ['2026-01-02T03:04:05+09:00', '2026-01-01T18:04:05+00:00'];
        yield 'Z' => ['2026-01-02T03:04:05Z', '2026-01-02T03:04:05+00:00'];
        yield 'basic offset' => ['2026-01-02T03:04:05+0900', '2026-01-01T18:04:05+00:00'];
        yield 'hour-only offset' => ['2026-01-02T03:04:05+09', '2026-01-01T18:04:05+00:00'];
        yield 'negative offset' => ['2026-01-02T03:04:05-05:00', '2026-01-02T08:04:05+00:00'];
        yield 'the largest offset in use' => ['2026-01-02T03:04:05+14:00', '2026-01-01T13:04:05+00:00'];
        yield 'the largest the clock allows' => ['2026-01-02T03:04:05+23:59', '2026-01-01T03:05:05+00:00'];
    }

    #[DataProvider('acceptedOffsets')]
    public function testOffsetInTheInputDecidesTheInstant(string $input, string $expectedUtc): void
    {
        $value = self::valueOf(Rule::dateTime(), $input);

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame($expectedUtc, $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedFractions(): iterable
    {
        yield 'one digit' => ['2026-01-02T03:04:05.1Z', '100000'];
        yield 'milliseconds as JavaScript writes them' => ['2026-01-02T03:04:05.123Z', '123000'];
        yield 'microseconds' => ['2026-01-02T03:04:05.123456Z', '123456'];
    }

    #[DataProvider('acceptedFractions')]
    public function testFractionalSecondsAreKept(string $input, string $expectedMicroseconds): void
    {
        $value = self::valueOf(Rule::dateTime(), $input);

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame($expectedMicroseconds, $value->format('u'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedInputs(): iterable
    {
        yield 'no offset' => ['2026-01-02T03:04:05'];
        yield 'space instead of T' => ['2026-01-02 03:04:05+09:00'];
        yield 'no seconds' => ['2026-01-02T03:04+09:00'];
        yield 'unpadded hour' => ['2026-01-02T3:04:05+09:00'];
        yield 'unpadded month' => ['2026-1-02T03:04:05+09:00'];
        yield 'day out of range' => ['2026-02-30T03:04:05+09:00'];
        yield 'hour out of range' => ['2026-01-02T25:04:05+09:00'];
        yield 'seven fractional digits' => ['2026-01-02T03:04:05.1234567Z'];
        yield 'lowercase z' => ['2026-01-02T03:04:05z'];
        yield 'trailing text' => ['2026-01-02T03:04:05+09:00 x'];
        yield 'date only' => ['2026-01-02'];
        yield 'offset hour past the clock' => ['2026-01-02T03:04:05+24:00'];
        yield 'offset minute past the clock' => ['2026-01-02T03:04:05+09:99'];
        yield 'offset hour and minute past the clock' => ['2026-01-02T03:04:05-99:99'];
        yield 'basic offset past the clock' => ['2026-01-02T03:04:05+0999'];
        yield 'null byte' => ["2026-01-02T03:04:05Z\0"];
    }

    #[DataProvider('rejectedInputs')]
    public function testInputsOutsideTheAcceptedShapeAreTypeFailures(string $input): void
    {
        $this->assertSame(['dateTime'], self::failedRules(Rule::dateTime(), $input));
    }

    public function testNonStringIsATypeFailure(): void
    {
        $this->assertSame(['dateTime'], self::failedRules(Rule::dateTime(), 1767286245));
    }

    public function testSanitizersRunBeforeTheShapeIsChecked(): void
    {
        $value = self::valueOf(Rule::dateTime(Sanitize::Trim), "  2026-01-02T03:04:05Z\n");

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-01-02T03:04:05+00:00', $value->format('Y-m-d\TH:i:sP'));
    }

    public function testDefaultKeepsTheInstantItWasGiven(): void
    {
        $default = new DateTimeImmutable('2026-01-02T03:04:05+09:00');
        $value   = self::valueOf(Rule::dateTime()->default($default), null);

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame($default->format('U.u'), $value->format('U.u'));
    }

    public function testValidatedValueIsInUtcWhateverOffsetTheInputCarried(): void
    {
        // Two clients naming one instant from different zones hand the caller
        // the same value, not two that only compare equal. The wall clock is
        // what format() writes to a database, so it has to agree as well.
        $east = self::valueOf(Rule::dateTime(), '2026-01-02T12:00:00+09:00');
        $west = self::valueOf(Rule::dateTime(), '2026-01-01T22:00:00-05:00');

        $this->assertInstanceOf(DateTimeImmutable::class, $east);
        $this->assertInstanceOf(DateTimeImmutable::class, $west);
        $this->assertSame('UTC', $east->getTimezone()->getName());
        $this->assertSame('UTC', $west->getTimezone()->getName());
        $this->assertSame('2026-01-02 03:00:00', $east->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-02 03:00:00', $west->format('Y-m-d H:i:s'));
    }

    public function testADefaultDeclaredOnAContainerIsInUtcToo(): void
    {
        // A container's default reaches the caller through prepareDefault()
        // rather than through output(), so the two have to agree on the frame.
        $declared = new DateTimeImmutable('2026-01-02T03:04:05+14:00');
        $value    = self::valueOf(Rule::shape(['t' => Rule::dateTime()])->default(['t' => $declared]), null);

        $this->assertIsArray($value);
        $at = $value['t'];
        $this->assertInstanceOf(DateTimeImmutable::class, $at);
        $this->assertSame('UTC', $at->getTimezone()->getName());
        $this->assertSame('2026-01-01 13:04:05', $at->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'before, earlier' => ['before', '2026-01-02T03:04:04Z', true];
        yield 'before, equal' => ['before', '2026-01-02T03:04:05Z', false];
        yield 'before, later' => ['before', '2026-01-02T03:04:06Z', false];
        yield 'beforeOrEqual, equal' => ['beforeOrEqual', '2026-01-02T03:04:05Z', true];
        yield 'beforeOrEqual, later' => ['beforeOrEqual', '2026-01-02T03:04:06Z', false];
        yield 'after, later' => ['after', '2026-01-02T03:04:06Z', true];
        yield 'after, equal' => ['after', '2026-01-02T03:04:05Z', false];
        yield 'afterOrEqual, equal' => ['afterOrEqual', '2026-01-02T03:04:05Z', true];
        yield 'afterOrEqual, earlier' => ['afterOrEqual', '2026-01-02T03:04:04Z', false];
    }

    #[DataProvider('comparisons')]
    public function testComparisonsAreMadeOnTheInstant(string $method, string $input, bool $passes): void
    {
        $limit = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $rule  = match ($method) {
            'before' => Rule::dateTime()->before($limit),
            'beforeOrEqual' => Rule::dateTime()->beforeOrEqual($limit),
            'after' => Rule::dateTime()->after($limit),
            default => Rule::dateTime()->afterOrEqual($limit),
        };

        $this->assertSame($passes ? [] : [$method], self::failedRules($rule, $input));
    }

    public function testComparisonLimitIsReadAsAnInstantRatherThanAsLocalTime(): void
    {
        // The same instant written in another zone must give the same verdict.
        $limit = new DateTimeImmutable('2026-01-02T12:04:05+09:00');

        $this->assertSame([], self::failedRules(Rule::dateTime()->beforeOrEqual($limit), '2026-01-02T03:04:05Z'));
        $this->assertSame(['before'], self::failedRules(Rule::dateTime()->before($limit), '2026-01-02T03:04:05Z'));
    }

    public function testComparisonParameterIsReportedInUtc(): void
    {
        $error = self::onlyError(
            Rule::dateTime()->before(new DateTimeImmutable('2026-01-02T12:04:05+09:00')),
            '2026-01-02T23:00:00Z',
        );

        $this->assertSame('before', $error->rule);
        $this->assertSame(['date' => '2026-01-02T03:04:05+00:00'], $error->params);
    }

    public function testTwoFieldsNamingTheSameInstantInDifferentZonesCompareEqual(): void
    {
        $rules  = ['from' => Rule::dateTime(), 'to' => Rule::dateTime()->same('from')];
        $result = new Validator($rules)->validate([
            'from' => '2026-01-02T03:04:05Z',
            'to' => '2026-01-02T12:04:05+09:00',
        ]);

        $this->assertFalse($result->failed());
    }
}
