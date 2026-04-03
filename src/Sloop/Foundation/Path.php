<?php

declare(strict_types=1);

namespace Sloop\Foundation;

use RuntimeException;

/**
 * Application path management.
 *
 * Manages the base path and provides accessors for standard directory paths.
 * Must be initialized once via init() before use.
 */
final class Path
{
    /**
     * Application root directory path.
     *
     * @var string|null
     */
    private static ?string $basePath = null;

    /**
     * Initialize the base path.
     *
     * @param string $basePath Absolute path to the application root directory
     * @return void
     * @throws RuntimeException If the directory does not exist
     */
    public static function init(string $basePath): void
    {
        if ($basePath === '') {
            throw new RuntimeException('Base path must not be empty.');
        }

        $resolved = realpath($basePath);

        if ($resolved === false) {
            throw new RuntimeException(
                'Base path does not exist: ' . $basePath
            );
        }

        self::$basePath = $resolved;
    }

    /**
     * Get the application base path.
     *
     * @param string $path Optional relative path to append
     * @return string
     */
    public static function base(string $path = ''): string
    {
        return self::join(self::getBasePath(), $path);
    }

    /**
     * Get the source directory path (src/).
     *
     * @param string $path Optional relative path to append
     * @return string
     */
    public static function src(string $path = ''): string
    {
        return self::join(self::base('src'), $path);
    }

    /**
     * Get the configuration directory path (config/).
     *
     * @param string $path Optional relative path to append
     * @return string
     */
    public static function config(string $path = ''): string
    {
        return self::join(self::base('config'), $path);
    }

    /**
     * Get the storage directory path (storage/).
     *
     * @param string $path Optional relative path to append
     * @return string
     */
    public static function storage(string $path = ''): string
    {
        return self::join(self::base('storage'), $path);
    }

    /**
     * Get the public directory path (public/).
     *
     * @param string $path Optional relative path to append
     * @return string
     */
    public static function public(string $path = ''): string
    {
        return self::join(self::base('public'), $path);
    }

    /**
     * Get the routes directory path (routes/).
     *
     * @param string $path Optional relative path to append
     * @return string
     */
    public static function routes(string $path = ''): string
    {
        return self::join(self::base('routes'), $path);
    }

    /**
     * Get the tests directory path (tests/).
     *
     * @param string $path Optional relative path to append
     * @return string
     */
    public static function tests(string $path = ''): string
    {
        return self::join(self::base('tests'), $path);
    }

    /**
     * Check if the path has been initialized.
     *
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::$basePath !== null;
    }

    /**
     * Reset the path state. Intended for testing only.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$basePath = null;
    }

    /**
     * Get the verified base path.
     *
     * @return string Resolved base path
     * @throws RuntimeException If not initialized
     */
    private static function getBasePath(): string
    {
        if (self::$basePath === null) {
            throw new RuntimeException(
                'Path has not been initialized. Call Path::init() first.'
            );
        }

        return self::$basePath;
    }

    /**
     * Join a base path with a relative path segment.
     *
     * @param string $base Base directory path
     * @param string $path Relative path to append
     * @return string
     */
    private static function join(string $base, string $path): string
    {
        if ($path === '') {
            return $base;
        }

        return $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR . '/');
    }
}
