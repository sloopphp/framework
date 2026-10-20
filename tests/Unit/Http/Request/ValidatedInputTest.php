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
            'list over the smaller of two count limits, declared first' => [
                Rule::list(Rule::string())->exactCount(1)->maxCount(3),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'list sent over its count limit that an array sanitizer brings within it' => [
                Rule::list(Rule::string(), ArraySanitize::RemoveEmpty)->maxCount(2),
                [2 => 'c', 1 => '', 0 => 'a'],
                [2 => 'c', 1 => '', 0 => 'a'],
            ],
            'list within its count limit with an array sanitizer' => [
                Rule::list(Rule::string(), ArraySanitize::RemoveEmpty)->maxCount(2),
                [1 => 'b', 0 => 'a'],
                [0 => 'a', 1 => 'b'],
            ],
            'list over minCount(), within maxCount()' => [
                Rule::list(Rule::list(Rule::string())->maxCount(2))->maxCount(3)->minCount(1),
                [1 => [1 => 'd', 0 => 'c'], 0 => [1 => 'b', 0 => 'a']],
                [0 => [0 => 'a', 1 => 'b'], 1 => [0 => 'c', 1 => 'd']],
            ],
            'list without a count limit' => [
                Rule::list(Rule::string()),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'list with a closure array sanitizer' => [
                Rule::list(Rule::string(), static fn (array $value): array => $value)->maxCount(2),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'Rule::array() within its count limit, and its contents' => [
                Rule::array()->maxCount(2),
                [1 => [1 => 'b', 0 => 'a'], 0 => 'x'],
                [0 => 'x', 1 => [1 => 'b', 0 => 'a']],
            ],
            'Rule::array() without a count limit' => [
                Rule::array(),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'shape: a declared key' => [
                Rule::shape(['tags' => Rule::list(Rule::string())->maxCount(2)]),
                ['tags' => [1 => 'b', 0 => 'a']],
                ['tags' => [0 => 'a', 1 => 'b']],
            ],
            'shape: a declared key after a scalar' => [
                Rule::shape(['name' => Rule::string(), 'tags' => Rule::list(Rule::string())->maxCount(2)]),
                ['name' => 'a', 'tags' => [1 => 'b', 0 => 'a']],
                ['name' => 'a', 'tags' => [0 => 'a', 1 => 'b']],
            ],
            'shape: a key it drops' => [
                Rule::shape(['name' => Rule::string()]),
                ['name' => 'a', 'junk' => [1 => 'b', 0 => 'a']],
                ['name' => 'a', 'junk' => [1 => 'b', 0 => 'a']],
            ],
            'shape: a declared key after a key it drops' => [
                Rule::shape(['tags' => Rule::list(Rule::string())->maxCount(2)]),
                ['junk' => 'x', 'tags' => [1 => 'b', 0 => 'a']],
                ['tags' => [0 => 'a', 1 => 'b']],
            ],
            'shape: every declared key kept when it is rebuilt without a key it drops' => [
                Rule::shape(['name' => Rule::string(), 'tags' => Rule::list(Rule::string())->maxCount(2)]),
                ['name' => 'n', 'junk' => 'x', 'tags' => [1 => 'b', 0 => 'a']],
                ['name' => 'n', 'tags' => [0 => 'a', 1 => 'b']],
            ],
            'shape sent with numbered keys only' => [
                Rule::shape(['name' => Rule::string()]),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
            ],
            'shape with a closure array sanitizer' => [
                Rule::shape(['name' => Rule::string()], static fn (array $value): array => $value),
                [1 => 'b', 0 => 'a'],
                [1 => 'b', 0 => 'a'],
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
    public function testOrdersOnlyWhatADeclarationBounds(FieldRule $rule, array $sent, array $expected): void
    {
        $request = new ServerRequest('POST', '/')->withParsedBody(['field' => $sent]);

        $this->assertSame(['field' => $expected], ValidatedInput::collect($request, ['field' => $rule]));
    }
}
