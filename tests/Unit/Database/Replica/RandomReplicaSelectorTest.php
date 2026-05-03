<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Replica;

use PHPUnit\Framework\TestCase;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Replica\RandomReplicaSelector;

final class RandomReplicaSelectorTest extends TestCase
{
    private function makeConfig(string $host): ValidatedConfig
    {
        return new ValidatedConfig(
            driver: 'mysql',
            host: $host,
            port: 3306,
            database: 'app',
            username: 'app',
            password: null,
            charset: 'utf8mb4',
            collation: null,
            connectTimeoutSeconds: 2,
            options: [],
        );
    }

    public function testPickReturnsZeroForSingleCandidate(): void
    {
        $selector = new RandomReplicaSelector();

        $this->assertSame(0, $selector->pick([$this->makeConfig('r1.example')]));
    }

    public function testPickReturnsIndexWithinRangeForMultipleCandidates(): void
    {
        $selector   = new RandomReplicaSelector();
        $candidates = [
            $this->makeConfig('r1.example'),
            $this->makeConfig('r2.example'),
            $this->makeConfig('r3.example'),
        ];

        for ($i = 0; $i < 200; $i++) {
            $picked = $selector->pick($candidates);

            $this->assertGreaterThanOrEqual(0, $picked);
            $this->assertLessThan(3, $picked);
        }
    }

    public function testPickEventuallyChoosesEveryCandidate(): void
    {
        $selector   = new RandomReplicaSelector();
        $candidates = [
            $this->makeConfig('r1.example'),
            $this->makeConfig('r2.example'),
            $this->makeConfig('r3.example'),
        ];

        $seen = [];
        for ($i = 0; $i < 200 && \count($seen) < 3; $i++) {
            $picked        = $selector->pick($candidates);
            $seen[$picked] = true;
        }

        $this->assertCount(3, $seen);
    }

    public function testPickIsStatelessAcrossInvocations(): void
    {
        $selector = new RandomReplicaSelector();

        // Single-element calls always pick 0; the selector keeps no state from the previous call.
        $this->assertSame(0, $selector->pick([$this->makeConfig('r1.example')]));
        $this->assertSame(0, $selector->pick([$this->makeConfig('r1.example')]));

        $picked = $selector->pick([
            $this->makeConfig('r1.example'),
            $this->makeConfig('r2.example'),
        ]);
        $this->assertGreaterThanOrEqual(0, $picked);
        $this->assertLessThan(2, $picked);
    }

    public function testPickDoesNotMutateCandidates(): void
    {
        $selector   = new RandomReplicaSelector();
        $candidates = [
            $this->makeConfig('r1.example'),
            $this->makeConfig('r2.example'),
            $this->makeConfig('r3.example'),
        ];
        $snapshot   = $candidates;

        $selector->pick($candidates);
        $selector->pick($candidates);

        $this->assertSame($snapshot, $candidates);
    }
}
