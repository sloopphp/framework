<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Psr\Http\Message\UploadedFileInterface;
use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class FileUploadRequest extends ValidatedRequest
{
    public private(set) ?UploadedFileInterface $avatar = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['avatar' => Rule::file()];
    }
}
