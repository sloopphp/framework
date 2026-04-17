<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

/**
 * Tiny value-object stub resolved through the container to verify object DI.
 */
final readonly class DiService
{
    /**
     * Create a new DiService.
     *
     * @param  string $id Identifier used by tests to assert the injected instance
     * @return void
     */
    public function __construct(public string $id = 'default')
    {
    }
}
