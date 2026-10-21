<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log;

use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Log\LogConfigValidator;
use Sloop\Log\LogManager;

// The validator lists the names LogManager reads, in a copy of its own.
// Every listed name is fed through both, so a name the validator accepts
// cannot be one LogManager rejects.
final class LogConfigValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function listedNameProvider(): iterable
    {
        yield 'driver stream' => [['driver' => 'stream', 'stream' => 'php://memory']];
        yield 'driver daily' => [['driver' => 'daily', 'path' => sys_get_temp_dir() . '/sloop_log_validator_test.log']];

        foreach (LogConfigValidator::LEVELS as $level) {
            yield 'level ' . $level => [['stream' => 'php://memory', 'level' => $level]];
        }

        foreach (LogConfigValidator::FORMATTERS as $formatter) {
            yield 'formatter ' . $formatter => [['stream' => 'php://memory', 'formatter' => $formatter]];
        }

        foreach (LogConfigValidator::PROCESSORS as $processor) {
            yield 'processor ' . $processor => [['stream' => 'php://memory', 'processors' => [$processor]]];
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('listedNameProvider')]
    public function testEveryListedNameIsOneLogManagerBuilds(array $config): void
    {
        $channels = LogConfigValidator::validateChannels(['app' => $config]);

        $logger = new LogManager(channels: $channels)->channel('app');

        $this->assertInstanceOf(Logger::class, $logger);
    }

}
