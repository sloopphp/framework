<?php

declare(strict_types=1);

namespace Sloop\Database\Migration;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * The directory migrations are read from, and the order they apply in.
 *
 * Only the top level is read. Entries whose names start with a dot,
 * directories, and entries that do not end in `.php` are left alone, so a
 * `.gitkeep` or a README can sit beside the migrations. Every other `.php`
 * entry must follow the naming convention: a helper script dropped into the
 * directory is reported rather than silently skipped, because a skipped file
 * here is a schema change that never runs. For the same reason an entry that
 * cannot be inspected, such as a dangling symlink, is kept rather than skipped.
 *
 * @internal Applications give the directory path rather than use this class.
 */
final readonly class MigrationDirectory
{
    /**
     * Directory path with trailing separators removed.
     *
     * @var string
     */
    private string $path;

    /**
     * Point at a migration directory.
     *
     * @param  string                   $path Path to the directory
     * @throws InvalidArgumentException If the path is not an existing directory
     */
    public function __construct(string $path)
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException('Migration directory does not exist: ' . $path);
        }

        $this->path = rtrim($path, '/' . \DIRECTORY_SEPARATOR);
    }

    /**
     * List the migration files in the order they apply.
     *
     * Files are ordered by name compared byte by byte. The fixed-width
     * timestamp puts earlier migrations first, and files sharing a timestamp
     * fall back to their descriptions, so the order never depends on how the
     * filesystem happens to list the directory.
     *
     * @return list<MigrationFile>
     * @throws RuntimeException         If the directory cannot be read
     * @throws UnexpectedValueException If a file breaks the naming convention, or two files would declare the same class
     */
    public function files(): array
    {
        $entries = is_readable($this->path) ? scandir($this->path, \SCANDIR_SORT_NONE) : false;

        if ($entries === false) {
            throw new RuntimeException('Migration directory could not be read: ' . $this->path);
        }

        $fileNames = [];

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.') || !str_ends_with($entry, '.php')) {
                continue;
            }

            if (!is_dir($this->path . \DIRECTORY_SEPARATOR . $entry)) {
                $fileNames[] = $entry;
            }
        }

        sort($fileNames, \SORT_STRING);

        $files     = [];
        $claimedBy = [];

        foreach ($fileNames as $fileName) {
            $file = MigrationFile::fromPath($this->path . \DIRECTORY_SEPARATOR . $fileName);

            // PHP class names are case-insensitive: CreateUserS and CreateUsers are one class.
            $classKey = strtolower($file->className);

            if (isset($claimedBy[$classKey])) {
                throw new UnexpectedValueException(
                    'Migration files ' . $claimedBy[$classKey] . ' and ' . $fileName
                    . ' would declare the same class ' . $file->className . '.',
                );
            }

            $claimedBy[$classKey] = $fileName;
            $files[]              = $file;
        }

        return $files;
    }
}
