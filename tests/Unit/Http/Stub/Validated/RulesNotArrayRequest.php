<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;

final class RulesNotArrayRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @return string
     */
    protected function rules(): string
    {
        return 'name';
    }
}
