<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Replica;

use PHPUnit\Framework\TestCase;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\Replica\RandomReplicaSelector;
use Sloop\Database\Replica\ReplicaSelector;
use Sloop\Database\Replica\ReplicaSelectorRegistry;

final class ReplicaSelectorRegistryTest extends TestCase
{
    private function fixedSelector(): ReplicaSelector
    {
        return new class () implements ReplicaSelector {
            public function pick(array $candidates): int
            {
                return 0;
            }
        };
    }

    public function testGetReturnsSelectorRegisteredUnderIdentifier(): void
    {
        $random = new RandomReplicaSelector();
        $other  = $this->fixedSelector();

        $registry = new ReplicaSelectorRegistry([
            'random' => $random,
            'first'  => $other,
        ]);

        // Verify that each identifier returns a distinct instance. Checking only
        // one of them would let an implementation that always returns the same
        // object pass.
        $this->assertSame($random, $registry->get('random'));
        $this->assertSame($other, $registry->get('first'));
    }

    public function testGetThrowsForUnregisteredIdentifierAndListsRegisteredOnes(): void
    {
        $registry = new ReplicaSelectorRegistry([
            'random' => new RandomReplicaSelector(),
            'first'  => $this->fixedSelector(),
        ]);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            'No replica selector is registered for "round_robin". Registered: random, first.'
        );

        $registry->get('round_robin');
    }

    public function testGetThrowsForEmptyRegistry(): void
    {
        $registry = new ReplicaSelectorRegistry([]);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            'No replica selector is registered for "random". Registered: (none).'
        );

        $registry->get('random');
    }
}
