<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Config\Config;
use Sloop\Config\InvalidConfigException;
use Sloop\Tests\Support\ThrowsAssertions;

final class ConfigTest extends TestCase
{
    use ThrowsAssertions;

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

    public function testLoadThrowsForEnvironmentNameWithPathTraversal(): void
    {
        // The environment name becomes a path segment whose PHP files get
        // executed, so a tainted APP_ENV must not escape the config directory.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains('Invalid environment name: ../evil (only [A-Za-z0-9_-] is allowed)');

        Config::load($this->fixturesPath, '../evil');
    }

    public function testLoadThrowsForEnvironmentNameWithDirectorySeparator(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains('Invalid environment name: sub/dir (only [A-Za-z0-9_-] is allowed)');

        Config::load($this->fixturesPath, 'sub/dir');
    }

    public function testLoadThrowsForNonexistentDirectory(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains('Config directory does not exist: /nonexistent/path');

        Config::load('/nonexistent/path');
    }

    public function testLoadThrowsWhenCalledTwice(): void
    {
        Config::load($this->fixturesPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Configuration has already been loaded');

        Config::load($this->fixturesPath);
    }

    public function testLoadThrowsForNonArrayConfigFile(): void
    {
        $invalidPath = $this->fixturesPath . '/invalid';

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains(
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
        $this->assertFalse(Config::get('app.debug'));
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
    // getString / getInt / getFloat / getBool
    // ---------------------------------------------------------------

    public function testGetStringReturnsStringValue(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame('localhost', Config::getString('database.host'));
    }

    public function testGetStringReturnsDefaultForMissingKey(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame('', Config::getString('database.missing'));
        $this->assertSame('fallback', Config::getString('database.missing', 'fallback'));
    }

    public function testGetStringReturnsDefaultWhenValueIsNotString(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame('fallback', Config::getString('database.port', 'fallback'));
    }

    public function testGetIntReturnsIntValue(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame(3306, Config::getInt('database.port'));
    }

    public function testGetIntReturnsDefaultForMissingKey(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame(0, Config::getInt('database.missing'));
        $this->assertSame(5432, Config::getInt('database.missing', 5432));
    }

    public function testGetIntRejectsNumericString(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame(5432, Config::getInt('database.port_as_string', 5432));
    }

    public function testGetFloatReturnsFloatValue(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame(2.5, Config::getFloat('database.timeout'));
    }

    public function testGetFloatPromotesIntValue(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame(3306.0, Config::getFloat('database.port'));
    }

    public function testGetFloatReturnsDefaultForMissingKey(): void
    {
        Config::load($this->fixturesPath);

        $this->assertSame(0.0, Config::getFloat('database.missing'));
        $this->assertSame(1.5, Config::getFloat('database.missing', 1.5));
    }

    public function testGetBoolReturnsBoolValue(): void
    {
        Config::load($this->fixturesPath);

        // Pass a default that differs from the declared value so the test
        // fails if the default is returned instead of the configured one.
        $this->assertFalse(Config::getBool('database.pooling', true));
    }

    public function testGetBoolReturnsDefaultForMissingKey(): void
    {
        Config::load($this->fixturesPath);

        $this->assertFalse(Config::getBool('database.missing'));
        $this->assertTrue(Config::getBool('database.missing', true));
    }

    public function testGetBoolReturnsDefaultWhenValueIsNotBool(): void
    {
        Config::load($this->fixturesPath);

        $this->assertTrue(Config::getBool('database.port', true));
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

        $thrown = $this->assertThrows(
            RuntimeException::class,
            static fn (): mixed => Config::withConfig(['app.name' => 'Temp'], static function (): never {
                throw new RuntimeException('error');
            }),
        );

        $this->assertSame('error', $thrown->getMessage());
        $this->assertSame('Sloop', Config::get('app.name'));
    }

    public function testWithConfigRestoresOnError(): void
    {
        Config::load($this->fixturesPath);

        $thrown = $this->assertThrows(
            \Error::class,
            static fn (): mixed => Config::withConfig(['app.name' => 'Temp'], static function (): never {
                throw new \Error('fatal');
            }),
        );

        $this->assertSame('fatal', $thrown->getMessage());
        $this->assertSame('Sloop', Config::get('app.name'));
    }

    public function testWithConfigReturnsCallbackResult(): void
    {
        Config::load($this->fixturesPath);

        $result = Config::withConfig(['app.name' => 'Test'], fn () => Config::get('app.name'));

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

    public function testEnvironmentOverrideReplacesANestedListInsteadOfAppendingToIt(): void
    {
        Config::load($this->fixturesPath, 'production');

        $this->assertSame([['host' => 'replica-1'], ['host' => 'replica-2']], Config::get('lists.connections.main.read'));
        $this->assertSame('db', Config::get('lists.connections.main.host'));
    }

    public function testEnvironmentOverrideWithAnEmptyListEmptiesAList(): void
    {
        Config::load($this->fixturesPath, 'production');

        $this->assertSame([], Config::get('lists.emptied'));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function listOverrideProvider(): array
    {
        return [
            'a list replacing an empty list'          => ['lists.empty_to_list', ['x']],
            'a list replacing a scalar'               => ['lists.scalar_to_list', ['x']],
            'a list missing from the base'            => ['lists.new_list', ['x', 'y']],
            'a list the environment does not mention' => ['lists.untouched', ['a', 'b']],
            'an associative array over a scalar'      => ['lists.scalar_to_assoc', ['key' => 'value']],
            'a scalar over an associative array'      => ['lists.assoc_to_scalar', 'single'],
            'a list with gaps replacing a list'       => ['lists.gapped', [1 => 'prod']],
            'an integer-keyed map replacing one'      => ['lists.int_map', [2 => 10]],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mismatchedShapeProvider(): array
    {
        return [
            'an empty array over an associative array'   => ['empty_over_assoc', 's.headers'],
            'a list over an associative array'           => ['list_over_assoc', 's.headers'],
            'an associative array over a list'           => ['assoc_over_list', 's.origins'],
            'string and integer keys over a list'        => ['mixed_keys_over_list', 's.items'],
            'a list over an associative array, nested'   => ['nested_list_over_assoc', 's.pool.main.options'],
        ];
    }

    #[DataProvider('mismatchedShapeProvider')]
    public function testEnvironmentOverrideWhoseShapeDiffersFromTheBaseThrows(string $fixture, string $key): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains('Config "' . $key . '" in environment "production"');

        Config::load(__DIR__ . '/fixtures-shape/' . $fixture, 'production');
    }

    public function testEnvironmentOverrideWithAnAssociativeArrayFillsAnEmptyArray(): void
    {
        Config::load(__DIR__ . '/fixtures-shape/assoc_over_empty', 'production');

        $this->assertSame(['X-Frame-Options' => 'DENY'], Config::get('s.headers'));
    }

    public function testEnvironmentOverrideAppendsIntegerKeysInsideAnAssociativeArray(): void
    {
        Config::load(__DIR__ . '/fixtures-shape/int_keys_inside_assoc', 'production');

        $this->assertSame(['key' => 'value', 'other' => 'x', 0 => 'y'], Config::get('s.items'));
    }

    #[DataProvider('listOverrideProvider')]
    public function testEnvironmentOverrideOfListsAndMismatchedShapes(string $key, mixed $expected): void
    {
        Config::load($this->fixturesPath, 'production');

        $this->assertSame($expected, Config::get($key));
    }

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
