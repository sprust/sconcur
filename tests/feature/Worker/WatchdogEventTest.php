<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use Closure;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use SConcur\Tests\Impl\Worker\ReportingWorkerGroup;
use SConcur\Worker\WorkerGroupConfig;
use SConcur\Worker\MasterLogger;
use SConcur\Worker\WatchdogEvent;
use SConcur\Worker\WatchdogEventEnum;
use SConcur\Worker\WorkerMaster;

/**
 * What the master hands the application when its watchdog acts, and what happens when
 * the application's own handler throws.
 */
class WatchdogEventTest extends TestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sc-watchdog-' . uniqid('', true);

        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function testTheHandlerIsToldWhichWorkerAndWhy(): void
    {
        $received = [];

        $group = ReportingWorkerGroup::make(
            logger: $this->logger(),
            watchdogTimeoutMs: 30_000,
            onWatchdogEvent: static function (WatchdogEvent $event) use (&$received): void {
                $received[] = $event;
            },
        );

        $group->report(
            event: WatchdogEventEnum::HeartbeatLost,
            pid: 4711,
            ageSeconds: 31.5,
        );

        self::assertCount(1, $received);

        $event = $received[0];

        self::assertSame(WatchdogEventEnum::HeartbeatLost, $event->event);
        self::assertSame('http', $event->group);
        self::assertSame(0, $event->slot);
        self::assertSame(4711, $event->pid);
        self::assertSame(31.5, $event->ageSeconds);
        self::assertSame(30_000, $event->watchdogTimeoutMs);
    }

    /**
     * Caught by the master's reporter, which every event passes through on its way to the
     * application's handler; a group hands the event on as it is.
     */
    public function testAHandlerThatThrowsIsLoggedAndDoesNotEscape(): void
    {
        $logger = $this->logger();

        $handler = static function (): void {
            throw new RuntimeException('the alerting backend is down');
        };

        $master = new class(runtimeDir: $this->directory, groups: [static::group()], onWatchdogEvent: $handler) extends WorkerMaster {
            public function reporterLoggingTo(MasterLogger $logger): Closure
            {
                $this->logger = $logger;

                return $this->watchdogReporter();
            }
        };

        $group = ReportingWorkerGroup::make(
            logger: $logger,
            watchdogTimeoutMs: 30_000,
            onWatchdogEvent: $master->reporterLoggingTo($logger),
        );

        // No exception: an application's alerting must not be able to stop the supervisor.
        $group->report(
            event: WatchdogEventEnum::KillEscalated,
            pid: 4711,
        );

        $logger->close();

        $log = $this->logText();

        self::assertStringContainsString('watchdog handler failed', $log);
        self::assertStringContainsString('the alerting backend is down', $log);
    }

    public function testWithoutAHandlerNothingHappens(): void
    {
        $group = ReportingWorkerGroup::make(
            logger: $this->logger(),
            watchdogTimeoutMs: 30_000,
            onWatchdogEvent: null,
        );

        $group->report(
            event: WatchdogEventEnum::KillSurvived,
            pid: 4711,
        );

        $this->expectNotToPerformAssertions();
    }

    public function testTheHandlerReachesTheMaster(): void
    {
        $handler = static function (WatchdogEvent $event): void {
        };

        $master = new WorkerMaster(
            runtimeDir: sys_get_temp_dir(),
            groups: [static::group()],
            onWatchdogEvent: $handler,
        );

        self::assertSame($handler, $this->handlerOf($master));
    }

    protected static function group(): WorkerGroupConfig
    {
        return new WorkerGroupConfig(
            name: 'http',
            role: static function (): void {
            },
        );
    }

    protected function handlerOf(WorkerMaster $master): ?Closure
    {
        $property = new ReflectionProperty(WorkerMaster::class, 'onWatchdogEvent');

        /** @var null|Closure $handler */
        $handler = $property->getValue($master);

        return $handler;
    }

    protected function logger(): MasterLogger
    {
        return new MasterLogger($this->directory, 'sconcur-test-master', 3, 12345);
    }

    protected function logText(): string
    {
        $text = '';

        foreach ((array) glob($this->directory . '/*') as $file) {
            if (is_string($file)) {
                $text .= (string) file_get_contents($file);
            }
        }

        return $text;
    }
}
