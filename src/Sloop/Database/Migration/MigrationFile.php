<?php

declare(strict_types=1);

namespace Sloop\Database\Migration;

use Sloop\Support\Str;
use UnexpectedValueException;

/**
 * One migration file, read from its name.
 *
 * The name carries everything needed before the file is loaded: a 14-digit
 * timestamp, then an underscore, then a lowercase snake_case description
 * (`20260501000000_create_users_table.php`), whose StudlyCase form is the
 * class the file declares.
 *
 * @internal Applications follow the naming convention rather than use this class.
 */
final readonly class MigrationFile
{
    /**
     * File name convention: timestamp, underscore, snake_case description.
     *
     * The description starts with a letter and its words are joined by single
     * underscores, so the StudlyCase class name is a valid PHP identifier.
     *
     * @var string
     */
    private const string PATTERN = '/\A(\d{14})_([a-z][a-z0-9]*(?:_[a-z0-9]+)*)\.php\z/';

    /**
     * Describe one migration file.
     *
     * @param string $path      Path the file was found at
     * @param string $version   14-digit timestamp at the start of the name
     * @param string $name      File name without the extension, which identifies the migration
     * @param string $className Class the file is expected to declare
     */
    private function __construct(
        public string $path,
        public string $version,
        public string $name,
        public string $className,
    ) {
    }

    /**
     * Read a migration file from its path.
     *
     * @param  string                   $path Path to the file; only the last segment is read
     * @return self
     * @throws UnexpectedValueException If the file name does not follow the convention
     */
    public static function fromPath(string $path): self
    {
        $fileName = basename($path);

        if (preg_match(self::PATTERN, $fileName, $matches) !== 1) {
            throw new UnexpectedValueException(
                'Migration file name must be a 14-digit timestamp, an underscore and a lowercase '
                . 'snake_case description ending in .php (e.g. 20260501000000_create_users_table.php), got: '
                . $fileName,
            );
        }

        return new self(
            $path,
            $matches[1],
            $matches[1] . '_' . $matches[2],
            Str::studly($matches[2]),
        );
    }
}
