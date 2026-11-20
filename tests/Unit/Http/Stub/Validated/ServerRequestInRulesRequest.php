<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Psr\Http\Message\ServerRequestInterface;
use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class ServerRequestInRulesRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(ServerRequestInterface $request): array
    {
        return ['name' => Rule::string()->default($request->getUri()->getQuery())];
    }
}
