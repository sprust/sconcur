<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Worker;

use SConcur\Worker\MasterLogger;
use SConcur\Worker\RestartPolicy;
use SConcur\Worker\WorkerGroup;
use SConcur\Worker\WorkerGroupConfig;
use SConcur\Worker\WorkerProcess;

/**
 * A WorkerGroup that takes a worker the test started itself and hands its exit to the
 * group as reaping does: what the group decides from how a worker left, tested without a
 * fork, which the test process cannot do (it runs the extension's runtime).
 */
class ExitingWorkerGroup extends WorkerGroup
{
    public static function make(MasterLogger $logger, RestartPolicy $restartPolicy): self
    {
        return new self(
            config: new WorkerGroupConfig(
                name: 'http',
                role: static function (): void {
                },
                workerCount: 1,
                restartPolicy: $restartPolicy,
            ),
            logger: $logger,
            masterPid: 12345,
            cwd: sys_get_temp_dir(),
        );
    }

    public function exited(WorkerProcess $process): void
    {
        $this->slots[0] = $process;

        $this->handleExit(
            index: 0,
            process: $process,
            stopping: false,
        );
    }
}
