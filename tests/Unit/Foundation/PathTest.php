<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Foundation\Path;

final class PathTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $resolved = realpath(__DIR__ . '/../../..');
        $this->assertIsString($resolved, 'Fixture path must exist');
        $this->fixturePath = $resolved;
        Path::reset();
    }

    protected function tearDown(): void
    {
        Path::reset();
    }

    // -------------------------------------------------------
    // init
    // -------------------------------------------------------

    public function testInitSucceedsWithValidDirectory(): void
    {
        Path::init($this->fixturePath);

        $this->assertTrue(Path::isInitialized());
    }

    public function testInitThrowsExceptionForNonExistentDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Base path does not exist');

        Path::init('/nonexistent/path/that/does/not/exist');
    }

    // -------------------------------------------------------
    // base
    // -------------------------------------------------------

    public function testBaseReturnsBasePath(): void
    {
        Path::init($this->fixturePath);

        $this->assertSame($this->fixturePath, Path::base());
    }

    public function testBaseAppendsRelativePath(): void
    {
        Path::init($this->fixturePath);

        $expected = $this->fixturePath . DIRECTORY_SEPARATOR . 'composer.json';
        $this->assertSame($expected, Path::base('composer.json'));
    }

    public function testBaseStripsLeadingSlash(): void
    {
        Path::init($this->fixturePath);

        $expected = $this->fixturePath . DIRECTORY_SEPARATOR . 'src';
        $this->assertSame($expected, Path::base('/src'));
    }

    public function testBaseThrowsExceptionWhenNotInitialized(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Path has not been initialized');

        Path::base();
    }

    // -------------------------------------------------------
    // Directory accessors
    // -------------------------------------------------------

    public function testSrcReturnsSrcPath(): void
    {
        Path::init($this->fixturePath);

        $expected = $this->fixturePath . DIRECTORY_SEPARATOR . 'src';
        $this->assertSame($expected, Path::src());
    }

    public function testSrcAppendsRelativePath(): void
    {
        Path::init($this->fixturePath);

        $expected = $this->fixturePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Sloop';
        $this->assertSame($expected, Path::src('Sloop'));
    }

    public function testConfigReturnsConfigPath(): void
    {
        Path::init($this->fixturePath);

        $expected = $this->fixturePath . DIRECTORY_SEPARATOR . 'config';
        $this->assertSame($expected, Path::config());
    }

    public function testStorageReturnsStoragePath(): void
    {
        Path::init($this->fixturePath);

        $expected = $this->fixturePath . DIRECTORY_SEPARATOR . 'storage';
        $this->assertSame($expected, Path::storage());
    }

    public function testPublicReturnsPublicPath(): void
    {
        Path::init($this->fixturePath);

        $expected = $this->fixturePath . DIRECTORY_SEPARATOR . 'public';
        $this->assertSame($expected, Path::public());
    }

    public function testRoutesReturnsRoutesPath(): void
    {
        Path::init($this->fixturePath);

        $expected = $this->fixturePath . DIRECTORY_SEPARATOR . 'routes';
        $this->assertSame($expected, Path::routes());
    }

    public function testTestsReturnsTestsPath(): void
    {
        Path::init($this->fixturePath);

        $expected = $this->fixturePath . DIRECTORY_SEPARATOR . 'tests';
        $this->assertSame($expected, Path::tests());
    }

    // -------------------------------------------------------
    // reset
    // -------------------------------------------------------

    public function testResetClearsInitializedState(): void
    {
        Path::init($this->fixturePath);
        $this->assertTrue(Path::isInitialized());

        Path::reset();
        $this->assertFalse(Path::isInitialized());
    }

    // -------------------------------------------------------
    // Helper functions
    // -------------------------------------------------------

    public function testBasePathHelperFunction(): void
    {
        Path::init($this->fixturePath);

        $this->assertSame(Path::base(), base_path());
        $this->assertSame(Path::base('src'), base_path('src'));
    }

    public function testSrcPathHelperFunction(): void
    {
        Path::init($this->fixturePath);

        $this->assertSame(Path::src(), src_path());
    }

    public function testConfigPathHelperFunction(): void
    {
        Path::init($this->fixturePath);

        $this->assertSame(Path::config(), config_path());
    }

    public function testStoragePathHelperFunction(): void
    {
        Path::init($this->fixturePath);

        $this->assertSame(Path::storage(), storage_path());
    }

    public function testPublicPathHelperFunction(): void
    {
        Path::init($this->fixturePath);

        $this->assertSame(Path::public(), public_path());
    }

    public function testRoutesPathHelperFunction(): void
    {
        Path::init($this->fixturePath);

        $this->assertSame(Path::routes(), routes_path());
    }

    public function testTestsPathHelperFunction(): void
    {
        Path::init($this->fixturePath);

        $this->assertSame(Path::tests(), tests_path());
    }

    public function testInitThrowsExceptionForEmptyString(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Base path must not be empty');

        Path::init('');
    }
}
