<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Nyholm\Psr7\ServerRequest;
use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class ServerRequestClassInRulesRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(ServerRequest $request): array
    {
        return ['name' => Rule::string()->default($request->getUri()->getQuery())];
    }
}
