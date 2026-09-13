<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Database\Migration\MigrationDirectory;
use Sloop\Database\Migration\MigrationFile;
use Sloop\Tests\Support\ThrowsAssertions;
use UnexpectedValueException;

final class MigrationDirectoryTest extends TestCase
{
    use ThrowsAssertions;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sloop_test_migrations_' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    /**
     * @param  list<MigrationFile> $files
     * @return list<string>
     */
    private function names(array $files): array
    {
        return array_map(static fn (MigrationFile $file): string => $file->name, $files);
    }

    private function touch(string $fileName): void
    {
        file_put_contents($this->directory . '/' . $fileName, '');
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }

            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }

    public function testFilesReturnsEmptyListForEmptyDirectory(): void
    {
        $this->assertSame([], new MigrationDirectory($this->directory)->files());
    }

    public function testFilesReturnsMigrationsOrderedByName(): void
    {
        $this->touch('20260503000000_create_comments_table.php');
        $this->touch('20260501000000_create_users_table.php');
        $this->touch('20260502000000_create_posts_table.php');

        $this->assertSame(
            [
                '20260501000000_create_users_table',
                '20260502000000_create_posts_table',
                '20260503000000_create_comments_table',
            ],
            $this->names(new MigrationDirectory($this->directory)->files()),
        );
    }

    public function testFilesOrdersSameVersionByTheRestOfTheName(): void
    {
        $this->touch('20260501000000_create_users_table.php');
        $this->touch('20260501000000_add_email_to_users.php');

        $this->assertSame(
            [
                '20260501000000_add_email_to_users',
                '20260501000000_create_users_table',
            ],
            $this->names(new MigrationDirectory($this->directory)->files()),
        );
    }

    public function testFilesOrdersByByteValueRatherThanNumericValue(): void
    {
        $this->touch('20260501000000_b10.php');
        $this->touch('20260501000000_b9.php');

        $this->assertSame(
            ['20260501000000_b10', '20260501000000_b9'],
            $this->names(new MigrationDirectory($this->directory)->files()),
        );
    }

    public function testFilesReturnsPathInsideTheDirectory(): void
    {
        $this->touch('20260501000000_create_users_table.php');

        $files = new MigrationDirectory($this->directory)->files();

        $this->assertCount(1, $files);
        $this->assertSame($this->directory . DIRECTORY_SEPARATOR . '20260501000000_create_users_table.php', $files[0]->path);
        $this->assertSame('CreateUsersTable', $files[0]->className);
    }

    public function testFilesAcceptsDirectoryGivenWithTrailingSlash(): void
    {
        $this->touch('20260501000000_create_users_table.php');

        $files = new MigrationDirectory($this->directory . '/')->files();

        $this->assertCount(1, $files);
        $this->assertSame($this->directory . DIRECTORY_SEPARATOR . '20260501000000_create_users_table.php', $files[0]->path);
    }

    public function testFilesIgnoresFilesWithoutPhpExtension(): void
    {
        $this->touch('.gitkeep');
        $this->touch('README.md');
        $this->touch('20260501000000_create_users_table.php.bak');
        $this->touch('20260502000000_create_posts_table.php');

        $this->assertSame(
            ['20260502000000_create_posts_table'],
            $this->names(new MigrationDirectory($this->directory)->files()),
        );
    }

    public function testFilesIgnoresHiddenPhpFiles(): void
    {
        $this->touch('._20260501000000_create_users_table.php');
        $this->touch('.helper.php');
        $this->touch('20260502000000_create_posts_table.php');

        $this->assertSame(
            ['20260502000000_create_posts_table'],
            $this->names(new MigrationDirectory($this->directory)->files()),
        );
    }

    public function testFilesIgnoresSubdirectories(): void
    {
        mkdir($this->directory . '/20260501000000_archived.php');
        mkdir($this->directory . '/old');
        $this->touch('20260502000000_create_posts_table.php');

        $this->assertSame(
            ['20260502000000_create_posts_table'],
            $this->names(new MigrationDirectory($this->directory)->files()),
        );
    }

    public function testFilesRejectsPhpFileOutsideTheNamingConvention(): void
    {
        $this->touch('20260501000000_create_users_table.php');
        $this->touch('helpers.php');

        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            fn (): array => new MigrationDirectory($this->directory)->files(),
        );

        $this->assertStringContainsString('helpers.php', $thrown->getMessage());
    }

    public function testFilesRejectsTwoFilesThatDeclareTheSameClass(): void
    {
        $this->touch('20260501000000_create_users_table.php');
        $this->touch('20260601000000_create_users_table.php');

        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            fn (): array => new MigrationDirectory($this->directory)->files(),
        );

        $this->assertStringContainsString('CreateUsersTable', $thrown->getMessage());
        $this->assertStringContainsString('20260501000000_create_users_table.php', $thrown->getMessage());
        $this->assertStringContainsString('20260601000000_create_users_table.php', $thrown->getMessage());
    }

    public function testFilesRejectsTwoDescriptionsThatBecomeTheSameClassName(): void
    {
        $this->touch('20260501000000_add_2fa.php');
        $this->touch('20260601000000_add2fa.php');

        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            fn (): array => new MigrationDirectory($this->directory)->files(),
        );

        $this->assertStringContainsString('20260501000000_add_2fa.php', $thrown->getMessage());
        $this->assertStringContainsString('20260601000000_add2fa.php', $thrown->getMessage());
    }

    public function testFilesRejectsClassNamesThatDifferOnlyInCase(): void
    {
        $this->touch('20260501000000_create_users.php');
        $this->touch('20260601000000_create_user_s.php');

        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            fn (): array => new MigrationDirectory($this->directory)->files(),
        );

        $this->assertStringContainsString('20260501000000_create_users.php', $thrown->getMessage());
        $this->assertStringContainsString('20260601000000_create_user_s.php', $thrown->getMessage());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testFilesRejectsDirectoryThatCannotBeRead(): void
    {
        chmod($this->directory, 0o000);

        try {
            if (is_readable($this->directory)) {
                $this->markTestSkipped('The process can read a directory with no permissions (running as root).');
            }

            $thrown = $this->assertThrows(
                RuntimeException::class,
                fn (): array => new MigrationDirectory($this->directory)->files(),
            );
        } finally {
            chmod($this->directory, 0o755);
        }

        $this->assertStringContainsString($this->directory, $thrown->getMessage());
    }

    public function testConstructorRejectsMissingDirectory(): void
    {
        $missing = $this->directory . '/missing';

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): MigrationDirectory => new MigrationDirectory($missing),
        );

        $this->assertStringContainsString($missing, $thrown->getMessage());
    }

    public function testConstructorRejectsPathToAFile(): void
    {
        $this->touch('20260501000000_create_users_table.php');
        $file = $this->directory . '/20260501000000_create_users_table.php';

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): MigrationDirectory => new MigrationDirectory($file),
        );

        $this->assertStringContainsString($file, $thrown->getMessage());
    }

    public function testConstructorRejectsEmptyPath(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): MigrationDirectory => new MigrationDirectory(''),
        );
    }
}
