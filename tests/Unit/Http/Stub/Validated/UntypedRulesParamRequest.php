<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class UntypedRulesParamRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @param  mixed                                     $id
     * @return array<string, FieldRule<covariant mixed>>
     *
     * @noinspection PhpMissingParamTypeInspection
     */
    protected function rules($id): array
    {
        return ['name' => Rule::string()->default(\is_string($id) ? $id : 'not a string')];
    }
}
