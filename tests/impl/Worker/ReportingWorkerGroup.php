<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Worker;

use Closure;
use SConcur\Worker\MasterLogger;
use SConcur\Worker\WatchdogEventEnum;
use SConcur\Worker\WorkerGroup;
use SConcur\Worker\WorkerGroupConfig;

/**
 * A WorkerGroup that lets a test fire a watchdog event without a live worker behind it:
 * the reporting path is the part an application plugs into, and it has nothing to do
 * with how the process it names died.
 */
class ReportingWorkerGroup extends WorkerGroup
{
    public static function make(
        MasterLogger $logger,
        int $watchdogTimeoutMs,
        ?Closure $onWatchdogEvent,
    ): self {
        return new self(
            config: new WorkerGroupConfig(
                name: 'http',
                role: static function (): void {
                },
                watchdogTimeoutMs: $watchdogTimeoutMs,
            ),
            logger: $logger,
            masterPid: 12345,
            cwd: sys_get_temp_dir(),
            telemetrySocket: '',
            onWatchdogEvent: $onWatchdogEvent,
        );
    }

    public function report(WatchdogEventEnum $event, int $pid, ?float $ageSeconds = null): void
    {
        $this->reportWatchdogEvent(
            event: $event,
            index: 0,
            pid: $pid,
            ageSeconds: $ageSeconds,
        );
    }
}
