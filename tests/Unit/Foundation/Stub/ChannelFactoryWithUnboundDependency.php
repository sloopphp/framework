<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Sloop\Log\ChannelFactoryInterface;

/**
 * Test stub factory whose constructor needs an interface the container cannot resolve.
 *
 * Used to verify that Application::resolveChannelFactory() reports only a
 * wrong factory class name as a log config error.
 */
final readonly class ChannelFactoryWithUnboundDependency implements ChannelFactoryInterface
{
    public function __construct(private UnboundLogDependency $dependency)
    {
    }

    public function create(string $name, array $config): Logger
    {
        return new Logger($name . $this->dependency::class, [new TestHandler()]);
    }
}
