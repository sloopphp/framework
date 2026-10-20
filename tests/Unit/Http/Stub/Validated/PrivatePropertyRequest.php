<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class PrivatePropertyRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;
    private string $helper            = 'x';

    public function helper(): string
    {
        return $this->helper;
    }

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['name' => Rule::string()];
    }
}
