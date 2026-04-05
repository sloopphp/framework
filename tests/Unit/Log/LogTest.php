<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sloop\Log\Log;
use Sloop\Log\LogManager;

final class LogTest extends TestCase
{
    private TestHandler $handler;
    private LogManager $manager;

    protected function setUp(): void
    {
        Log::reset();

        $this->handler = new TestHandler();
        $this->manager = new LogManager();
        $this->manager->addChannel('app', new Logger('app', [$this->handler]));

        Log::init($this->manager);
    }

    protected function tearDown(): void
    {
        Log::reset();
    }

    // ---------------------------------------------------------------
    // PSR-3 interface
    // ---------------------------------------------------------------

    public function testImplementsLoggerInterface(): void
    {
        $interfaces = class_implements(Log::class);

        $this->assertContains(LoggerInterface::class, $interfaces);
    }

    // ---------------------------------------------------------------
    // init / reset
    // ---------------------------------------------------------------

    public function testLogThrowsBeforeInit(): void
    {
        Log::reset();

        $log = Log::channel('app');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Logger has not been initialized');
        $log->info('test');
    }

    // ---------------------------------------------------------------
    // Log level methods (instance)
    // ---------------------------------------------------------------

    public function testEmergencyLogsAtEmergencyLevel(): void
    {
        $log = Log::channel('app');
        $log->emergency('System down');

        $this->assertTrue($this->handler->hasEmergencyRecords());
        $this->assertTrue($this->handler->hasRecordThatContains('System down', Level::Emergency));
    }

    public function testAlertLogsAtAlertLevel(): void
    {
        $log = Log::channel('app');
        $log->alert('Alert message');

        $this->assertTrue($this->handler->hasAlertRecords());
    }

    public function testCriticalLogsAtCriticalLevel(): void
    {
        $log = Log::channel('app');
        $log->critical('Critical failure');

        $this->assertTrue($this->handler->hasCriticalRecords());
    }

    public function testErrorLogsAtErrorLevel(): void
    {
        $log = Log::channel('app');
        $log->error('Error occurred');

        $this->assertTrue($this->handler->hasErrorRecords());
    }

    public function testWarningLogsAtWarningLevel(): void
    {
        $log = Log::channel('app');
        $log->warning('Warning issued');

        $this->assertTrue($this->handler->hasWarningRecords());
    }

    public function testNoticeLogsAtNoticeLevel(): void
    {
        $log = Log::channel('app');
        $log->notice('Notice');

        $this->assertTrue($this->handler->hasNoticeRecords());
    }

    public function testInfoLogsAtInfoLevel(): void
    {
        $log = Log::channel('app');
        $log->info('Info message');

        $this->assertTrue($this->handler->hasInfoRecords());
    }

    public function testDebugLogsAtDebugLevel(): void
    {
        $log = Log::channel('app');
        $log->debug('Debug trace');

        $this->assertTrue($this->handler->hasDebugRecords());
    }

    public function testLogWithMonologLevel(): void
    {
        $log = Log::channel('app');
        $log->log(Level::Error, 'Generic log');

        $this->assertTrue($this->handler->hasErrorRecords());
    }

    public function testLogWithPsr3StringLevel(): void
    {
        $log = Log::channel('app');
        $log->log('warning', 'String level');

        $this->assertTrue($this->handler->hasWarningRecords());
    }

    public function testLogWithUnknownLevelFallsBackToDebug(): void
    {
        $log = Log::channel('app');
        $log->log('unknown', 'Fallback message');

        $this->assertTrue($this->handler->hasDebugRecords());
    }

    // ---------------------------------------------------------------
    // Context
    // ---------------------------------------------------------------

    public function testContextIsPassedToLogger(): void
    {
        $log = Log::channel('app');
        $log->info('User login', ['user_id' => 42]);

        $records = $this->handler->getRecords();
        $this->assertSame(42, $records[0]->context['user_id']);
    }

    // ---------------------------------------------------------------
    // Channel switching
    // ---------------------------------------------------------------

    public function testChannelReturnsNewLogInstance(): void
    {
        $app   = Log::channel('app');
        $audit = Log::channel('audit');

        $this->assertNotSame($app, $audit);
    }

    public function testChannelLogsToCorrectChannel(): void
    {
        $auditHandler = new TestHandler();
        $this->manager->addChannel('audit', new Logger('audit', [$auditHandler]));

        $log = Log::channel('audit');
        $log->info('Audit event');

        $this->assertTrue($auditHandler->hasInfoRecords());
        $this->assertFalse($this->handler->hasInfoRecords());
    }

    // ---------------------------------------------------------------
    // monolog
    // ---------------------------------------------------------------

    public function testMonologReturnsLoggerForDefaultChannel(): void
    {
        $logger = Log::monolog();

        $this->assertSame('app', $logger->getName());
    }

    // ---------------------------------------------------------------
    // reset and re-init
    // ---------------------------------------------------------------

    public function testResetAndReinitWorks(): void
    {
        Log::reset();

        $newHandler = new TestHandler();
        $newManager = new LogManager();
        $newManager->addChannel('app', new Logger('app', [$newHandler]));

        Log::init($newManager);

        $log = Log::channel('app');
        $log->info('After re-init');

        $this->assertTrue($newHandler->hasInfoRecords());
    }

    // ---------------------------------------------------------------
    // Empty message
    // ---------------------------------------------------------------

    public function testEmptyMessageIsLogged(): void
    {
        $log = Log::channel('app');
        $log->info('');

        $records = $this->handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('', $records[0]->message);
    }
}
