<?php

declare(strict_types=1);

namespace Sloop\Http;

/**
 * HTTP status code constants.
 *
 * Covers standard status codes defined in RFC 9110 (HTTP Semantics),
 * RFC 6585, RFC 7725, RFC 8297, and RFC 8470.
 *
 * Framework APIs accept `int` status codes so any integer value —
 * standard or custom — can be passed.
 *
 * ## Using standard codes
 *
 * ```php
 * return $this->response($data)->status(HttpStatus::Ok)->json();
 * throw new DomainException('Validation failed', HttpStatus::UnprocessableEntity);
 * ```
 *
 * ## Using non-standard / custom codes
 *
 * Pass custom codes as raw integers:
 *
 * ```php
 * // Cloudflare 520 (Web Server Returned an Unknown Error)
 * return $this->response()->error('Upstream down', 520);
 *
 * // nginx 499 (Client Closed Request)
 * throw new InfrastructureException('Client disconnected', 499);
 * ```
 *
 * For frequently used custom codes, define your own constants class in
 * application code.
 */
final class HttpStatus
{
    // ---------------------------------------------------------------
    // 1xx Informational
    // ---------------------------------------------------------------

    public const int Continue           = 100;
    public const int SwitchingProtocols = 101;
    public const int Processing         = 102;
    public const int EarlyHints         = 103;

    // ---------------------------------------------------------------
    // 2xx Success
    // ---------------------------------------------------------------

    public const int Ok                   = 200;
    public const int Created              = 201;
    public const int Accepted             = 202;
    public const int NonAuthoritativeInfo = 203;
    public const int NoContent            = 204;
    public const int ResetContent         = 205;
    public const int PartialContent       = 206;
    public const int MultiStatus          = 207;
    public const int AlreadyReported      = 208;
    public const int ImUsed               = 226;

    // ---------------------------------------------------------------
    // 3xx Redirection
    // ---------------------------------------------------------------

    public const int MultipleChoices   = 300;
    public const int MovedPermanently  = 301;
    public const int Found             = 302;
    public const int SeeOther          = 303;
    public const int NotModified       = 304;
    public const int UseProxy          = 305;
    public const int TemporaryRedirect = 307;
    public const int PermanentRedirect = 308;

    // ---------------------------------------------------------------
    // 4xx Client Error
    // ---------------------------------------------------------------

    public const int BadRequest           = 400;
    public const int Unauthorized         = 401;
    public const int PaymentRequired      = 402;
    public const int Forbidden            = 403;
    public const int NotFound             = 404;
    public const int MethodNotAllowed     = 405;
    public const int NotAcceptable        = 406;
    public const int ProxyAuthRequired    = 407;
    public const int RequestTimeout       = 408;
    public const int Conflict             = 409;
    public const int Gone                 = 410;
    public const int LengthRequired       = 411;
    public const int PreconditionFailed   = 412;
    public const int ContentTooLarge      = 413;
    public const int UriTooLong           = 414;
    public const int UnsupportedMediaType = 415;
    public const int RangeNotSatisfiable  = 416;
    public const int ExpectationFailed    = 417;
    public const int ImATeapot            = 418;
    public const int MisdirectedRequest   = 421;
    public const int UnprocessableEntity  = 422;
    public const int Locked               = 423;
    public const int FailedDependency     = 424;
    public const int TooEarly             = 425;
    public const int UpgradeRequired      = 426;
    public const int PreconditionRequired = 428;
    public const int TooManyRequests      = 429;
    public const int RequestHeaderFieldsTooLarge = 431;
    public const int UnavailableForLegalReasons  = 451;

    // ---------------------------------------------------------------
    // 5xx Server Error
    // ---------------------------------------------------------------

    public const int InternalServerError           = 500;
    public const int NotImplemented                = 501;
    public const int BadGateway                    = 502;
    public const int ServiceUnavailable            = 503;
    public const int GatewayTimeout                = 504;
    public const int HttpVersionNotSupported       = 505;
    public const int VariantAlsoNegotiates         = 506;
    public const int InsufficientStorage           = 507;
    public const int LoopDetected                  = 508;
    public const int NotExtended                   = 510;
    public const int NetworkAuthenticationRequired = 511;
}
