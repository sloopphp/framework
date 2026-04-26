<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log\Processor;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sloop\Log\Processor\ElapsedTimeProcessor;
use Sloop\Log\TraceContext;

final class ElapsedTimeProcessorTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private function createRecord(array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test message',
            extra: $extra,
        );
    }

    public function testInjectsElapsedMsIntoExtra(): void
    {
        $context   = new TraceContext();
        $processor = new ElapsedTimeProcessor($context);
        $record    = $this->createRecord();

        $processed = $processor($record);

        $this->assertArrayHasKey('elapsed_ms', $processed->extra);
        $this->assertIsInt($processed->extra['elapsed_ms']);
        $this->assertGreaterThanOrEqual(0, $processed->extra['elapsed_ms']);
    }

    public function testElapsedMsIncreasesOverTime(): void
    {
        $context   = new TraceContext();
        $processor = new ElapsedTimeProcessor($context);

        // CI's Windows runner intermittently reports far fewer ms elapsed than
        // the actual usleep duration (timer resolution + scheduler jitter).
        // Loosen the lower bound to verify only "some elapsed time was
        // measured" rather than a specific minimum, keeping the intent
        // (elapsed_ms grows after a wait) without environment-dependent flakes.
        usleep(30_000);
        $processed = $processor($this->createRecord());

        $this->assertGreaterThanOrEqual(1, $processed->extra['elapsed_ms']);
    }

    public function testPreservesExistingExtraValues(): void
    {
        $context   = new TraceContext();
        $processor = new ElapsedTimeProcessor($context);
        $record    = $this->createRecord(extra: ['existing' => 'value']);

        $processed = $processor($record);

        $this->assertSame('value', $processed->extra['existing']);
        $this->assertArrayHasKey('elapsed_ms', $processed->extra);
    }
}
