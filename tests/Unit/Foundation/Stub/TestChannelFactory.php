<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Sloop\Log\ChannelFactoryInterface;

/**
 * Test stub implementing ChannelFactoryInterface for custom log driver tests.
 */
final class TestChannelFactory implements ChannelFactoryInterface
{
    public function create(string $name, array $config): Logger
    {
        return new Logger($name, [new TestHandler()]);
    }
}
