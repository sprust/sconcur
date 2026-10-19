<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Worker\WorkerSpawnException;

/**
 * A worker launched as a php process of its own, via proc_open.
 *
 * The command is passed as an array (no shell), so pid() is the php process itself
 * and signals reach it directly — there is no intermediate `sh -c` to absorb them.
 *
 * Everything above stderr is shut out of the worker (see inheritedDescriptorGuard):
 * proc_open hands the child every other descriptor the master holds, and none of them
 * are the worker's business.
 */
class ExecWorkerProcess extends WorkerProcess
{
    /** @var resource */
    protected mixed $process;

    /**
     * @param list<string>          $command   full command in array form (no shell)
     * @param string                $cwd       working directory for the worker
     * @param array<string, string> $env       full environment for the worker
     * @param bool                  $heartbeat open the liveness pipe the watchdog reads
     */
    public function __construct(array $command, string $cwd, array $env, bool $heartbeat = false)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        if ($heartbeat) {
            $descriptors[Heartbeat::FD] = ['pipe', 'w'];
        }

        $descriptors += self::inheritedDescriptorGuard();

        $pipes = [];

        $process = proc_open(
            command: $command,
            descriptor_spec: $descriptors,
            pipes: $pipes,
            cwd: $cwd,
            env_vars: $env,
        );

        if (!is_resource($process)) {
            throw new WorkerSpawnException(
                message: 'Failed to spawn worker process: ' . implode(' ', $command),
            );
        }

        $status = proc_get_status($process);

        $this->process     = $process;
        $this->pid         = (int) $status['pid'];
        $this->startedAtNs = hrtime(true);

        // stdin is unused; close it so the worker never blocks waiting on it.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $this->stdoutPipe    = $pipes[1] ?? null;
        $this->stderrPipe    = $pipes[2] ?? null;
        $this->heartbeatPipe = $pipes[Heartbeat::FD] ?? null;

        foreach ([$this->stdoutPipe, $this->stderrPipe, $this->heartbeatPipe] as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }
    }

    /**
     * A descriptor spec that points every descriptor above stderr at /dev/null in the
     * child, so the worker inherits none of the master's own. The caller's own entries
     * win over it, which is how the liveness pipe keeps Heartbeat::FD.
     *
     * proc_open only rewires the descriptors it is given and leaves the rest open in the
     * child. The master holds the telemetry collector's unix listener and one accepted
     * socket per live worker, the panel's listener and its open browser connections, its
     * lock, state and log files, and the pipes of every other worker — a worker spawned
     * later got a copy of all of them, kept them for its whole life and could hold a lock
     * or a connection open long after the master let go of it. PHP cannot set FD_CLOEXEC
     * on a stream, so the spawn itself is where this is closed.
     *
     * Reads /proc, which is where this library already runs; without it the guard is
     * empty and the spawn behaves as it did before.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    protected static function inheritedDescriptorGuard(): array
    {
        $entries = @scandir('/proc/self/fd');

        if ($entries === false) {
            return [];
        }

        $guard = [];

        foreach ($entries as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }

            $descriptor = (int) $entry;

            // 0/1/2 are the worker's own pipes, spelled out by the caller.
            if ($descriptor < 3) {
                continue;
            }

            $guard[$descriptor] = ['file', '/dev/null', 'r'];
        }

        return $guard;
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

    /** Reaps the process (proc_close), preventing a zombie; it blocks until the process is gone. */
    protected function release(): void
    {
        if (is_resource($this->process)) {
            @proc_close($this->process);
        }
    }
}
