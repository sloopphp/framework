<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class InheritedPrivateRequest extends PrivateHelperBase
{
    public private(set) ?string $name = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['name' => Rule::string()];
    }
}
