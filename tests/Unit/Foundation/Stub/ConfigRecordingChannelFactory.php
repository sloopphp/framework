<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Sloop\Log\ChannelFactoryInterface;

/**
 * Test stub that keeps the channel config it was given, for tests of what reaches a custom factory.
 */
final class ConfigRecordingChannelFactory implements ChannelFactoryInterface
{
    /**
     * Channel config passed to the last create() call.
     *
     * @var array<array-key, mixed>|null
     */
    public static ?array $lastConfig = null;

    public function create(string $name, array $config): Logger
    {
        self::$lastConfig = $config;

        return new Logger($name, [new TestHandler()]);
    }
}
