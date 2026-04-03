<?php

declare(strict_types=1);

use Sloop\Support\Env;

if (!function_exists('env')) {
    /**
     * Get an environment variable value.
     *
     * @param string      $key      Environment variable name
     * @param string|null $default  Default value if not set
     * @param bool        $required Throw exception if not set
     * @return string|null
     */
    function env(
        string $key,
        ?string $default = null,
        bool $required = false,
    ): ?string {
        return Env::get($key, $default, $required);
    }
}
