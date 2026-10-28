<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class ArrayRulesParamRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @param  array<mixed>                              $id
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(array $id): array
    {
        return ['name' => Rule::string()->default((string) \count($id))];
    }
}
