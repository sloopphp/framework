<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Input a ValidatedRequest validates, gathered by the request method.
 *
 * POST, PUT and PATCH validate the body: the parsed body together with the
 * uploaded files. GET, HEAD and DELETE validate the query string as well,
 * since RFC 9110 gives content in those requests no defined meaning and
 * clients put what they send in either place. A field found in two of the
 * places is refused instead of one silently winning; an empty value (null,
 * '' or a file input left without a file) counts as not sent, as it does for
 * the Validator. The body and the uploaded files are merged key by key, as
 * PHP splits one form between them. Only the fields with a rule are
 * gathered, so the key named in that refusal is always one the application
 * declared, never one a client made up.
 *
 * @internal Used by RouteRequestHandler.
 */
final class ValidatedInput
{
    /**
     * Methods whose query string is validated together with the body.
     *
     * @var list<string>
     */
    private const array QUERY_METHODS = ['GET', 'HEAD', 'DELETE'];

    /**
     * Gather the input of the fields that have a rule.
     *
     * @param  ServerRequestInterface      $request PSR-7 request
     * @param  list<string>                $fields  Names of the fields with a rule
     * @return array<string, mixed>|string The input of those fields sent with a value (an empty one is left out, which the Validator reads as empty), or the message of the 400 when one was sent twice
     * @throws LogicException              When the parsed body is an object, which a middleware put there in place of an array
     */
    public static function collect(ServerRequestInterface $request, array $fields): array|string
    {
        $body  = self::body($request);
        $files = $request->getUploadedFiles();
        $query = \in_array($request->getMethod(), self::QUERY_METHODS, true) ? $request->getQueryParams() : [];

        $input = [];
        foreach ($fields as $field) {
            [$combined, $value] = self::combine($body[$field] ?? null, $files[$field] ?? null);
            if (!$combined) {
                return 'The request value "' . $field . '" was sent as both a form value and a file.';
            }

            $fromQuery = $query[$field] ?? null;
            if (!self::isEmpty($fromQuery)) {
                if (!self::isEmpty($value)) {
                    return 'The request value "' . $field . '" was sent in both the query string and the body.';
                }
                $value = $fromQuery;
            }

            if (!self::isEmpty($value)) {
                $input[$field] = $value;
            }
        }

        return $input;
    }

    /**
     * The parsed body of the request.
     *
     * @param  ServerRequestInterface  $request PSR-7 request
     * @return array<array-key, mixed>
     * @throws LogicException          When the parsed body is an object, which a middleware put there in place of an array
     */
    private static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody() ?? [];
        if (!\is_array($body)) {
            throw new LogicException('The parsed body of the request is not an array; a ValidatedRequest reads the body as one.');
        }

        return $body;
    }

    /**
     * Put back together a form value and the uploaded files PHP split from it.
     *
     * PHP puts `user[name]` in the parsed body and `user[avatar]` in the
     * uploaded files, so one field of a form can arrive in both halves. The
     * halves are merged key by key; an empty one gives way to the other, and
     * two values with something in them at the same place cannot be merged.
     * The merged keys are put back in order, so that the elements of a list
     * keep the indexes the client sent even when a lower one came only with
     * the files.
     *
     * @param  mixed              $form Value from the parsed body
     * @param  mixed              $file Value from the uploaded files
     * @return array{bool, mixed} Whether the halves could be merged, and the merged value
     */
    private static function combine(mixed $form, mixed $file): array
    {
        if (self::holdsNoFile($file)) {
            return [true, $form];
        }
        if (self::isEmpty($form) || $form === []) {
            return [true, $file];
        }
        if (!\is_array($form) || !\is_array($file)) {
            return [false, null];
        }

        foreach ($file as $key => $part) {
            [$combined, $merged] = self::combine($form[$key] ?? null, $part);
            if (!$combined) {
                return [false, null];
            }
            $form[$key] = $merged;
        }
        ksort($form);

        return [true, $form];
    }

    /**
     * Whether the files half of a field holds no file: empty, or only file inputs left without a file, at any depth.
     *
     * @param  mixed $file Value from the uploaded files
     * @return bool
     */
    private static function holdsNoFile(mixed $file): bool
    {
        if (!\is_array($file)) {
            return self::isEmpty($file);
        }
        foreach ($file as $part) {
            if (!self::holdsNoFile($part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a value counts as not sent: null, '', or a file input left without a file.
     *
     * The same values the rules take as empty.
     *
     * @param  mixed $value Value to check
     * @return bool
     */
    private static function isEmpty(mixed $value): bool
    {
        return $value === null
            || $value === ''
            || ($value instanceof UploadedFileInterface && $value->getError() === UPLOAD_ERR_NO_FILE);
    }
}
