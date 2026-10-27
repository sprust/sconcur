<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Worker;

use RuntimeException;
use SConcur\Worker\WorkerProcess;

/**
 * A WorkerProcess around a php started with proc_open: what WorkerProcess does with a
 * worker's pipes and exit — the lines drained, the marks read, the outcome kept — tested
 * without a fork, which the test process cannot do (it runs the extension's runtime).
 *
 * The command is passed as an array (no shell), so pid() is the php process itself and
 * signals reach it directly. The heartbeat pipe is descriptor 3, which the command writes
 * its marks to itself (php://fd/3).
 */
class ProcOpenWorkerProcess extends WorkerProcess
{
    public const int HEARTBEAT_FD = 3;

    /** @var resource */
    protected mixed $process;

    /**
     * @param list<string> $command full command in array form (no shell)
     */
    public function __construct(array $command)
    {
        $pipes = [];

        $process = proc_open(
            command: $command,
            descriptor_spec: [
                0                  => ['file', '/dev/null', 'r'],
                1                  => ['pipe', 'w'],
                2                  => ['pipe', 'w'],
                self::HEARTBEAT_FD => ['pipe', 'w'],
            ],
            pipes: $pipes,
            cwd: sys_get_temp_dir(),
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn: ' . implode(' ', $command));
        }

        $this->process     = $process;
        $this->pid         = (int) proc_get_status($process)['pid'];
        $this->startedAtNs = hrtime(true);

        $this->stdoutPipe    = $pipes[1] ?? null;
        $this->stderrPipe    = $pipes[2] ?? null;
        $this->heartbeatPipe = $pipes[self::HEARTBEAT_FD] ?? null;

        foreach ([$this->stdoutPipe, $this->stderrPipe, $this->heartbeatPipe] as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }
    }

    protected function refresh(): void
    {
        if (!$this->running) {
            return;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            return;
        }

        $this->running = false;

        if ($status['signaled']) {
            $this->termSignal = (int) $status['termsig'];

            return;
        }

        $this->exitCode = (int) $status['exitcode'];
    }

    protected function release(): void
    {
        if (is_resource($this->process)) {
            @proc_close($this->process);
        }
    }
}
