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
     * Validated value of the field, asserting that validation passed.
     *
     * @param FieldRule<*> $rule
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
     * @param  FieldRule<*> $rule
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
     * @param FieldRule<*> $rule
     */
    private static function onlyError(FieldRule $rule, mixed $input): ValidationError
    {
        $errors = new Validator(['v' => $rule])->validate(['v' => $input])->errors();
        self::assertCount(1, $errors['v'] ?? []);

        return $errors['v'][0];
    }
}
