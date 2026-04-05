<?php

declare(strict_types=1);

namespace Sloop\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Thrown when a requested entry is not found in the container.
 */
final class EntryNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
