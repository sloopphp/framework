<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log;

use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Sloop\Log\LogManager;

final class LogManagerTest extends TestCase
{
    // ---------------------------------------------------------------
    // channel — default
    // ---------------------------------------------------------------

    public function testChannelReturnsDefaultLogger(): void
    {
        $manager = new LogManager();

        $logger = $manager->channel();

        $this->assertSame('app', $logger->getName());
    }

    public function testChannelWithNullReturnsDefaultLogger(): void
    {
        $manager  = new LogManager();
        $explicit = null;

        $this->assertSame($manager->channel(), $manager->channel($explicit));
    }

    public function testCustomDefaultChannelName(): void
    {
        $manager = new LogManager('web');

        $this->assertSame('web', $manager->channel()->getName());
    }

    // ---------------------------------------------------------------
    // channel — named
    // ---------------------------------------------------------------

    public function testChannelReturnsNamedLogger(): void
    {
        $manager = new LogManager();

        $logger = $manager->channel('audit');

        $this->assertSame('audit', $logger->getName());
    }

    public function testChannelCachesLoggerInstance(): void
    {
        $manager = new LogManager();

        $first  = $manager->channel('audit');
        $second = $manager->channel('audit');

        $this->assertSame($first, $second);
    }

    public function testDifferentChannelsReturnDifferentLoggers(): void
    {
        $manager = new LogManager();

        $audit = $manager->channel('audit');
        $slack = $manager->channel('slack');

        $this->assertNotSame($audit, $slack);
    }

    // ---------------------------------------------------------------
    // addChannel
    // ---------------------------------------------------------------

    public function testAddChannelRegistersPreConfiguredLogger(): void
    {
        $manager = new LogManager();
        $custom  = new Logger('custom', [new TestHandler()]);

        $manager->addChannel('custom', $custom);

        $this->assertSame($custom, $manager->channel('custom'));
    }

    public function testAddChannelOverridesExistingChannel(): void
    {
        $manager = new LogManager();
        $manager->channel('audit');

        $replacement = new Logger('audit', [new TestHandler()]);
        $manager->addChannel('audit', $replacement);

        $this->assertSame($replacement, $manager->channel('audit'));
    }

    // ---------------------------------------------------------------
    // defaultChannel property
    // ---------------------------------------------------------------

    public function testDefaultChannelPropertyReturnsName(): void
    {
        $manager = new LogManager('api');

        $this->assertSame('api', $manager->defaultChannel);
    }

    // ---------------------------------------------------------------
    // default stream and level
    // ---------------------------------------------------------------

    public function testAutoCreatedLoggerHasStreamHandler(): void
    {
        $manager = new LogManager();

        $logger   = $manager->channel();
        $handlers = $logger->getHandlers();

        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(StreamHandler::class, $handlers[0]);
    }

    public function testCustomDefaultLevel(): void
    {
        $manager = new LogManager('app', Level::Warning);

        $logger  = $manager->channel();
        $handler = $logger->getHandlers()[0];

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame(Level::Warning, $handler->getLevel());
    }
}
