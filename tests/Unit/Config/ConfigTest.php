<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Config\Config;

final class ConfigTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        Config::reset();
        $this->fixturesPath = __DIR__ . '/fixtures';
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    // ---------------------------------------------------------------
    // load
    // ---------------------------------------------------------------

    public function testLoadReadsPhpFilesFromDirectory(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame('Sloop', Config::get('app.name'));
        $this->assertSame('localhost', Config::get('database.host'));
        $this->assertSame(3306, Config::get('database.port'));
    }

    public function testLoadMergesEnvironmentOverrides(): void
    {
        Config::load($this->fixturesPath, 'production');

        $this->assertSame('localhost', Config::get('database.host'));
        $this->assertSame(3306, Config::get('database.port'));
        $this->assertTrue(Config::get('database.pooling'));
    }

    public function testLoadIgnoresNonexistentEnvironmentDirectory(): void
    {
        Config::load($this->fixturesPath, 'nonexistent');

        $this->assertSame('Sloop', Config::get('app.name'));
    }

    public function testLoadThrowsForNonexistentDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Config directory does not exist: /nonexistent/path');

        Config::load('/nonexistent/path');
    }

    public function testLoadThrowsWhenCalledTwice(): void
    {
        Config::load($this->fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuration has already been loaded');

        Config::load($this->fixturesPath);
    }

    public function testLoadThrowsForNonArrayConfigFile(): void
    {
        $invalidPath = $this->fixturesPath . '/invalid';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Config file must return an array, got string: ' . $invalidPath . DIRECTORY_SEPARATOR . 'broken.php'
        );

        Config::load($invalidPath);
    }

    // ---------------------------------------------------------------
    // get
    // ---------------------------------------------------------------

    public function testGetReturnsDotNotatedValue(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame('Sloop', Config::get('app.name'));
        $this->assertSame(false, Config::get('app.debug'));
        $this->assertSame('UTC', Config::get('app.timezone'));
    }

    public function testGetReturnsTopLevelArray(): void
    {
        Config::load($this->fixturesPath);

        $app = Config::get('app');

        $this->assertIsArray($app);
        $this->assertSame('Sloop', $app['name']);
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        Config::load($this->fixturesPath);

        $this->assertNull(Config::get('nonexistent'));
        $this->assertSame('fallback', Config::get('nonexistent', 'fallback'));
        $this->assertSame('default', Config::get('app.missing', 'default'));
    }

    public function testGetReturnsNullBeforeLoad(): void
    {
        $this->assertNull(Config::get('app.name'));
    }

    // ---------------------------------------------------------------
    // has
    // ---------------------------------------------------------------

    public function testHasReturnsTrueForExistingKey(): void
    {
        Config::load($this->fixturesPath);

        $this->assertTrue(Config::has('app'));
        $this->assertTrue(Config::has('app.name'));
        $this->assertTrue(Config::has('database.port'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        Config::load($this->fixturesPath);

        $this->assertFalse(Config::has('nonexistent'));
        $this->assertFalse(Config::has('app.missing'));
    }

    // ---------------------------------------------------------------
    // all
    // ---------------------------------------------------------------

    public function testAllReturnsEntireConfiguration(): void
    {
        Config::load($this->fixturesPath);

        $all = Config::all();

        $this->assertArrayHasKey('app', $all);
        $this->assertArrayHasKey('database', $all);
        $this->assertSame('Sloop', Config::get('app.name'));
    }

    public function testAllReturnsEmptyBeforeLoad(): void
    {
        $this->assertSame([], Config::all());
    }

    // ---------------------------------------------------------------
    // isLoaded
    // ---------------------------------------------------------------

    public function testIsLoadedReturnsFalseBeforeLoad(): void
    {
        $this->assertFalse(Config::isLoaded());
    }

    public function testIsLoadedReturnsTrueAfterLoad(): void
    {
        Config::load($this->fixturesPath);

        $this->assertTrue(Config::isLoaded());
    }

    // ---------------------------------------------------------------
    // withConfig
    // ---------------------------------------------------------------

    public function testWithConfigOverridesValues(): void
    {
        Config::load($this->fixturesPath);

        Config::withConfig(['app.name' => 'TestApp'], function () {
            $this->assertSame('TestApp', Config::get('app.name'));
        });

        $this->assertSame('Sloop', Config::get('app.name'));
    }

    public function testWithConfigOverridesMultipleValues(): void
    {
        Config::load($this->fixturesPath);

        Config::withConfig([
            'app.name' => 'Override',
            'database.host' => 'test-db',
        ], function () {
            $this->assertSame('Override', Config::get('app.name'));
            $this->assertSame('test-db', Config::get('database.host'));
        });

        $this->assertSame('Sloop', Config::get('app.name'));
        $this->assertSame('localhost', Config::get('database.host'));
    }

    public function testWithConfigRestoresOnException(): void
    {
        Config::load($this->fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error');

        try {
            Config::withConfig(['app.name' => 'Temp'], static function (): never {
                throw new RuntimeException('error');
            });
        } finally {
            $this->assertSame('Sloop', Config::get('app.name'));
        }
    }

    public function testWithConfigRestoresOnError(): void
    {
        Config::load($this->fixturesPath);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('fatal');

        try {
            Config::withConfig(['app.name' => 'Temp'], static function (): never {
                throw new \Error('fatal');
            });
        } finally {
            $this->assertSame('Sloop', Config::get('app.name'));
        }
    }

    public function testWithConfigReturnsCallbackResult(): void
    {
        Config::load($this->fixturesPath);

        $result = Config::withConfig(['app.name' => 'Test'], function () {
            return Config::get('app.name');
        });

        $this->assertSame('Test', $result);
    }

    public function testWithConfigSupportsNestedCalls(): void
    {
        Config::load($this->fixturesPath);

        Config::withConfig(['app.name' => 'Level1'], function () {
            $this->assertSame('Level1', Config::get('app.name'));

            Config::withConfig(['app.name' => 'Level2'], function () {
                $this->assertSame('Level2', Config::get('app.name'));
            });

            $this->assertSame('Level1', Config::get('app.name'));
        });

        $this->assertSame('Sloop', Config::get('app.name'));
    }

    // ---------------------------------------------------------------
    // Environment override merging
    // ---------------------------------------------------------------

    public function testEnvironmentOverridePreservesBaseValues(): void
    {
        Config::load($this->fixturesPath, 'production');

        $this->assertSame('Sloop', Config::get('app.name'));
        $this->assertSame('myapp', Config::get('database.name'));
    }

    public function testEnvironmentOverrideOnlyAffectsSpecifiedKeys(): void
    {
        Config::load($this->fixturesPath, 'production');

        $this->assertSame('localhost', Config::get('database.host'));
        $this->assertSame(3306, Config::get('database.port'));
        $this->assertSame('myapp', Config::get('database.name'));
        $this->assertTrue(Config::get('database.pooling'));
    }
}
