<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Input a ValidatedRequest validates, gathered by the request method.
 *
 * POST, PUT and PATCH validate the body: the parsed body together with the
 * uploaded files. GET, HEAD and DELETE validate the query string as well,
 * since RFC 9110 gives content in those requests no defined meaning and
 * clients put what they send in either place. A field found in two of the
 * places is refused instead of one silently winning. Only the fields with a
 * rule are gathered, so the key named in that refusal is always one the
 * application declared, never one a client made up.
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
     * @return array<string, mixed>|string The input of those fields that were sent, or the message of the 400 when one was sent twice
     * @throws LogicException              When the parsed body is an object, which a middleware put there in place of an array
     */
    public static function collect(ServerRequestInterface $request, array $fields): array|string
    {
        $body = $request->getParsedBody() ?? [];
        if (!\is_array($body)) {
            throw new LogicException('The parsed body of the request is not an array; a ValidatedRequest reads the body as one.');
        }
        $files = $request->getUploadedFiles();
        $query = \in_array($request->getMethod(), self::QUERY_METHODS, true) ? $request->getQueryParams() : [];

        $input = [];
        foreach ($fields as $field) {
            if (\array_key_exists($field, $body)) {
                if (\array_key_exists($field, $files)) {
                    return 'The request value "' . $field . '" was sent as both a form value and a file.';
                }
                $input[$field] = $body[$field];
            } elseif (\array_key_exists($field, $files)) {
                $input[$field] = $files[$field];
            }

            if (\array_key_exists($field, $query)) {
                if (\array_key_exists($field, $input)) {
                    return 'The request value "' . $field . '" was sent in both the query string and the body.';
                }
                $input[$field] = $query[$field];
            }
        }

        return $input;
    }
}
