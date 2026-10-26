<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Exceptions\Worker\InvalidConfigException;
use SConcur\Worker\AccessLogTarget;
use SConcur\Worker\Cpu;
use SConcur\Worker\RestartPolicy;
use SConcur\Worker\WorkerGroupConfig;
use SConcur\Worker\WorkerMaster;

/**
 * What a group and a master accept as they are built: the settings that cannot work are
 * refused there, with the reason, before any worker starts.
 */
class WorkerGroupConfigTest extends TestCase
{
    public function testAGroupHasItsDefaults(): void
    {
        $group = $this->group();

        self::assertSame(Cpu::count(), $group->resolvedWorkerCount(), 'no count is one worker per core');
        self::assertSame(RestartPolicy::Always, $group->restartPolicy);
        self::assertSame(10_000, $group->shutdownTimeoutMs);
        self::assertSame(60_000, $group->watchdogTimeoutMs);
        self::assertSame(30_000, $group->readyTimeoutMs);
        self::assertSame(AccessLogTarget::Stdout, $group->accessLog);
        self::assertSame([], $group->env);
    }

    public function testAGroupNameThatIsNotAPathComponentIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('the name may contain only letters, digits');

        $this->group(name: 'a/b');
    }

    public function testANegativeCountIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('group "http": workerCount must be >= 0');

        $this->group(workerCount: -1);
    }

    public function testANegativeTimingIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('group "http": shutdownTimeoutMs must be >= 0');

        $this->group(shutdownTimeoutMs: -1);
    }

    public function testAWatchdogThresholdBelowTheFloorIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('group "http": watchdogTimeoutMs must be 0 (off) or at least 5000ms');

        $this->group(watchdogTimeoutMs: 600);
    }

    public function testTheWatchdogIsSwitchedOffByZero(): void
    {
        self::assertSame(0, $this->group(watchdogTimeoutMs: 0)->watchdogTimeoutMs);
        self::assertSame(
            WorkerGroupConfig::MIN_WATCHDOG_TIMEOUT_MS,
            $this->group(watchdogTimeoutMs: WorkerGroupConfig::MIN_WATCHDOG_TIMEOUT_MS)->watchdogTimeoutMs,
        );
    }

    public function testAReadyTimeoutBelowTheFloorIsRefused(): void
    {
        // There is no "off": a zero would count every worker that starts as a failed start.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('group "http": readyTimeoutMs must be at least 1000ms');

        $this->group(readyTimeoutMs: 0);
    }

    public function testTheBackoffDoublesUpToItsCeiling(): void
    {
        $group = $this->group(restartBackoffMs: 200, maxRestartBackoffMs: 1_000);

        self::assertSame(
            [0, 200, 400, 800, 1_000, 1_000],
            array_map($group->backoffMs(...), [0, 1, 2, 3, 4, 5]),
        );
    }

    public function testAMasterWithoutGroupsIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('at least one group');

        new WorkerMaster(
            runtimeDir: sys_get_temp_dir(),
            groups: [],
        );
    }

    public function testTwoGroupsWithTheSameNameAreRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('group "http" is named twice');

        new WorkerMaster(
            runtimeDir: sys_get_temp_dir(),
            groups: [$this->group(), $this->group()],
        );
    }

    public function testAMasterNameThatIsNotAPathComponentIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('the name may contain only letters, digits');

        new WorkerMaster(
            runtimeDir: sys_get_temp_dir(),
            groups: [$this->group()],
            name: '../escape',
        );
    }

    protected function group(
        string $name = 'http',
        int $workerCount = 0,
        int $shutdownTimeoutMs = 10_000,
        int $restartBackoffMs = 200,
        int $maxRestartBackoffMs = 30_000,
        int $watchdogTimeoutMs = 60_000,
        int $readyTimeoutMs = 30_000,
    ): WorkerGroupConfig {
        return new WorkerGroupConfig(
            name: $name,
            role: static function (): void {
            },
            workerCount: $workerCount,
            shutdownTimeoutMs: $shutdownTimeoutMs,
            restartBackoffMs: $restartBackoffMs,
            maxRestartBackoffMs: $maxRestartBackoffMs,
            watchdogTimeoutMs: $watchdogTimeoutMs,
            readyTimeoutMs: $readyTimeoutMs,
        );
    }
}
