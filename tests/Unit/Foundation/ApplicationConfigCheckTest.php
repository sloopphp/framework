<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Config\Config;
use Sloop\Config\InvalidConfigException;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Exception\DatabaseConfigException;
use Sloop\Foundation\Application;
use Sloop\Foundation\Path;
use Sloop\Log\Exception\LogConfigException;
use Sloop\Log\Log;
use Sloop\Log\LogManager;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Foundation\Stub\ConfigRecordingChannelFactory;
use Sloop\Validation\ValidationMessages;

final class ApplicationConfigCheckTest extends TestCase
{
    use ThrowsAssertions;

    private string $tmpDir;

    private function writeConfig(string $file, string $content): void
    {
        file_put_contents($this->tmpDir . '/config/' . $file, $content);
    }

    private function logManager(Application $app): LogManager
    {
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);

        return $manager;
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_app_config_check_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/config');

        Path::reset();
        Config::reset();
        Log::reset();
        ValidationMessages::reset();
    }

    protected function tearDown(): void
    {
        Path::reset();
        Config::reset();
        Log::reset();
        ValidationMessages::reset();
        ConfigRecordingChannelFactory::$lastConfig = null;

        foreach (['response.php', 'log.php', 'database.php', 'middleware.php'] as $file) {
            if (is_file($this->tmpDir . '/config/' . $file)) {
                unlink($this->tmpDir . '/config/' . $file);
            }
        }
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    /**
     * @return iterable<string, array{string, string, class-string<InvalidConfigException>, string}>
     */
    public static function rejectedConfigProvider(): iterable
    {
        $connection = '"driver" => "mysql", "host" => "localhost", "database" => "app"';
        $logLevels  = 'debug, info, notice, warning, error, critical, alert, emergency';
        $processors = 'web, introspection, memory_usage, memory_peak, hostname, process_id';

        yield 'response with an unknown key' => [
            'response.php', '["json_option" => 0]',
            InvalidConfigException::class, 'response has an unsupported key "json_option".',
        ];
        yield 'response.json_options not an integer' => [
            'response.php', '["json_options" => "pretty"]',
            InvalidConfigException::class, 'response.json_options must be an integer of JSON_* flags.',
        ];

        yield 'log with an unknown key' => [
            'log.php', '["channel" => []]',
            LogConfigException::class, 'log has an unsupported key "channel".',
        ];
        yield 'log.default not a string' => [
            'log.php', '["default" => 42]',
            LogConfigException::class, 'log.default must be a string.',
        ];
        yield 'log.default naming an undefined channel' => [
            'log.php', '["default" => "audit", "channels" => ["app" => []]]',
            LogConfigException::class, 'log.default names the channel "audit", which log.channels does not define.',
        ];
        yield 'log.default written without log.channels' => [
            'log.php', '["default" => "app"]',
            LogConfigException::class, 'log.default names the channel "app", which log.channels does not define.',
        ];
        yield 'log.channels not an array' => [
            'log.php', '["channels" => "app"]',
            LogConfigException::class, 'log.channels must be an array of channel names mapped to settings.',
        ];
        yield 'log channel without a name' => [
            'log.php', '["channels" => [["driver" => "stream"]]]',
            LogConfigException::class, 'log.channels has a channel without a name (key 0); write the channel name as the key.',
        ];
        yield 'log channel not an array' => [
            'log.php', '["channels" => ["app" => "stream"]]',
            LogConfigException::class, 'log.channels.app must be an array of settings.',
        ];
        yield 'log channel with an unknown driver' => [
            'log.php', '["channels" => ["app" => ["driver" => "syslog"]]]',
            LogConfigException::class, 'log.channels.app.driver must be one of: stream, daily, custom.',
        ];
        yield 'log channel with a driver that is not a string' => [
            'log.php', '["channels" => ["app" => ["driver" => 1]]]',
            LogConfigException::class, 'log.channels.app.driver must be one of: stream, daily, custom.',
        ];
        yield 'stream channel with an unknown key' => [
            'log.php', '["channels" => ["app" => ["severity" => "info"]]]',
            LogConfigException::class, 'log.channels.app has an unsupported key "severity".',
        ];
        yield 'stream channel with a key that is not a name' => [
            'log.php', '["channels" => ["app" => ["driver" => "stream", 0 => "info"]]]',
            LogConfigException::class, 'log.channels.app has an unsupported key "0".',
        ];
        yield 'daily channel with a key of the stream driver' => [
            'log.php', '["channels" => ["app" => ["driver" => "daily", "path" => "/tmp/app.log", "stream" => "php://stderr"]]]',
            LogConfigException::class, 'log.channels.app has an unsupported key "stream".',
        ];
        yield 'log channel with an unknown level' => [
            'log.php', '["channels" => ["app" => ["level" => "verbose"]]]',
            LogConfigException::class, 'log.channels.app.level must be one of: ' . $logLevels . '.',
        ];
        yield 'log channel with a level that is not a string' => [
            'log.php', '["channels" => ["app" => ["level" => 100]]]',
            LogConfigException::class, 'log.channels.app.level must be one of: ' . $logLevels . '.',
        ];
        yield 'log channel with an unknown formatter' => [
            'log.php', '["channels" => ["app" => ["formatter" => "xml"]]]',
            LogConfigException::class, 'log.channels.app.formatter must be one of: json, line.',
        ];
        yield 'log channel with a formatter name in upper case' => [
            'log.php', '["channels" => ["app" => ["formatter" => "JSON"]]]',
            LogConfigException::class, 'log.channels.app.formatter must be one of: json, line.',
        ];
        yield 'log channel with processors that are not a list' => [
            'log.php', '["channels" => ["app" => ["processors" => "web"]]]',
            LogConfigException::class, 'log.channels.app.processors must be a list of processor names.',
        ];
        yield 'log channel with an unknown processor' => [
            'log.php', '["channels" => ["app" => ["processors" => ["web", "wbe"]]]]',
            LogConfigException::class, 'log.channels.app.processors must contain only: ' . $processors . '.',
        ];
        yield 'log channel with a processor that is not a string' => [
            'log.php', '["channels" => ["app" => ["processors" => [1]]]]',
            LogConfigException::class, 'log.channels.app.processors must contain only: ' . $processors . '.',
        ];
        yield 'log channel with auto_context that is not a boolean' => [
            'log.php', '["channels" => ["app" => ["auto_context" => "no"]]]',
            LogConfigException::class, 'log.channels.app.auto_context must be true or false.',
        ];
        yield 'stream channel with an empty stream' => [
            'log.php', '["channels" => ["app" => ["stream" => ""]]]',
            LogConfigException::class, 'log.channels.app.stream must be a stream path or URL.',
        ];
        yield 'stream channel with a stream that is not a string' => [
            'log.php', '["channels" => ["app" => ["stream" => 2]]]',
            LogConfigException::class, 'log.channels.app.stream must be a stream path or URL.',
        ];
        yield 'daily channel without a path' => [
            'log.php', '["channels" => ["app" => ["driver" => "daily"]]]',
            LogConfigException::class, 'log.channels.app.path must be the file the daily driver writes to.',
        ];
        yield 'daily channel with days that is not an integer' => [
            'log.php', '["channels" => ["app" => ["driver" => "daily", "path" => "/tmp/app.log", "days" => "7"]]]',
            LogConfigException::class, 'log.channels.app.days must be an integer.',
        ];
        yield 'custom channel without a factory' => [
            'log.php', '["channels" => ["app" => ["driver" => "custom"]]]',
            LogConfigException::class, 'log.channels.app.factory must be the class name of a ChannelFactoryInterface implementation.',
        ];
        yield 'custom channel with an unknown processor' => [
            'log.php', '["channels" => ["app" => ["driver" => "custom", "factory" => "App\\\\LogFactory", "processors" => ["wbe"]]]]',
            LogConfigException::class, 'log.channels.app.processors must contain only: ' . $processors . '.',
        ];
        yield 'custom channel with auto_context that is not a boolean' => [
            'log.php', '["channels" => ["app" => ["driver" => "custom", "factory" => "App\\\\LogFactory", "auto_context" => 0]]]',
            LogConfigException::class, 'log.channels.app.auto_context must be true or false.',
        ];

        yield 'database with an unknown key' => [
            'database.php', '["connection" => []]',
            DatabaseConfigException::class, 'database has an unsupported key "connection".',
        ];
        yield 'database.default not a string' => [
            'database.php', '["default" => 1, "connections" => ["main" => [' . $connection . ']]]',
            DatabaseConfigException::class, 'database.default must be a string.',
        ];
        yield 'database.default naming an undefined connection' => [
            'database.php', '["default" => "primary", "connections" => ["main" => [' . $connection . ']]]',
            DatabaseConfigException::class, 'database.default names the connection "primary", which database.connections does not define.',
        ];
        yield 'database.default written without database.connections' => [
            'database.php', '["default" => "main"]',
            DatabaseConfigException::class, 'database.default names the connection "main", which database.connections does not define.',
        ];
        yield 'database.connections not an array' => [
            'database.php', '["connections" => "main"]',
            DatabaseConfigException::class, 'database.connections must be an array of connection names mapped to settings.',
        ];
        yield 'database connection without a name' => [
            'database.php', '["connections" => [[' . $connection . ']]]',
            DatabaseConfigException::class, 'database.connections has a connection without a name (key 0); write the connection name as the key.',
        ];
        yield 'database connection not an array' => [
            'database.php', '["connections" => ["main" => "mysql"]]',
            DatabaseConfigException::class, 'database.connections.main must be an array of settings.',
        ];
        yield 'database connection with a setting without a name' => [
            'database.php', '["connections" => ["main" => [' . $connection . ', 0 => "x"]]]',
            DatabaseConfigException::class, 'database.connections.main has a setting without a name (key 0).',
        ];
        yield 'database connection with an unknown key' => [
            'database.php', '["connections" => ["main" => [' . $connection . ', "hots" => "db"]]]',
            DatabaseConfigException::class, 'Connection [main]: unsupported config key "hots".',
        ];
        yield 'database connection missing a required key, after a valid one' => [
            'database.php', '["connections" => ["main" => [' . $connection . '], "audit" => ["driver" => "mysql", "host" => "localhost"]]]',
            DatabaseConfigException::class, 'Connection [audit]: missing required config key "database".',
        ];

        yield 'middleware entry that is not a string' => [
            'middleware.php', '[\Sloop\Tests\Unit\Foundation\Stub\XRequestIdMiddleware::class, 42]',
            InvalidConfigException::class, 'config/middleware.php must list middleware class names; the entry at key 1 is int.',
        ];
        yield 'middleware entry that is null' => [
            'middleware.php', '[null]',
            InvalidConfigException::class, 'config/middleware.php must list middleware class names; the entry at key 0 is null.',
        ];
    }

    /**
     * @param class-string<InvalidConfigException> $exception
     */
    #[DataProvider('rejectedConfigProvider')]
    public function testBootRejectsMalformedConfig(string $file, string $config, string $exception, string $message): void
    {
        $this->writeConfig($file, '<?php return ' . $config . ';');

        $thrown = $this->assertThrows(InvalidConfigException::class, fn (): Application => new Application($this->tmpDir));

        $this->assertSame($exception, $thrown::class);
        $this->assertSame($message, $thrown->getMessage());
    }

    public function testBootAcceptsLogChannelsWithoutLogDefault(): void
    {
        $this->writeConfig('log.php', '<?php return ["channels" => ["audit" => ["level" => "error"]]];');

        $manager = $this->logManager(new Application($this->tmpDir));

        $this->assertSame('app', $manager->defaultChannel);
        $handler = $manager->channel('audit')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame(Level::Error, $handler->getLevel());
    }

    public function testBootAcceptsEveryWrittenStreamAndDailyEntry(): void
    {
        $this->writeConfig('log.php', '<?php return [
            "default"  => "app",
            "channels" => [
                "app" => [
                    "driver"       => "stream",
                    "stream"       => "php://stdout",
                    "level"        => "WARNING",
                    "formatter"    => "",
                    "processors"   => ["hostname"],
                    "auto_context" => false,
                ],
                "file" => [
                    "driver"       => "daily",
                    "path"         => "' . $this->tmpDir . '/app.log",
                    "days"         => 7,
                    "level"        => "info",
                    "formatter"    => "json",
                    "processors"   => [],
                    "auto_context" => true,
                ],
                "nulls" => [
                    "driver"       => null,
                    "stream"       => null,
                    "level"        => null,
                    "formatter"    => null,
                    "processors"   => null,
                    "auto_context" => null,
                ],
            ],
        ];');

        $manager = $this->logManager(new Application($this->tmpDir));

        $this->assertSame('app', $manager->defaultChannel);
        $handler = $manager->channel('app')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame(Level::Warning, $handler->getLevel());
        $file = $manager->channel('file')->getHandlers()[0];
        $this->assertInstanceOf(RotatingFileHandler::class, $file);
        $this->assertSame(Level::Info, $file->getLevel());
        $nulls = $manager->channel('nulls')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $nulls);
        $this->assertSame(Level::Debug, $nulls->getLevel());
    }

    public function testBootLeavesTheKeysOfACustomChannelToItsFactory(): void
    {
        $this->writeConfig('log.php', '<?php return ["channels" => ["app" => [
            "driver"  => "custom",
            "factory" => \\' . ConfigRecordingChannelFactory::class . '::class,
            "level"   => 100,
            "url"     => "https://logs.example.com",
            0         => "unnamed",
        ]]];');
        ConfigRecordingChannelFactory::$lastConfig = null;

        $manager = $this->logManager(new Application($this->tmpDir));

        $this->assertInstanceOf(TestHandler::class, $manager->channel('app')->getHandlers()[0]);
        // The key without a name is left out; the others reach the factory as written.
        $this->assertSame([
            'driver'  => 'custom',
            'factory' => ConfigRecordingChannelFactory::class,
            'level'   => 100,
            'url'     => 'https://logs.example.com',
        ], ConfigRecordingChannelFactory::$lastConfig);
    }

    public function testBootLeavesAnUnresolvableCustomFactoryToTheFirstUse(): void
    {
        $this->writeConfig('log.php', '<?php return ["channels" => ["app" => ["driver" => "custom", "factory" => "App\\\\NoSuchLogFactory"]]];');

        $manager = $this->logManager(new Application($this->tmpDir));

        $thrown = $this->assertThrows(LogConfigException::class, static fn () => $manager->channel('app'));
        $this->assertSame('Custom log factory cannot be resolved: App\\NoSuchLogFactory', $thrown->getMessage());
    }

    public function testBootSkipsALogChannelWrittenAsNull(): void
    {
        $this->writeConfig('log.php', '<?php return ["default" => "app", "channels" => ["audit" => null, "app" => ["level" => "error"]]];');

        $manager = $this->logManager(new Application($this->tmpDir));

        // app, written after the null entry, is still read.
        $app = $manager->channel('app')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $app);
        $this->assertSame(Level::Error, $app->getLevel());
        // audit is not configured, so it is the unconfigured channel at debug.
        $handler = $manager->channel('audit')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame(Level::Debug, $handler->getLevel());
    }

    public function testBootRejectsLogDefaultNamingAChannelWrittenAsNull(): void
    {
        $this->writeConfig('log.php', '<?php return ["default" => "audit", "channels" => ["audit" => null]];');

        $thrown = $this->assertThrows(LogConfigException::class, fn (): Application => new Application($this->tmpDir));

        $this->assertSame('log.default names the channel "audit", which log.channels does not define.', $thrown->getMessage());
    }

    public function testBootSkipsADatabaseConnectionWrittenAsNull(): void
    {
        $this->writeConfig('database.php', '<?php return ["default" => "main", "connections" => [
            "replica" => null,
            "main"    => ["driver" => "mysql", "host" => "localhost", "database" => "app", "replica_selector" => "nope"],
        ]];');

        $manager = new Application($this->tmpDir)->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // main, written after the null entry, is still read: using it reaches
        // the replica_selector check, not "not defined".
        $thrown = $this->assertThrows(DatabaseConfigException::class, static fn () => $manager->connection());
        $this->assertSame('No replica selector is registered for "nope". Registered: random.', $thrown->getMessage());
    }

    public function testBootRejectsDatabaseDefaultNamingAConnectionWrittenAsNull(): void
    {
        $this->writeConfig('database.php', '<?php return ["default" => "replica", "connections" => ["replica" => null]];');

        $thrown = $this->assertThrows(DatabaseConfigException::class, fn (): Application => new Application($this->tmpDir));

        $this->assertSame('database.default names the connection "replica", which database.connections does not define.', $thrown->getMessage());
    }

    public function testBootLeavesAnUnregisteredReplicaSelectorToTheFirstUse(): void
    {
        $this->writeConfig('database.php', '<?php return ["default" => "main", "connections" => ["main" => [
            "driver" => "mysql", "host" => "localhost", "database" => "app", "replica_selector" => "nope",
        ]]];');

        $manager = new Application($this->tmpDir)->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        $thrown = $this->assertThrows(DatabaseConfigException::class, static fn () => $manager->connection());
        $this->assertSame('No replica selector is registered for "nope". Registered: random.', $thrown->getMessage());
    }

    public function testBootLeavesAnUnwrittenDatabaseDefaultToTheFirstUse(): void
    {
        $this->writeConfig('database.php', '<?php return ["connections" => ["main" => ["driver" => "mysql", "host" => "localhost", "database" => "app"]]];');

        $manager = new Application($this->tmpDir)->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        $thrown = $this->assertThrows(DatabaseConfigException::class, static fn () => $manager->connection());
        $this->assertSame('Database connection [] is not defined.', $thrown->getMessage());
    }
}
