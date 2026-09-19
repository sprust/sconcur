<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Worker;

use Closure;
use SConcur\Worker\MasterConfig;
use SConcur\Worker\MasterLogger;
use SConcur\Worker\WatchdogEventEnum;
use SConcur\Worker\WorkerGroup;

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
        $config = MasterConfig::fromArray([
            'watchdogTimeoutMs' => $watchdogTimeoutMs,
            'groups'            => [
                ['name' => 'http', 'workerScript' => __FILE__],
            ],
        ]);

        return new self(
            config: $config->groups()[0],
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
