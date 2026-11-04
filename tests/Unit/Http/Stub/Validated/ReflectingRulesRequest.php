<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;

final class ReflectingRulesRequest extends ValidatedRequest
{
    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        throw new \ReflectionException('thrown by rules');
    }
}
