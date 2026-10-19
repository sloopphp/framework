<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class PropertyWithoutRuleRequest extends ValidatedRequest
{
    public private(set) ?string $name  = null;
    public private(set) ?string $email = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['name' => Rule::string()];
    }
}
