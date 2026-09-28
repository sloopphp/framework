<?php

declare(strict_types=1);

namespace Sloop\Validation;

use finfo;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Rules for a field whose validated value is an uploaded file.
 *
 * An upload carrying UPLOAD_ERR_NO_FILE counts as an empty field and is
 * caught by required() rather than reported as a broken upload. Every other
 * upload error fails the field on its own, whatever rules were declared.
 *
 * The request built from the globals already leaves a NO_FILE entry out, but
 * one built elsewhere may still carry the entry a browser sends for a field
 * left empty. Inside a list such an entry is still an element: it reads as
 * null and is counted by the rules on the number of elements.
 *
 * Sanitizers do not apply here: there is no string to clean.
 *
 * @extends FieldRule<UploadedFileInterface>
 */
final class FileRule extends FieldRule
{
    /**
     * Bytes read from the start of the file to name its type.
     *
     * A type that the rest of the content would change, such as text against
     * binary, is decided on this sample.
     *
     * @var int
     */
    private const int SNIFF_BYTES = 4096;

    /**
     * Create the rule set.
     */
    public function __construct()
    {
        parent::__construct([]);
    }

    /**
     * Fail when the file is larger than the given number of bytes.
     *
     * This runs with the other rules rather than before them, so a file that
     * is too large is still read by mimeTypes() and both failures are reported.
     *
     * @param  int                      $max     Largest accepted size in bytes
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $max is below 1 or the message template is malformed
     */
    public function maxSize(int $max, ?string $message = null): self
    {
        if ($max < 1) {
            throw new InvalidArgumentException('maxSize() needs at least 1 byte, got ' . $max . '.');
        }

        return $this->withCheck(
            'maxSize',
            ['max' => $max],
            static function (UploadedFileInterface $file) use ($max): bool {
                $size = $file->getSize();

                // A transport that cannot say how large the file is cannot
                // show it to be within the limit either.
                return $size !== null && $size <= $max;
            },
            $message,
        );
    }

    /**
     * Fail unless the content of the file is one of the given media types.
     *
     * The type is read from the bytes, not from the Content-Type the client
     * sent: that header is written by the sender, so a script can claim to be
     * an image just by saying so. Only the start of the file is read, so a
     * type that the rest of the content would change is not caught here.
     * Passing this does not make the file safe to store under the name the
     * client sent or to serve back.
     *
     * @param  list<string>             $types   Accepted media types, such as `image/png`
     * @param  string|null              $message Message for this rule only
     * @return self
     * @throws InvalidArgumentException When $types is empty or the message template is malformed
     */
    public function mimeTypes(array $types, ?string $message = null): self
    {
        if ($types === []) {
            throw new InvalidArgumentException('mimeTypes() needs at least one media type.');
        }

        return $this->withCheck(
            'mimeTypes',
            ['types' => $types],
            static fn (UploadedFileInterface $file): bool => \in_array(self::sniff($file), $types, true),
            $message,
        );
    }

    /**
     * Not available on a file field.
     *
     * The validated value is the upload itself, and two uploads are distinct
     * objects however alike their content is, so the answer would always be
     * the same one. Compare what the upload holds in a rule of your own.
     *
     * @param  string         $field   Unused
     * @param  string|null    $message Unused
     * @return never
     * @throws LogicException Always
     */
    public function same(string $field, ?string $message = null): never
    {
        throw new LogicException('Rule::file() has no same(): two uploads never hold the same value.');
    }

    /**
     * Not available on a file field.
     *
     * @param  string         $field   Unused
     * @param  string|null    $message Unused
     * @return never
     * @throws LogicException Always
     */
    public function different(string $field, ?string $message = null): never
    {
        throw new LogicException('Rule::file() has no different(): two uploads never hold the same value.');
    }

    /**
     * Nothing can be compared with a file field, in either direction.
     *
     * @internal Read by Validator for both sides of a declared comparison.
     *
     * @return bool
     */
    public function comparesByValue(): bool
    {
        return false;
    }

    /**
     * Count an upload that carries nothing as an empty field.
     *
     * @param  mixed $value Raw input after sanitizing, or a declared default as it is
     * @return bool
     */
    protected function isEmpty(mixed $value): bool
    {
        if ($value instanceof UploadedFileInterface) {
            return $value->getError() === \UPLOAD_ERR_NO_FILE;
        }

        return parent::isEmpty($value);
    }

    /**
     * Take an upload that arrived intact.
     *
     * @param  mixed                              $value Raw value
     * @return UploadedFileInterface|TypeMismatch
     */
    protected function coerce(mixed $value): UploadedFileInterface|TypeMismatch
    {
        if (!$value instanceof UploadedFileInterface) {
            return new TypeMismatch();
        }

        return $value->getError() === \UPLOAD_ERR_OK ? $value : new TypeMismatch('upload');
    }

    /**
     * Rule name reported when the value is not an uploaded file at all.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'file';
    }

    /**
     * Read the media type from the first bytes of the file.
     *
     * The stream is rewound afterwards so that the caller reads it from the
     * start, and one that cannot be rewound is not read at all.
     * finfo::buffer() rather than finfo::file(), because a PSR-7 upload need
     * not be backed by a path on disk.
     *
     * @param  UploadedFileInterface $file File to inspect
     * @return string|null
     */
    private static function sniff(UploadedFileInterface $file): ?string
    {
        $stream = $file->getStream();
        // Reading a stream that cannot be put back would hand the caller a
        // file with its first bytes already taken, so leave it alone and let
        // the type stay unknown; no declared type matches that.
        if (!$stream->isSeekable()) {
            return null;
        }

        $stream->rewind();
        $head = $stream->read(self::SNIFF_BYTES);
        $stream->rewind();

        $type = new finfo(\FILEINFO_MIME_TYPE)->buffer($head);

        return $type === false ? null : $type;
    }
}
