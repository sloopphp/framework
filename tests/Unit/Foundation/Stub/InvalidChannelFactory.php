<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

/**
 * Test stub that does NOT implement ChannelFactoryInterface.
 *
 * Used to verify the type check failure path in Application::resolveChannelFactory().
 */
final class InvalidChannelFactory
{
}
