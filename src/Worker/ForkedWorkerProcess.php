<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Exceptions\Process\ForkRefusedException;
use SConcur\Exceptions\Process\ProcessException;
use SConcur\Exceptions\Worker\WorkerSpawnException;
use SConcur\Process\Dto\ForkResult;
use SConcur\Process\ProcessControl;

/**
 * A worker launched by forking the master (LaunchMode::Fork).
 *
 * The fork returns twice. In the master this object is built around the child's pid and
 * pipes; in the child the constructor hands over to $child, which turns the copy into the
 * worker and never returns — the master's call stack is still below it, and nothing of
 * the worker may run there.
 *
 * The extension prepares the child before PHP runs in it: stdout and stderr onto the
 * pipes read here, every other descriptor of the master pointed at /dev/null, the
 * master's signal watching undone, the random generators reseeded and the master's
 * objects marked as destroyed. See SConcur\Process\ProcessControl.
 */
class ForkedWorkerProcess extends WorkerProcess
{
    /**
     * @param bool                       $heartbeat       open the liveness pipe the watchdog reads
     * @param Closure(ForkResult): never $child           what the child runs; it never returns
     * @param list<int>                  $keepDescriptors descriptors the worker keeps, such as the
     *                                                    group's listening socket; every other one
     *                                                    of the master's is pointed at /dev/null
     */
    public function __construct(bool $heartbeat, Closure $child, array $keepDescriptors = [])
    {
        try {
            $fork = ProcessControl::fork(
                keepDescriptors: $keepDescriptors,
                captureOutput: true,
                heartbeat: $heartbeat,
            );
        } catch (ProcessException|ForkRefusedException $exception) {
            throw new WorkerSpawnException(
                message: 'Failed to fork a worker: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if ($fork->isChild()) {
            $child($fork);
        }

        $this->pid           = $fork->pid;
        $this->startedAtNs   = hrtime(true);
        $this->stdoutPipe    = $fork->stdout;
        $this->stderrPipe    = $fork->stderr;
        $this->heartbeatPipe = $fork->heartbeat;
    }

    protected function refresh(): void
    {
        if (!$this->running) {
            return;
        }

        foreach (ProcessControl::reap([$this->pid]) as $exit) {
            $this->running    = false;
            $this->exitCode   = $exit->exitCode;
            $this->termSignal = $exit->signal;
        }
    }

    /** Nothing to give back: the child was reaped as it was seen to exit. */
    protected function release(): void
    {
    }
}
