<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use BcMath\Number;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Sloop\Support\TimeOfDay;
use Sloop\Tests\Unit\Validation\Stub\Priority;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class ValueTypeTest extends TestCase
{
    /**
     * @return array<string, array{FieldRule<covariant mixed>, string}>
     */
    public static function rules(): array
    {
        return [
            'string'           => [Rule::string(), 'string'],
            'int'              => [Rule::int(), 'int'],
            'float'            => [Rule::float(), 'float'],
            'bool'             => [Rule::bool(), 'bool'],
            'decimal'          => [Rule::decimal(5, 2), 'string'],
            'decimal asNumber' => [Rule::decimal(5, 2)->asNumber(), Number::class],
            'enum'             => [Rule::enum(Priority::class), Priority::class],
            'date'             => [Rule::date(), DateTimeImmutable::class],
            'dateTime'         => [Rule::dateTime(), DateTimeImmutable::class],
            'time'             => [Rule::time(), TimeOfDay::class],
            'file'             => [Rule::file(), UploadedFileInterface::class],
            'array'            => [Rule::array(), 'array'],
            'list'             => [Rule::list(Rule::int()), 'array'],
            'shape'            => [Rule::shape(['a' => Rule::int()]), 'array'],
        ];
    }

    /**
     * @param FieldRule<covariant mixed> $rule
     */
    #[DataProvider('rules')]
    public function testNamesTheTypeOfTheValidatedValue(FieldRule $rule, string $type): void
    {
        $this->assertSame($type, $rule->valueType());
    }

    public function testAnOptionalFieldWithoutADefaultCanBeNull(): void
    {
        $this->assertTrue(Rule::int()->canBeNull());
        $this->assertTrue(Rule::int()->requiredWith('other')->canBeNull());
    }

    public function testARequiredFieldOrOneWithADefaultCannotBeNull(): void
    {
        $this->assertFalse(Rule::int()->required()->canBeNull());
        $this->assertFalse(Rule::int()->default(1)->canBeNull());
    }
}
