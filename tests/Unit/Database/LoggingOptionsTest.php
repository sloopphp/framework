<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Sloop\Database\LoggingOptions;

final class LoggingOptionsTest extends TestCase
{
    public function testDefaultsMatchPlannedBehavior(): void
    {
        $options = new LoggingOptions();

        $this->assertTrue($options->logBindings);
        $this->assertFalse($options->logAllQueries);
        $this->assertNull($options->slowQueryThresholdMs);
    }

    public function testStoresExplicitFields(): void
    {
        $options = new LoggingOptions(
            logBindings: false,
            logAllQueries: true,
            slowQueryThresholdMs: 200,
        );

        $this->assertFalse($options->logBindings);
        $this->assertTrue($options->logAllQueries);
        $this->assertSame(200, $options->slowQueryThresholdMs);
    }
}
