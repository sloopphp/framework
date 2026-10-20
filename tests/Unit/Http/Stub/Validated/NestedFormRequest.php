<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class NestedFormRequest extends ValidatedRequest
{
    /** @var array<string, mixed>|null */
    public private(set) ?array $user = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['user' => Rule::shape(['name' => Rule::string()->required(), 'avatar' => Rule::file()])];
    }
}
