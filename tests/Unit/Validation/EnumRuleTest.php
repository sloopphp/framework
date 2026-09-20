<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Validation\Stub\Payment;
use Sloop\Tests\Unit\Validation\Stub\Priority;
use Sloop\Tests\Unit\Validation\Stub\Suit;
use Sloop\Validation\Rule;
use Sloop\Validation\Sanitize;
use Sloop\Validation\ValidationError;

final class EnumRuleTest extends TestCase
{
    use ThrowsAssertions;
    use ValidatesOneField;

    public function testStringBackedEnumIsReadFromItsValue(): void
    {
        $this->assertSame(Payment::Card, self::valueOf(Rule::enum(Payment::class), 'card'));
    }

    public function testCaseItselfIsAccepted(): void
    {
        $this->assertSame(Payment::Cash, self::valueOf(Rule::enum(Payment::class), Payment::Cash));
    }

    /**
     * @return iterable<string, array{mixed, Priority}>
     */
    public static function intBackedInputs(): iterable
    {
        yield 'JSON int' => [10, Priority::High];
        yield 'digits' => ['1', Priority::Low];
        yield 'leading zeros' => ['010', Priority::High];
        yield 'negative' => ['-5', Priority::Negative];
    }

    #[DataProvider('intBackedInputs')]
    public function testIntBackedEnumReadsIntsLikeRuleInt(mixed $input, Priority $expected): void
    {
        $this->assertSame($expected, self::valueOf(Rule::enum(Priority::class), $input));
    }

    /**
     * @return iterable<string, array{class-string<\BackedEnum>, mixed}>
     */
    public static function rejectedInputs(): iterable
    {
        yield 'unknown string' => [Payment::class, 'cheque'];
        yield 'case name' => [Payment::class, 'Cash'];
        yield 'int for string-backed' => [Payment::class, 1];
        yield 'unknown int' => [Priority::class, 2];
        yield 'float for int-backed' => [Priority::class, 1.0];
        yield 'non-integer string for int-backed' => [Priority::class, '1.0'];
        yield 'case of another enum' => [Payment::class, Priority::Low];
        yield 'array' => [Payment::class, ['cash']];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[DataProvider('rejectedInputs')]
    public function testRejects(string $enum, mixed $input): void
    {
        self::assertErrorSame(new ValidationError('enum', [], 'The selected v is invalid.'), self::onlyError(Rule::enum($enum), $input));
    }

    public function testSanitizersFollowTheEnumClass(): void
    {
        $this->assertSame(Payment::Cash, self::valueOf(Rule::enum(Payment::class, Sanitize::Trim, mb_strtolower(...)), ' CASH '));
    }

    public function testPureEnumThrows(): void
    {
        // @phpstan-ignore argument.type, argument.templateType (the point of the test is that the type refuses it)
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::enum(Suit::class));

        $this->assertSame('enum() needs a backed enum, got ' . Suit::class . '.', $e->getMessage());
    }

    public function testInAndNotIn(): void
    {
        $in = Rule::enum(Payment::class)->in([Payment::Cash, Payment::Card]);
        $this->assertSame([], self::failedRules($in, 'cash'));
        self::assertErrorSame(
            new ValidationError('in', ['values' => ['cash', 'card']], 'The selected v is invalid.'),
            self::onlyError($in, 'transfer'),
        );

        $notIn = Rule::enum(Priority::class)->notIn([Priority::Negative]);
        $this->assertSame([], self::failedRules($notIn, 1));
        self::assertErrorSame(
            new ValidationError('notIn', ['values' => [-5]], 'The selected v is invalid.'),
            self::onlyError($notIn, '-5'),
        );
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidDeclarations(): iterable
    {
        yield 'empty in' => [static fn () => Rule::enum(Payment::class)->in([]), 'in() needs at least one case.'];
        yield 'empty notIn' => [static fn () => Rule::enum(Payment::class)->notIn([]), 'notIn() needs at least one case.'];
        yield 'in with another enum' => [
            // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
            static fn () => Rule::enum(Payment::class)->in([Payment::Cash, Priority::Low]),
            'in() needs cases of ' . Payment::class . ', got ' . Priority::class . '.',
        ];
        yield 'notIn with another enum' => [
            // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
            static fn () => Rule::enum(Payment::class)->notIn([Priority::Low]),
            'notIn() needs cases of ' . Payment::class . ', got ' . Priority::class . '.',
        ];
        yield 'default of another enum' => [
            // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
            static fn () => Rule::enum(Payment::class)->default(Priority::Low),
            'default() needs a case of ' . Payment::class . ', got ' . Priority::class . '.',
        ];
    }

    /**
     * @param \Closure(): mixed $declare
     */
    #[DataProvider('invalidDeclarations')]
    public function testInvalidDeclarationThrows(\Closure $declare, string $message): void
    {
        $this->assertSame($message, $this->assertThrows(InvalidArgumentException::class, $declare)->getMessage());
    }

    public function testDefault(): void
    {
        $this->assertSame(Payment::Cash, self::valueOf(Rule::enum(Payment::class)->default(Payment::Cash), null));
    }
}
