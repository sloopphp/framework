<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Request;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Http\Request\ValidatedInput;
use Sloop\Validation\ArraySanitize;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class ValidatedInputTest extends TestCase
{
    /**
     * @return array<string, array{FieldRule<covariant mixed>, array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function orderings(): array
    {
        return [
            'list within its count limit' => [
                Rule::list(Rule::string())->maxCount(2),
                [1 => 'b', 0 => 'a'],
                [0 => 'a', 1 => 'b'],
            ],
            'list over maxCount()' => [
                Rule::list(Rule::string())->maxCount(1),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'list over exactCount()' => [
                Rule::list(Rule::string())->exactCount(1),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'list over betweenCount()' => [
                Rule::list(Rule::string())->betweenCount(0, 1),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'list over the smaller of two count limits' => [
                Rule::list(Rule::string())->maxCount(3)->exactCount(1),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'list over its count limit with an array sanitizer' => [
                Rule::list(Rule::string(), ArraySanitize::RemoveEmpty)->maxCount(1),
                [1 => 'b', 0 => 'a'],
                [0 => 'a', 1 => 'b'],
            ],
            'list over minCount() only' => [
                Rule::list(Rule::list(Rule::string())->maxCount(2))->maxCount(1)->minCount(1),
                [0 => [1 => 'b', 0 => 'a']],
                [0 => [0 => 'a', 1 => 'b']],
            ],
            'shape: a declared key' => [
                Rule::shape(['tags' => Rule::list(Rule::string())->maxCount(2)]),
                ['tags' => [1 => 'b', 0 => 'a']],
                ['tags' => [0 => 'a', 1 => 'b']],
            ],
            'shape: a key it drops' => [
                Rule::shape(['name' => Rule::string()]),
                ['name' => 'a', 'junk' => [1 => 'b', 0 => 'a']],
                ['name' => 'a', 'junk' => [1 => 'b', 0 => 'a']],
            ],
            'shape sent with numbered keys only' => [
                Rule::shape(['name' => Rule::string()]),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'shape with a closure array sanitizer' => [
                Rule::shape(['name' => Rule::string()], static fn (array $value): array => $value),
                ['junk' => [1 => 'b', 0 => 'a']],
                ['junk' => [0 => 'a', 1 => 'b']],
            ],
            'a type that holds no array' => [
                Rule::string(),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
        ];
    }

    /**
     * @param FieldRule<covariant mixed> $rule
     * @param array<array-key, mixed>    $sent
     * @param array<array-key, mixed>    $expected
     */
    #[DataProvider('orderings')]
    public function testOrdersOnlyThePartTheValidatorReads(FieldRule $rule, array $sent, array $expected): void
    {
        $request = new ServerRequest('POST', '/')->withParsedBody(['field' => $sent]);

        $this->assertSame(['field' => $expected], ValidatedInput::collect($request, ['field' => $rule]));
    }
}
