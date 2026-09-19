<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use Sloop\Validation\FieldRule;
use Sloop\Validation\ValidationError;
use Sloop\Validation\Validator;

/**
 * Helpers for tests that exercise one field rule on one value.
 */
trait ValidatesOneField
{
    /**
     * Assert that two errors match, comparing the parameter values with their types.
     *
     * assertEquals() would treat `'100'` and `100` as equal, and the params
     * reach clients as JSON, where the two differ.
     */
    private static function assertErrorSame(ValidationError $expected, ValidationError $actual): void
    {
        self::assertSame(
            [$expected->rule, $expected->params, $expected->message],
            [$actual->rule, $actual->params, $actual->message],
        );
    }

    /**
     * Assert that two error maps match field by field, with assertErrorSame() for each error.
     *
     * @param array<string, list<ValidationError>> $expected
     * @param array<string, list<ValidationError>> $actual
     */
    private static function assertErrorsSame(array $expected, array $actual): void
    {
        self::assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $field => $errors) {
            self::assertCount(\count($errors), $actual[$field]);
            foreach ($errors as $index => $error) {
                self::assertErrorSame($error, $actual[$field][$index]);
            }
        }
    }

    /**
     * Validated value of the field, asserting that validation passed.
     *
     * @param FieldRule<covariant mixed> $rule
     */
    private static function valueOf(FieldRule $rule, mixed $input): mixed
    {
        $result = new Validator(['v' => $rule])->validate(['v' => $input]);
        self::assertFalse($result->failed(), 'Expected ' . var_export($input, true) . ' to pass.');

        return $result->values()['v'];
    }

    /**
     * Names of the rules that failed for the value; empty when it passed.
     *
     * @param  FieldRule<covariant mixed> $rule
     * @return list<string>
     */
    private static function failedRules(FieldRule $rule, mixed $input): array
    {
        $errors = new Validator(['v' => $rule])->validate(['v' => $input])->errors();

        return array_map(static fn (ValidationError $e): string => $e->rule, $errors['v'] ?? []);
    }

    /**
     * The single error of the field.
     *
     * @param FieldRule<covariant mixed> $rule
     */
    private static function onlyError(FieldRule $rule, mixed $input): ValidationError
    {
        $errors = new Validator(['v' => $rule])->validate(['v' => $input])->errors();
        self::assertCount(1, $errors['v'] ?? []);

        return $errors['v'][0];
    }
}
