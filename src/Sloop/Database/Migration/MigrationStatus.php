<?php

declare(strict_types=1);

namespace Sloop\Database\Migration;

use DateTimeImmutable;

/**
 * Where one migration stands: applied or not, and whether its file is present.
 *
 * A migration that has not run has no batch and no time. A migration recorded
 * in the history whose file is no longer in the directory is still listed,
 * with hasFile false, since rolling it back would fail.
 */
final readonly class MigrationStatus
{
    /**
     * Describe one migration.
     *
     * @param string                 $name      Migration name: the file name without the extension
     * @param int|null               $batch     Batch it ran in, or null when it has not run
     * @param DateTimeImmutable|null $appliedAt Time it was recorded as applied, or null when it has not run
     * @param bool                   $hasFile   Whether its file is in the migration directory
     */
    public function __construct(
        public string $name,
        public ?int $batch,
        public ?DateTimeImmutable $appliedAt,
        public bool $hasFile,
    ) {
    }
}
