<?php

declare(strict_types=1);

use Sloop\Foundation\Path;

if (!function_exists('base_path')) {
    /**
     * Get the application base path.
     *
     * @param  string         $path Optional relative path to append
     * @return string
     * @throws LogicException If Path::init() has not been called
     */
    function base_path(string $path = ''): string
    {
        return Path::base($path);
    }
}

if (!function_exists('src_path')) {
    /**
     * Get the source directory path (src/).
     *
     * @param  string         $path Optional relative path to append
     * @return string
     * @throws LogicException If Path::init() has not been called
     */
    function src_path(string $path = ''): string
    {
        return Path::src($path);
    }
}

if (!function_exists('config_path')) {
    /**
     * Get the configuration directory path (config/).
     *
     * @param  string         $path Optional relative path to append
     * @return string
     * @throws LogicException If Path::init() has not been called
     */
    function config_path(string $path = ''): string
    {
        return Path::config($path);
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the storage directory path (storage/).
     *
     * @param  string         $path Optional relative path to append
     * @return string
     * @throws LogicException If Path::init() has not been called
     */
    function storage_path(string $path = ''): string
    {
        return Path::storage($path);
    }
}

if (!function_exists('public_path')) {
    /**
     * Get the public directory path (public/).
     *
     * @param  string         $path Optional relative path to append
     * @return string
     * @throws LogicException If Path::init() has not been called
     */
    function public_path(string $path = ''): string
    {
        return Path::public($path);
    }
}

if (!function_exists('routes_path')) {
    /**
     * Get the routes directory path (routes/).
     *
     * @param  string         $path Optional relative path to append
     * @return string
     * @throws LogicException If Path::init() has not been called
     */
    function routes_path(string $path = ''): string
    {
        return Path::routes($path);
    }
}

if (!function_exists('tests_path')) {
    /**
     * Get the tests directory path (tests/).
     *
     * @param  string         $path Optional relative path to append
     * @return string
     * @throws LogicException If Path::init() has not been called
     */
    function tests_path(string $path = ''): string
    {
        return Path::tests($path);
    }
}
