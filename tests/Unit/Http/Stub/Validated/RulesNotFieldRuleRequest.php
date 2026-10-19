<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;

final class RulesNotFieldRuleRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return ['name' => 'string'];
    }
}
