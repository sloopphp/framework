<?php

declare(strict_types=1);

namespace Sloop\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Thrown when an error occurs while resolving a container entry.
 */
final class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
