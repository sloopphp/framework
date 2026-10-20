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
 * places is refused instead of one silently winning; an empty value (null
 * or '') counts as not sent, as it does for the Validator. Only the fields
 * with a rule are gathered, so the key named in that refusal is always one
 * the application declared, never one a client made up.
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
        $body = $request->getParsedBody() ?? [];
        if (!\is_array($body)) {
            throw new LogicException('The parsed body of the request is not an array; a ValidatedRequest reads the body as one.');
        }
        $files = $request->getUploadedFiles();
        $query = \in_array($request->getMethod(), self::QUERY_METHODS, true) ? $request->getQueryParams() : [];

        $sources = ['body' => $body, 'file' => $files, 'query' => $query];
        $input   = [];
        foreach ($fields as $field) {
            $sent = self::sent($field, $sources);

            if (\array_key_exists('body', $sent) && \array_key_exists('file', $sent)) {
                return 'The request value "' . $field . '" was sent as both a form value and a file.';
            }
            if (\count($sent) > 1) {
                return 'The request value "' . $field . '" was sent in both the query string and the body.';
            }
            if ($sent !== []) {
                $input[$field] = array_first($sent);
            }
        }

        return $input;
    }

    /**
     * The values a field was sent with, keyed by where they came from, leaving out empty ones.
     *
     * @param  string                                 $field   Field name
     * @param  array<string, array<array-key, mixed>> $sources Body, files and query string, keyed by place
     * @return array<string, mixed>
     */
    private static function sent(string $field, array $sources): array
    {
        $sent = [];
        foreach ($sources as $place => $source) {
            $value = $source[$field] ?? null;
            if ($value !== null && $value !== '') {
                $sent[$place] = $value;
            }
        }

        return $sent;
    }
}
