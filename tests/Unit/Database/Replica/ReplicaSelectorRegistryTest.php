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
    public function testGetReturnsSelectorRegisteredUnderIdentifier(): void
    {
        $random = new RandomReplicaSelector();
        $other  = $this->fixedSelector();

        $registry = new ReplicaSelectorRegistry([
            'random' => $random,
            'first'  => $other,
        ]);

        // 識別子ごとに別のインスタンスが返ることを確認する。片方だけ検証すると
        // 「常に同じものを返す」実装でも通ってしまう。
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

    private function fixedSelector(): ReplicaSelector
    {
        return new class () implements ReplicaSelector {
            public function pick(array $candidates): int
            {
                return 0;
            }
        };
    }
}
