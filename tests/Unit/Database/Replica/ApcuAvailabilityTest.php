<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Replica;

use PHPUnit\Framework\TestCase;
use Sloop\Database\Replica\ApcuDeadReplicaCache;

// isAvailable() is deliberately tested outside ApcuDeadReplicaCacheTest: that
// class skips itself when APCu is disabled, so a defect in the availability
// probe would silently skip its own regression test instead of failing it.
// This class runs in every environment and states the probe's contract by
// deriving the expectation from the extension itself.
final class ApcuAvailabilityTest extends TestCase
{
    public function testIsAvailableMatchesTheExtensionState(): void
    {
        $expected = \function_exists('apcu_enabled') && apcu_enabled();

        $this->assertSame($expected, ApcuDeadReplicaCache::isAvailable());
    }
}
