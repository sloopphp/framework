<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Migration\MigrationFile;
use Sloop\Tests\Support\ThrowsAssertions;
use UnexpectedValueException;

final class MigrationFileTest extends TestCase
{
    use ThrowsAssertions;

    public function testFromPathReadsVersionNameAndClassName(): void
    {
        $file = MigrationFile::fromPath('/app/database/migrations/20260501000000_create_users_table.php');

        $this->assertSame('/app/database/migrations/20260501000000_create_users_table.php', $file->path);
        $this->assertSame('20260501000000', $file->version);
        $this->assertSame('20260501000000_create_users_table', $file->name);
        $this->assertSame('CreateUsersTable', $file->className);
    }

    public function testFromPathAcceptsDigitsAfterTheFirstLetterOfEachWord(): void
    {
        $file = MigrationFile::fromPath('20260501000000_add_2fa_to_users.php');

        $this->assertSame('Add2faToUsers', $file->className);
    }

    public function testFromPathAcceptsSingleWordName(): void
    {
        $file = MigrationFile::fromPath('20260501000000_init.php');

        $this->assertSame('Init', $file->className);
    }

    #[DataProvider('invalidFileNames')]
    public function testFromPathRejectsNameOutsideTheConvention(string $fileName): void
    {
        $thrown = $this->assertThrows(
            UnexpectedValueException::class,
            static fn (): MigrationFile => MigrationFile::fromPath('/app/database/migrations/' . $fileName),
        );

        $this->assertStringContainsString($fileName, $thrown->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFileNames(): array
    {
        return [
            'timestamp shorter than 14 digits'  => ['2026050100000_create_users_table.php'],
            'timestamp longer than 14 digits'   => ['202605010000000_create_users_table.php'],
            'no timestamp'                      => ['create_users_table.php'],
            'no name'                           => ['20260501000000.php'],
            'empty name'                        => ['20260501000000_.php'],
            'name starting with a digit'        => ['20260501000000_2fa.php'],
            'uppercase letters in name'         => ['20260501000000_CreateUsersTable.php'],
            'hyphen in name'                    => ['20260501000000_create-users-table.php'],
            'consecutive underscores'           => ['20260501000000_create__users.php'],
            'trailing underscore'               => ['20260501000000_create_users_.php'],
            'separator other than underscore'   => ['20260501000000-create_users_table.php'],
            'extension other than php'          => ['20260501000000_create_users_table.inc'],
            'uppercase extension'               => ['20260501000000_create_users_table.PHP'],
            'text after the extension'          => ['20260501000000_create_users_table.php.bak'],
            'newline before the extension'      => ["20260501000000_create_users_table\n.php"],
            'trailing newline after the name'   => ["20260501000000_create_users_table.php\n"],
        ];
    }
}
