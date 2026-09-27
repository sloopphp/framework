<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use Sloop\Support\Arr;

/**
 * Conversion of $_FILES into the PSR-7 uploaded file tree.
 *
 * Each file keeps the path PHP wrote it to, so nothing is opened until the
 * file is read, and moveTo() moves it with move_uploaded_file() instead of
 * copying its contents.
 *
 * @internal Used by Application when it builds the request from the globals.
 */
final class UploadedFiles
{
    /**
     * Keys of a file field that the conversion reads.
     *
     * @var list<string>
     */
    private const array PARTS = ['tmp_name', 'size', 'error', 'name', 'type'];

    /**
     * Convert a $_FILES array.
     *
     * A field named `photos[]` or `form[profile][avatar]` arrives with the
     * nesting inside each of name, type, tmp_name, error and size; the result
     * puts it outside, so the file is at `photos[0]` or `form['profile']['avatar']`.
     *
     * @param  array<array-key, mixed> $files Array shaped like $_FILES
     * @return array<array-key, mixed> UploadedFileInterface leaves under the field names
     */
    public static function fromGlobals(array $files): array
    {
        $converted = [];
        foreach ($files as $field => $spec) {
            if (\is_array($spec)) {
                $converted[$field] = self::fromSpec($spec);
            }
        }

        return $converted;
    }

    /**
     * Convert one field, descending while its parts hold arrays.
     *
     * @param  array<array-key, mixed>                       $spec The five parts of the field, each a value or an array of them
     * @return UploadedFileInterface|array<array-key, mixed>
     */
    private static function fromSpec(array $spec): UploadedFileInterface|array
    {
        $tmpName = $spec['tmp_name'] ?? null;
        if (!\is_array($tmpName)) {
            return self::file($spec);
        }

        $converted = [];
        foreach (array_keys($tmpName) as $key) {
            $converted[$key] = self::fromSpec(self::entry($spec, $key));
        }

        return $converted;
    }

    /**
     * Take the entry under one key from each of the five parts.
     *
     * @param  array<array-key, mixed> $spec The five parts of a nested field
     * @param  int|string              $key  Key one level down
     * @return array<string, mixed>
     */
    private static function entry(array $spec, int|string $key): array
    {
        $entry = [];
        foreach (self::PARTS as $part) {
            $values       = $spec[$part] ?? null;
            $entry[$part] = \is_array($values) ? $values[$key] ?? null : null;
        }

        return $entry;
    }

    /**
     * Build the file of one leaf.
     *
     * A file without an error part reads as nothing having been uploaded.
     *
     * @param  array<array-key, mixed> $spec The five parts of a single file
     * @return UploadedFileInterface
     */
    private static function file(array $spec): UploadedFileInterface
    {
        $name = $spec['name'] ?? null;
        $type = $spec['type'] ?? null;

        return new UploadedFile(
            Arr::getString($spec, 'tmp_name'),
            Arr::getInt($spec, 'size'),
            Arr::getInt($spec, 'error', \UPLOAD_ERR_NO_FILE),
            \is_string($name) ? $name : null,
            \is_string($type) ? $type : null,
        );
    }
}
