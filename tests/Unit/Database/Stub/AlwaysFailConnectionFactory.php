<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

use LogicException;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Connection;
use Sloop\Database\Factory\ConnectionFactory;

final class AlwaysFailConnectionFactory implements ConnectionFactory
{
    public function make(ValidatedConfig $config, string $name): Connection
    {
        throw new LogicException('AlwaysFailConnectionFactory should not be invoked in this test path.');
    }
}
