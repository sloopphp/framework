<?php

declare(strict_types=1);

namespace Sloop\Database\Migration;

use Sloop\Database\Connection;

/**
 * Base class for a single schema change.
 *
 * Each migration lives in its own file named after the time it was written and
 * what it does (`20260501000000_create_users_table.php`), and declares a class
 * whose name is the StudlyCase form of that description (`CreateUsersTable`).
 */
abstract class Migration
{
    /**
     * Apply the change.
     *
     * @param  Connection $db Connection the change is applied through
     * @return void
     */
    abstract public function up(Connection $db): void;

    /**
     * Undo what up() applied.
     *
     * @param  Connection $db Connection the change is undone through
     * @return void
     */
    abstract public function down(Connection $db): void;
}
