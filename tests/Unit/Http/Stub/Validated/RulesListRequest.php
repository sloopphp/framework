<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class RulesListRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @return list<FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return [Rule::string()];
    }
}
