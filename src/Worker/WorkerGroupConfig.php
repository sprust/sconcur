<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Exceptions\Worker\InvalidConfigException;

/**
 * One pool inside a master: a role, how many processes run it, and how they are kept up.
 * A master runs several, and they need not be alike — an HTTP server pool and a pool of
 * queue consumers live under one supervisor, one lock and one journal.
 *
 * The role is what a worker of the group does: a closure the worker calls right after its
 * fork, with everything the master's process had loaded in its memory. It builds a server,
 * a consumer or a loop of its own and runs it; the worker leaves when the role returns.
 */
readonly class WorkerGroupConfig
{
    /**
     * The shortest watchdog threshold that can be honoured. A worker marks itself alive at
     * most twice a second (Scheduler::markAlive), so anything near that reads an ordinary
     * gap between two marks as a hang and kills every worker of the group on sight —
     * including the replacements, forever. Ten marks' worth leaves room for a GC pause, a
     * descheduled process and the master's own tick.
     */
    public const int MIN_WATCHDOG_TIMEOUT_MS = 5_000;

    /**
     * The shortest wait for a worker to say it is up that is accepted. A serve loop says it
     * is up after its first wait for work, which may take a quarter of a second on an idle
     * server.
     */
    public const int MIN_READY_TIMEOUT_MS = 1_000;

    /**
     * @param string                       $name                what the journal, the state file and the
     *                                                          telemetry call the group: letters, digits,
     *                                                          ".", "_" and "-"
     * @param Closure(WorkerContext): void $role                what a worker of the group runs, see above
     * @param int                          $workerCount         how many workers to keep up; 0 is one per logical CPU
     * @param RestartPolicy                $restartPolicy       which exits are followed by a new worker
     * @param int                          $shutdownTimeoutMs   how long a stopping worker may drain before it is
     *                                                          killed
     * @param int                          $restartBackoffMs    the first wait before restarting a worker that
     *                                                          died soon after it started, doubled with each
     *                                                          such death in a row
     * @param int                          $maxRestartBackoffMs the longest that wait grows to
     * @param int                          $watchdogTimeoutMs   how long a worker's PHP thread may go without
     *                                                          marking itself alive before it is killed: 0 is
     *                                                          off, anything else at least
     *                                                          MIN_WATCHDOG_TIMEOUT_MS
     * @param int                          $readyTimeoutMs      a worker that dies before it says it is up, and
     *                                                          within this long, failed to start; at least
     *                                                          MIN_READY_TIMEOUT_MS
     * @param AccessLogTarget              $accessLog           where the workers write their access lines
     * @param array<string, string>        $env                 what a worker's environment adds to the master's
     */
    public function __construct(
        public string $name,
        public Closure $role,
        public int $workerCount = 0,
        public RestartPolicy $restartPolicy = RestartPolicy::Always,
        public int $shutdownTimeoutMs = 10_000,
        public int $restartBackoffMs = 200,
        public int $maxRestartBackoffMs = 30_000,
        public int $watchdogTimeoutMs = 60_000,
        public int $readyTimeoutMs = 30_000,
        public AccessLogTarget $accessLog = AccessLogTarget::Stdout,
        public array $env = [],
    ) {
        // The name reaches the journal and file names, so it is kept to a safe charset.
        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw new InvalidConfigException(
                message: sprintf('group "%s": the name may contain only letters, digits, ".", "_" and "-"', $name),
            );
        }

        $counts = [
            'workerCount'         => $workerCount,
            'shutdownTimeoutMs'   => $shutdownTimeoutMs,
            'restartBackoffMs'    => $restartBackoffMs,
            'maxRestartBackoffMs' => $maxRestartBackoffMs,
        ];

        foreach ($counts as $setting => $value) {
            if ($value < 0) {
                throw new InvalidConfigException(
                    message: sprintf('group "%s": %s must be >= 0', $name, $setting),
                );
            }
        }

        // Refused rather than clamped: a threshold below the floor is a misunderstanding of
        // what the number means, and serving a different one in silence would hide it.
        if ($watchdogTimeoutMs !== 0 && $watchdogTimeoutMs < self::MIN_WATCHDOG_TIMEOUT_MS) {
            throw new InvalidConfigException(
                message: sprintf(
                    'group "%s": watchdogTimeoutMs must be 0 (off) or at least %dms',
                    $name,
                    self::MIN_WATCHDOG_TIMEOUT_MS,
                ),
            );
        }

        // There is no "off": a zero would count every worker that starts as a failed start.
        if ($readyTimeoutMs < self::MIN_READY_TIMEOUT_MS) {
            throw new InvalidConfigException(
                message: sprintf(
                    'group "%s": readyTimeoutMs must be at least %dms',
                    $name,
                    self::MIN_READY_TIMEOUT_MS,
                ),
            );
        }
    }

    /** How many workers the group keeps: workerCount, or one per logical CPU (Cpu::count) when that is 0. */
    public function resolvedWorkerCount(): int
    {
        return $this->workerCount > 0 ? $this->workerCount : Cpu::count();
    }

    /**
     * How long to wait before a restart that follows this many fast failures in a row: not
     * at all after none, then restartBackoffMs, doubled with each further one, up to
     * maxRestartBackoffMs. Keeps a crash loop from spinning.
     */
    public function backoffMs(int $consecutiveFastFails): int
    {
        if ($consecutiveFastFails <= 0) {
            return 0;
        }

        return (int) min(
            $this->restartBackoffMs * (2 ** ($consecutiveFastFails - 1)),
            $this->maxRestartBackoffMs,
        );
    }
}
