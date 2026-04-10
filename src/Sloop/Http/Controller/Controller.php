<?php

declare(strict_types=1);

namespace Sloop\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Sloop\Http\HttpStatus;
use Sloop\Http\Response\Response;
use Sloop\Http\Response\ResponseFormatterInterface;

/**
 * Base controller with convenience methods.
 *
 * Extending this class is optional. Controllers can be plain classes
 * as long as their action methods return a ResponseInterface.
 *
 * Subclasses with their own dependencies must propagate the formatter
 * to the parent constructor:
 *
 * ```php
 * public function __construct(
 *     ResponseFormatterInterface $formatter,
 *     private UserService $service,
 * ) {
 *     parent::__construct($formatter);
 * }
 * ```
 */
abstract class Controller
{
    /**
     * Create a new controller.
     *
     * @param  ResponseFormatterInterface $formatter Response formatter for building structured responses
     * @return void
     */
    public function __construct(
        private readonly ResponseFormatterInterface $formatter,
    ) {
    }

    /**
     * Create a response builder with the given data.
     *
     * @param  mixed $data Response data
     * @return Response
     */
    protected function response(mixed $data = null): Response
    {
        return new Response($data, $this->formatter);
    }

    /**
     * Create a 204 No Content response.
     *
     * @return ResponseInterface
     */
    protected function noContent(): ResponseInterface
    {
        return $this->response()->noContent();
    }

    /**
     * Create a redirect response.
     *
     * @param  string $url    Redirect URL
     * @param  int    $status HTTP status code (default 302)
     * @return ResponseInterface
     */
    protected function redirect(string $url, int $status = HttpStatus::Found): ResponseInterface
    {
        return $this->response()->redirect($url, $status);
    }
}
