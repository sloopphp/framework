<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sloop\Validation\FieldRule;
use UnexpectedValueException;

/**
 * Input a ValidatedRequest validates, gathered by the request method.
 *
 * POST, PUT and PATCH validate the body: the parsed body together with the
 * uploaded files. GET, HEAD and DELETE validate the query string as well,
 * since RFC 9110 gives content in those requests no defined meaning and
 * clients put what they send in either place. A field found in two of the
 * places is refused instead of one silently winning. Whether a value counts
 * as sent is asked of the rule at its place, so an empty value is the one
 * the Validator would take as empty: null or '' after the sanitizers, and a
 * file input left without a file only where the rule is Rule::file(); `[]`
 * is a value. The body and the uploaded files are merged key by key, as PHP
 * splits one form between them; keys found only in the files come after the
 * ones in the body, as nothing is reordered. Only the fields with a rule are
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
     * @param  ServerRequestInterface                    $request PSR-7 request
     * @param  array<string, FieldRule<covariant mixed>> $rules   Rules keyed by field name
     * @return array<string, mixed>|string               The input of those fields sent with a value (an empty one is left out, which the Validator reads as empty), or the message of the 400 when one was sent twice
     * @throws LogicException                            When the parsed body is an object, which a middleware put there in place of an array
     * @throws UnexpectedValueException                  When a sanitizer closure returns something other than a string
     * @throws RuntimeException                          When PCRE aborts while a Sanitize case is running
     */
    public static function collect(ServerRequestInterface $request, array $rules): array|string
    {
        $body  = self::body($request);
        $files = $request->getUploadedFiles();
        $query = \in_array($request->getMethod(), self::QUERY_METHODS, true) ? $request->getQueryParams() : [];

        $input = [];
        foreach ($rules as $field => $rule) {
            [$combined, $value] = self::combine($body[$field] ?? null, $files[$field] ?? null, $rule);
            if (!$combined) {
                return 'The request value "' . $field . '" was sent as both a form value and a file.';
            }

            // Each value is judged once: the sanitizers of the rule run here and again when it is validated.
            $sent = !self::isEmpty($value, $rule);
            if (\array_key_exists($field, $query) && !self::isEmpty($query[$field], $rule)) {
                if ($sent) {
                    return 'The request value "' . $field . '" was sent in both the query string and the body.';
                }
                [$value, $sent] = [$query[$field], true];
            }

            if ($sent) {
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
     * halves are merged key by key; an empty one, as the rule at that place
     * defines it, gives way to the other, and two values with something in
     * them at the same place cannot be merged. A key the rule drops (one a
     * shape does not declare) is not merged from the files, as the Validator
     * would drop it anyway. Below a list or a shape whose array sanitizer is
     * a closure, which can rename keys or move elements before they are
     * validated, no rule is asked: the halves are merged as they arrive, and
     * the closure receives them as one form would send them.
     *
     * @param  mixed                           $form Value from the parsed body
     * @param  mixed                           $file Value from the uploaded files
     * @param  FieldRule<covariant mixed>|null $rule Rule at this place, or null where none is declared
     * @return array{bool, mixed}              Whether the halves could be merged, and the merged value
     * @throws UnexpectedValueException        When a sanitizer closure returns something other than a string
     * @throws RuntimeException                When PCRE aborts while a Sanitize case is running
     */
    private static function combine(mixed $form, mixed $file, ?FieldRule $rule): array
    {
        if (self::isEmpty($file, $rule)) {
            return [true, $form];
        }
        if (self::isEmpty($form, $rule)) {
            return [true, $file];
        }
        if (!\is_array($file)) {
            return [false, null];
        }
        $kept = self::withoutDroppedKeys($file, $rule);
        if ($kept === []) {
            // A file half holding no file, or only keys the rule drops, has nothing to merge.
            return [true, $form];
        }
        if (!\is_array($form)) {
            return [false, null];
        }

        foreach ($kept as $key => $part) {
            [$combined, $merged] = self::combine($form[$key] ?? null, $part, $rule?->childRule($key));
            if (!$combined) {
                return [false, null];
            }
            $form[$key] = $merged;
        }

        return [true, $form];
    }

    /**
     * The files half of a field without the keys its rule drops.
     *
     * @param  array<array-key, mixed>         $file Value from the uploaded files
     * @param  FieldRule<covariant mixed>|null $rule Rule at this place, or null where none is declared
     * @return array<array-key, mixed>
     */
    private static function withoutDroppedKeys(array $file, ?FieldRule $rule): array
    {
        if ($rule === null) {
            return $file;
        }
        $kept = [];
        foreach ($file as $key => $part) {
            if (!$rule->dropsKey($key)) {
                $kept[$key] = $part;
            }
        }

        return $kept;
    }

    /**
     * Whether a value counts as not sent, as the rule at its place takes it as empty.
     *
     * Where no rule is declared, only null and '' count.
     *
     * @param  mixed                           $value Value to check
     * @param  FieldRule<covariant mixed>|null $rule  Rule at the value's place
     * @return bool
     * @throws UnexpectedValueException        When a sanitizer closure returns something other than a string
     * @throws RuntimeException                When PCRE aborts while a Sanitize case is running
     */
    private static function isEmpty(mixed $value, ?FieldRule $rule): bool
    {
        if ($rule === null) {
            return $value === null || $value === '';
        }

        return $rule->isEmptyInput($value);
    }
}
