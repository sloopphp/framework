<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Sloop\Log\ChannelFactoryInterface;
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
        $manager = new LogManager(defaultChannel: 'app', defaultLevel: Level::Warning);

        $logger  = $manager->channel();
        $handler = $logger->getHandlers()[0];

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame(Level::Warning, $handler->getLevel());
    }

    // ---------------------------------------------------------------
    // Config-driven channels
    // ---------------------------------------------------------------

    public function testStreamDriverCreatesStreamHandler(): void
    {
        $manager = new LogManager(channels: [
            'stderr' => [
                'driver' => 'stream',
                'stream' => 'php://stderr',
                'level'  => 'error',
            ],
        ]);

        $handler = $manager->channel('stderr')->getHandlers()[0];

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame(Level::Error, $handler->getLevel());
    }

    public function testDailyDriverCreatesRotatingFileHandler(): void
    {
        $path    = sys_get_temp_dir() . '/sloop_test_' . uniqid() . '.log';
        $manager = new LogManager(channels: [
            'app' => [
                'driver' => 'daily',
                'path'   => $path,
                'days'   => 14,
                'level'  => 'info',
            ],
        ]);

        $handler = $manager->channel('app')->getHandlers()[0];

        $this->assertInstanceOf(RotatingFileHandler::class, $handler);
        $this->assertSame(Level::Info, $handler->getLevel());
        $this->assertSame(14, (new ReflectionProperty(RotatingFileHandler::class, 'maxFiles'))->getValue($handler));
    }

    public function testDailyDriverDefaultsToUnlimitedRetention(): void
    {
        $path    = sys_get_temp_dir() . '/sloop_test_' . uniqid() . '.log';
        $manager = new LogManager(channels: [
            'app' => [
                'driver' => 'daily',
                'path'   => $path,
            ],
        ]);

        $handler = $manager->channel('app')->getHandlers()[0];

        $this->assertInstanceOf(RotatingFileHandler::class, $handler);
        $this->assertSame(0, (new ReflectionProperty(RotatingFileHandler::class, 'maxFiles'))->getValue($handler));
    }

    /**
     * @return list<array{string, Level}>
     */
    public static function logLevelProvider(): array
    {
        return [
            ['debug', Level::Debug],
            ['info', Level::Info],
            ['notice', Level::Notice],
            ['warning', Level::Warning],
            ['error', Level::Error],
            ['critical', Level::Critical],
            ['alert', Level::Alert],
            ['emergency', Level::Emergency],
        ];
    }

    #[DataProvider('logLevelProvider')]
    public function testAllPsr3LevelsAreResolved(string $levelName, Level $expected): void
    {
        $manager = new LogManager(channels: [
            'app' => [
                'driver' => 'stream',
                'level'  => $levelName,
            ],
        ]);

        $handler = $manager->channel('app')->getHandlers()[0];

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame($expected, $handler->getLevel());
    }

    public function testLevelIsCaseInsensitive(): void
    {
        $manager = new LogManager(channels: [
            'app' => [
                'driver' => 'stream',
                'level'  => 'WARNING',
            ],
        ]);

        $handler = $manager->channel('app')->getHandlers()[0];

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame(Level::Warning, $handler->getLevel());
    }

    public function testJsonFormatterIsAppliedToHandler(): void
    {
        $manager = new LogManager(channels: [
            'app' => [
                'driver'    => 'stream',
                'stream'    => 'php://stderr',
                'formatter' => 'json',
            ],
        ]);

        $handler = $manager->channel('app')->getHandlers()[0];

        $this->assertInstanceOf(FormattableHandlerInterface::class, $handler);
        $this->assertInstanceOf(JsonFormatter::class, $handler->getFormatter());
    }

    public function testLineFormatterIsAppliedToHandler(): void
    {
        $manager = new LogManager(channels: [
            'app' => [
                'driver'    => 'stream',
                'stream'    => 'php://stderr',
                'formatter' => 'line',
            ],
        ]);

        $handler = $manager->channel('app')->getHandlers()[0];

        $this->assertInstanceOf(FormattableHandlerInterface::class, $handler);
        $this->assertInstanceOf(LineFormatter::class, $handler->getFormatter());
    }

    public function testUnknownDriverThrowsException(): void
    {
        $manager = new LogManager(channels: [
            'app' => ['driver' => 'unknown_driver'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown log driver: unknown_driver');

        $manager->channel('app');
    }

    public function testUnknownFormatterThrowsException(): void
    {
        $manager = new LogManager(channels: [
            'app' => [
                'driver'    => 'stream',
                'formatter' => 'xml',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown log formatter: xml');

        $manager->channel('app');
    }

    public function testUnknownLevelThrowsException(): void
    {
        $manager = new LogManager(channels: [
            'app' => [
                'driver' => 'stream',
                'level'  => 'verbose',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown log level: verbose');

        $manager->channel('app');
    }

    public function testDailyDriverRequiresPath(): void
    {
        $manager = new LogManager(channels: [
            'app' => ['driver' => 'daily'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Log driver "daily" requires "path" configuration.');

        $manager->channel('app');
    }

    public function testUnconfiguredChannelFallsBackToDefault(): void
    {
        $manager = new LogManager(channels: [
            'audit' => ['driver' => 'stream'],
        ]);

        $handler = $manager->channel('undefined')->getHandlers()[0];

        $this->assertInstanceOf(StreamHandler::class, $handler);
    }

    // ---------------------------------------------------------------
    // Custom driver
    // ---------------------------------------------------------------

    public function testCustomDriverUsesFactoryResolver(): void
    {
        $factory = new class () implements ChannelFactoryInterface {
            public function create(string $name, array $config): Logger
            {
                return new Logger($name, [new TestHandler()]);
            }
        };

        $manager = new LogManager(
            channels: [
                'syslog' => [
                    'driver'  => 'custom',
                    'factory' => 'MySyslogFactory',
                ],
            ],
            customFactoryResolver: static fn (string $class): ChannelFactoryInterface => $factory,
        );

        $logger = $manager->channel('syslog');

        $this->assertSame('syslog', $logger->getName());
        $this->assertInstanceOf(TestHandler::class, $logger->getHandlers()[0]);
    }

    public function testCustomDriverWithoutFactoryKeyThrowsException(): void
    {
        $manager = new LogManager(channels: [
            'syslog' => ['driver' => 'custom'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Log driver "custom" requires "factory" configuration.');

        $manager->channel('syslog');
    }

    public function testCustomDriverWithoutResolverThrowsException(): void
    {
        $manager = new LogManager(channels: [
            'syslog' => [
                'driver'  => 'custom',
                'factory' => 'MySyslogFactory',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Custom log driver requires a factory resolver. Channel: syslog');

        $manager->channel('syslog');
    }
}
