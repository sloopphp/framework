<?php

declare(strict_types=1);

namespace Sloop\Security\Exception;

use Sloop\Config\InvalidConfigException;

/**
 * Thrown when the security config is malformed.
 *
 * Covers a security section that is not an array or has an unsupported key,
 * a security.headers entry that is malformed (a blank or non-string value, a
 * value with a line break, an integer key, a name given twice in different
 * case, or a name or value that cannot be sent), a security.csrf entry that
 * is malformed (an origin that is not a string or null, is not
 * scheme://host[:port] as browsers send it, names the default port, or for
 * the application's own origin uses a scheme other than http or https, or
 * trusted_origins that is not a list of strings), and a
 * security.max_body_size that is not a positive integer.
 */
final class SecurityConfigException extends InvalidConfigException
{
}
