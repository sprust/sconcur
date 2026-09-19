<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Worker\WorkerSpawnException;

/**
 * One live pool inside a master: the processes of a single WorkerGroupConfig, plus the
 * bookkeeping that keeps them up — the slots, the crash-loop backoff per slot and the
 * rolling reload in progress.
 *
 * The master owns what is process-wide (the lock, the state file, signals, telemetry)
 * and asks each group to reap, respawn and roll itself. Slot indices are local to a
 * group, so "worker 0" is always read together with the group it belongs to.
 */
class WorkerGroup
{
    protected const float HEALTHY_UPTIME_SECONDS = 1.0; // shorter run counts as a fast fail

    /** How long past its SIGKILL a worker has to still be there before that is reported. */
    protected const float SIGKILL_REPORT_AFTER_SECONDS = 2.0;

    /** @var array<int, WorkerProcess|null> live worker per slot (null while awaiting respawn) */
    protected array $slots = [];

    /** @var array<int, int> slot index => hrtime at which to respawn it */
    protected array $respawnAtNs = [];

    /** @var array<int, int> slot index => consecutive fast-fail count (drives backoff) */
    protected array $fastFails = [];

    protected int $workers = 0;

    protected bool $reloading = false;

    /** @var list<int> slot indices still to roll in the current rolling reload */
    protected array $reloadQueue = [];

    /** slot currently draining for reload (SIGTERM sent, awaiting exit), or -1 if none */
    protected int $reloadingIndex = -1;

    protected int $reloadDeadlineNs = 0;

    protected bool $reloadKillSent = false;

    /** Set while the group is being retired: its workers are drained and not replaced. */
    protected bool $retiring = false;

    /** @var array<int, int> slot index => hrtime at which the watchdog escalates to SIGKILL */
    protected array $watchdogDeadlineNs = [];

    /** @var array<int, bool> slot index => the watchdog's SIGKILL has already been sent */
    protected array $watchdogKillSent = [];

    /** @var array<int, bool> slot index => the journal already carries "survived SIGKILL" */
    protected array $watchdogKillReported = [];

    /**
     * @var array<int, float> slot index => how long its worker had been marking itself
     *                       alive when the watchdog condemned it. Taken then and not at
     *                       the exit, because a condemned worker goes on turning its loop
     *                       while it drains and would keep raising the figure that decides
     *                       whether it had been working or hung from the start.
     */
    protected array $watchdogServedSeconds = [];

    /**
     * @param string $telemetrySocket the collector socket the master listens on, empty
     *                                when telemetry is off. It reaches the workers through
     *                                their environment rather than their argv, so the
     *                                worker-agnostic master never feeds a non-matching
     *                                worker an unknown --flag
     */
    public function __construct(
        protected WorkerGroupConfig $config,
        protected MasterLogger $logger,
        protected int $masterPid,
        protected string $cwd,
        protected string $telemetrySocket = '',
    ) {
        $this->workers = $config->workerCount > 0 ? $config->workerCount : Cpu::count();
    }

    public function name(): string
    {
        return $this->config->name;
    }

    public function config(): WorkerGroupConfig
    {
        return $this->config;
    }

    public function workerCount(): int
    {
        return $this->workers;
    }

    public function shutdownTimeoutMs(): int
    {
        return $this->config->shutdownTimeoutMs;
    }

    public function isReloading(): bool
    {
        return $this->reloading;
    }

    public function isRetiring(): bool
    {
        return $this->retiring;
    }

    /** @return array<int, WorkerProcess|null> */
    public function slots(): array
    {
        return $this->slots;
    }

    public function hasPendingRespawns(): bool
    {
        return $this->respawnAtNs !== [];
    }

    public function allSlotsEmpty(): bool
    {
        foreach ($this->slots as $process) {
            if ($process !== null) {
                return false;
            }
        }

        return true;
    }

    public function aliveSlotCount(): int
    {
        $alive = 0;

        foreach ($this->slots as $process) {
            if ($process !== null) {
                ++$alive;
            }
        }

        return $alive;
    }

    public function spawnAll(): void
    {
        for ($index = 0; $index < $this->workers; ++$index) {
            $this->slots[$index] = null;

            $this->spawn($index);
        }
    }

    public function signalAll(int $signal): void
    {
        foreach ($this->slots as $process) {
            $process?->signal($signal);
        }
    }

    /**
     * Drains each live worker's output into the log and handles any that have exited.
     * $stopping suppresses the restart policy: the whole master is going away.
     */
    public function reapAndLog(bool $stopping): void
    {
        foreach ($this->slots as $index => $process) {
            if ($process === null) {
                continue;
            }

            $this->logWorkerLines(
                index: $index,
                process: $process,
                lines: $process->drainOutput(),
            );

            // Emptied every tick like the other two pipes: unread bytes would eventually
            // fill the pipe and block the worker on its next mark.
            $process->drainHeartbeat();

            if (!$process->isRunning()) {
                $this->handleExit(
                    index: $index,
                    process: $process,
                    stopping: $stopping,
                );
            }
        }
    }

    public function respawnDue(): void
    {
        $nowNs = hrtime(true);

        foreach ($this->respawnAtNs as $index => $dueAtNs) {
            if ($dueAtNs <= $nowNs && ($this->slots[$index] ?? null) === null) {
                unset($this->respawnAtNs[$index]);

                $this->spawn($index);
            }
        }
    }

    /**
     * Starts a rolling restart of every slot. Rolling one at a time is what keeps the
     * pool serving while it happens.
     */
    public function startReload(): void
    {
        if ($this->reloading) {
            return;
        }

        $this->reloading      = true;
        $this->reloadQueue    = array_keys($this->slots);
        $this->reloadingIndex = -1;
        $this->reloadKillSent = false;

        $this->logger->master(
            level: MasterLogger::INFO,
            message: sprintf('group %s: rolling %d worker(s)', $this->config->name, count($this->reloadQueue)),
        );
    }

    /**
     * Replaces the group's settings and rolls its workers onto them, one slot at a time,
     * so the group keeps serving throughout. A slot count that grew is filled as the roll
     * reaches it, and the slots that no longer fit are drained by the roll and not
     * replaced — so a resize is complete when the roll is, not the moment the config is
     * read.
     */
    public function reconfigure(WorkerGroupConfig $config): void
    {
        $this->config = $config;

        $this->workers = $config->workerCount > 0 ? $config->workerCount : Cpu::count();

        // A roll already under way was queued against the previous settings, and the
        // slots it has replaced are running them. Requeue every slot so none is left
        // behind on a config the operator has already replaced; startReload() alone
        // returns without doing anything while a roll is in flight.
        if ($this->reloading) {
            $this->reloadQueue = array_keys($this->slots);

            return;
        }

        $this->startReload();
    }

    /**
     * Takes the group back out of retirement, for one that reappeared in the config while
     * it was still draining. Its slots are refilled by the reconfigure that follows.
     */
    public function unretire(): void
    {
        $this->retiring = false;
    }

    /** Drains the group for good: its workers are asked to stop and never replaced. */
    public function retire(): void
    {
        if ($this->retiring) {
            return;
        }

        $this->retiring    = true;
        $this->respawnAtNs = [];

        $this->logger->master(
            level: MasterLogger::INFO,
            message: sprintf('group %s: removed from the config; draining', $this->config->name),
        );

        $this->signalAll(SIGTERM);
    }

    /**
     * Drives the rolling reload: for the current slot send SIGTERM, wait up to
     * shutdownTimeoutMs for it to drain (SIGKILL past that), then spawn a fresh
     * replacement and advance. Answers whether the reload is still running.
     */
    public function driveReload(): bool
    {
        if (!$this->reloading) {
            return false;
        }

        // A slot is mid-roll: wait for the worker to drain and exit (reapAndLog reaps it
        // into a null slot), escalating to SIGKILL once its drain deadline passes.
        if ($this->reloadingIndex !== -1) {
            $process = $this->slots[$this->reloadingIndex] ?? null;

            if ($process !== null) {
                if (!$this->reloadKillSent && hrtime(true) > $this->reloadDeadlineNs) {
                    $this->logWorker(
                        level: MasterLogger::WARN,
                        pid: $process->pid(),
                        index: $this->reloadingIndex,
                        message: 'reload drain timeout; sending SIGKILL',
                    );

                    $process->signal(SIGKILL);

                    $this->reloadKillSent = true;
                }

                return true;
            }

            $index = $this->reloadingIndex;

            $this->reloadingIndex = -1;
            $this->reloadKillSent = false;

            unset($this->respawnAtNs[$index]);

            // A slot past the (possibly shrunk) worker count is retired rather than
            // replaced — that is how a smaller workerCount takes effect on a reload.
            if ($index < $this->workers) {
                $this->spawn($index);
            } else {
                unset($this->slots[$index]);
            }

            return true;
        }

        // No slot in flight: finish the reload, or start the next slot in the queue.
        if ($this->reloadQueue === []) {
            $this->reloading = false;

            $this->fillMissingSlots();

            $this->logger->master(
                level: MasterLogger::INFO,
                message: sprintf('group %s: reload complete', $this->config->name),
            );

            return false;
        }

        $index = array_shift($this->reloadQueue);

        $process = $this->slots[$index] ?? null;

        // An already-empty slot (awaiting a crash respawn): just bring up a fresh one.
        if ($process === null) {
            unset($this->respawnAtNs[$index]);

            if ($index < $this->workers) {
                $this->spawn($index);
            } else {
                unset($this->slots[$index]);
            }

            return true;
        }

        $this->reloadingIndex   = $index;
        $this->reloadKillSent   = false;
        $this->reloadDeadlineNs = hrtime(true) + $this->config->shutdownTimeoutMs * 1_000_000;

        $this->logWorker(
            level: MasterLogger::INFO,
            pid: $process->pid(),
            index: $index,
            message: 'reloading; sending SIGTERM',
        );

        $process->signal(SIGTERM);

        return true;
    }

    /**
     * Kills a worker whose PHP thread stopped turning its serve loop, so its slot can be
     * refilled by the ordinary restart path. The evidence is the worker's own liveness
     * pipe (Heartbeat), written by that thread and drained by reapAndLog; a worker that
     * has never written to it is left alone, because a script without a serve loop never
     * will.
     *
     * SIGTERM first, SIGKILL after the group's shutdownTimeoutMs: a thread stuck in a
     * native call is usually freed by the signal itself (it interrupts the system call)
     * and then shuts down in order, while a CPU loop needs the second one.
     */
    public function driveWatchdog(): void
    {
        foreach ($this->slots as $index => $process) {
            if ($process === null) {
                continue;
            }

            // Already being unwound for a reload: its SIGTERM is sent and its deadline
            // runs in driveReload(). A second opinion here would only double the signals.
            if ($this->reloading && $index === $this->reloadingIndex) {
                continue;
            }

            // An armed slot is carried to its end whatever the config now says. Returning
            // early on a watchdog switched off by a reload would strand a worker that has
            // already been terminated, with the SIGKILL that frees its slot never sent.
            if (isset($this->watchdogDeadlineNs[$index])) {
                $this->escalateWatchdog(
                    index: $index,
                    process: $process,
                );

                continue;
            }

            if ($this->config->watchdogTimeoutMs <= 0) {
                continue;
            }

            $ageSeconds = $process->heartbeatAgeSeconds();

            if ($ageSeconds === null || ($ageSeconds * 1000) <= $this->config->watchdogTimeoutMs) {
                continue;
            }

            $this->watchdogDeadlineNs[$index]    = hrtime(true) + $this->config->shutdownTimeoutMs * 1_000_000;
            $this->watchdogKillSent[$index]      = false;
            $this->watchdogServedSeconds[$index] = $process->markedAliveForSeconds() ?? 0.0;

            $this->logWorker(
                level: MasterLogger::ERROR,
                pid: $process->pid(),
                index: $index,
                message: sprintf(
                    'no heartbeat for %.1fs (limit %dms); sending SIGTERM',
                    $ageSeconds,
                    $this->config->watchdogTimeoutMs,
                ),
            );

            $process->signal(SIGTERM);
        }
    }

    /** The second half of driveWatchdog: SIGKILL once the terminated worker overstays. */
    protected function escalateWatchdog(int $index, WorkerProcess $process): void
    {
        if (hrtime(true) <= $this->watchdogDeadlineNs[$index]) {
            return;
        }

        if ($this->watchdogKillSent[$index] ?? false) {
            $this->reportSurvivedKill(
                index: $index,
                process: $process,
            );

            return;
        }

        $this->watchdogKillSent[$index] = true;

        // The same deadline field now times the report below: a process that takes the
        // kill is reaped within a tick or two, so anything still here after this is not
        // going anywhere on its own.
        $this->watchdogDeadlineNs[$index] = hrtime(true)
            + (int) (self::SIGKILL_REPORT_AFTER_SECONDS * 1_000_000_000);

        $this->logWorker(
            level: MasterLogger::ERROR,
            pid: $process->pid(),
            index: $index,
            message: 'hung worker did not exit on SIGTERM; sending SIGKILL',
        );

        $process->signal(SIGKILL);
    }

    /**
     * Says once, and only once, that a worker outlived its SIGKILL. Nothing can be done
     * about it here — a process in uninterruptible I/O takes no signal at all — but the
     * slot is then down for as long as that lasts, and a silent watchdog would leave the
     * journal claiming the kill worked.
     */
    protected function reportSurvivedKill(int $index, WorkerProcess $process): void
    {
        if ($this->watchdogKillReported[$index] ?? false) {
            return;
        }

        $this->watchdogKillReported[$index] = true;

        $this->logWorker(
            level: MasterLogger::ERROR,
            pid: $process->pid(),
            index: $index,
            message: sprintf(
                'still alive %.1fs after SIGKILL; the slot stays down until the kernel releases it',
                self::SIGKILL_REPORT_AFTER_SECONDS,
            ),
        );
    }

    /** Drops whatever the watchdog had decided about the worker that held this slot. */
    protected function forgetWatchdog(int $index): void
    {
        unset(
            $this->watchdogDeadlineNs[$index],
            $this->watchdogKillSent[$index],
            $this->watchdogKillReported[$index],
            $this->watchdogServedSeconds[$index],
        );
    }

    /** Brings up the slots a grown workerCount added. */
    protected function fillMissingSlots(): void
    {
        for ($index = 0; $index < $this->workers; ++$index) {
            if (array_key_exists($index, $this->slots)) {
                continue;
            }

            $this->slots[$index] = null;

            $this->spawn($index);
        }
    }

    protected function spawn(int $index): void
    {
        if ($this->retiring) {
            return;
        }

        // The slot starts unjudged: the new worker inherits nothing the watchdog decided
        // about the one before it.
        $this->forgetWatchdog($index);

        try {
            $process = new WorkerProcess(
                command: $this->buildCommand(),
                cwd: $this->cwd,
                env: $this->buildEnv($index),
                heartbeat: $this->config->watchdogTimeoutMs > 0,
            );
        } catch (WorkerSpawnException $exception) {
            $backoffMs = $this->nextBackoffMs(
                index: $index,
                uptimeSeconds: 0.0,
            );

            $this->slots[$index]       = null;
            $this->respawnAtNs[$index] = hrtime(true) + $backoffMs * 1_000_000;

            $this->logger->master(
                level: MasterLogger::ERROR,
                message: sprintf(
                    'group %s: worker %d spawn failed: %s; retry in %dms',
                    $this->config->name,
                    $index,
                    $exception->getMessage(),
                    $backoffMs,
                ),
            );

            return;
        }

        $this->slots[$index] = $process;

        unset($this->respawnAtNs[$index]);

        $this->logWorker(
            level: MasterLogger::INFO,
            pid: $process->pid(),
            index: $index,
            message: 'spawned',
        );
    }

    protected function handleExit(int $index, WorkerProcess $process, bool $stopping): void
    {
        $this->logWorkerLines(
            index: $index,
            process: $process,
            lines: $process->drainFinalOutput(),
        );

        $pid           = $process->pid();
        $uptimeSeconds = $process->uptimeSeconds();
        $exitedCleanly = $process->exitedCleanly();

        $reason = $process->termSignal() !== null
            ? sprintf('signal=%d', $process->termSignal())
            : sprintf('code=%d', (int) $process->exitCode());

        $process->close();

        $this->slots[$index] = null;

        // Read before it is cleared: a death the watchdog caused is not a healthy worker
        // finishing, however long it had been up. Null when the watchdog had nothing to do
        // with this exit.
        $servedSeconds = $this->watchdogServedSeconds[$index] ?? null;

        $this->forgetWatchdog($index);

        if ($stopping || $this->retiring) {
            $this->logWorker(
                level: MasterLogger::INFO,
                pid: $pid,
                index: $index,
                message: sprintf('exited %s uptime=%.1fs (draining)', $reason, $uptimeSeconds),
            );

            return;
        }

        // The worker we are rolling for a reload exited on purpose: do not treat it as a
        // crash (no policy check, no backoff). driveReload() spawns its replacement.
        if ($this->reloading && $index === $this->reloadingIndex) {
            $this->logWorker(
                level: MasterLogger::INFO,
                pid: $pid,
                index: $index,
                message: sprintf('exited %s uptime=%.1fs (reloading)', $reason, $uptimeSeconds),
            );

            return;
        }

        if (!$this->config->restartPolicy->shouldRestart($exitedCleanly)) {
            $this->logWorker(
                level: MasterLogger::INFO,
                pid: $pid,
                index: $index,
                message: sprintf(
                    'exited %s uptime=%.1fs; not restarting (policy=%s)',
                    $reason,
                    $uptimeSeconds,
                    $this->config->restartPolicy->value,
                ),
            );

            return;
        }

        // A worker the watchdog killed is judged by how long it kept marking itself alive,
        // not by how long it existed: one that hung at startup goes on ageing while it
        // hangs, so its uptime would read as a long and healthy life. And it counts as
        // healthy only if it worked for longer than it then hung — otherwise a pool with a
        // shared cause cycles through work-hang-kill-respawn with nothing slowing it down.
        $backoffMs = $servedSeconds !== null
            ? $this->nextBackoffMs(
                index: $index,
                uptimeSeconds: $servedSeconds,
                healthyAfterSeconds: max(
                    self::HEALTHY_UPTIME_SECONDS,
                    $this->config->watchdogTimeoutMs / 1000,
                ),
            )
            : $this->nextBackoffMs(
                index: $index,
                uptimeSeconds: $uptimeSeconds,
            );

        $this->respawnAtNs[$index] = hrtime(true) + $backoffMs * 1_000_000;

        $this->logWorker(
            level: $exitedCleanly ? MasterLogger::INFO : MasterLogger::ERROR,
            pid: $pid,
            index: $index,
            message: sprintf('exited %s uptime=%.1fs; restarting in %dms', $reason, $uptimeSeconds, $backoffMs),
        );
    }

    /**
     * Computes the next respawn backoff for a slot: 0 when the worker ran long enough to
     * be considered healthy, otherwise an exponential delay that grows with each
     * consecutive fast fail (capped), preventing a crash-loop spin.
     *
     * For a worker the watchdog killed the caller passes the time it spent marking itself
     * alive rather than its uptime, and raises `healthyAfterSeconds` to the watchdog's own
     * threshold: a worker that hung at startup is then a fast fail and one that worked for
     * an hour before hanging is not, so the pool neither spins on a shared cause nor drifts
     * into the maximum backoff because it hangs once an hour.
     */
    protected function nextBackoffMs(
        int $index,
        float $uptimeSeconds,
        float $healthyAfterSeconds = self::HEALTHY_UPTIME_SECONDS,
    ): int {
        if ($uptimeSeconds >= $healthyAfterSeconds) {
            $this->fastFails[$index] = 0;

            return 0;
        }

        $fails = ($this->fastFails[$index] ?? 0) + 1;

        $this->fastFails[$index] = $fails;

        $backoffMs = $this->config->restartBackoffMs * (2 ** ($fails - 1));

        return (int) min($backoffMs, $this->config->maxRestartBackoffMs);
    }

    /**
     * Builds the worker command. The master's pid rides along as the `--masterPid` argv
     * flag — the same channel as the expanded `server` flags and the group's workerArgs,
     * no environment involved — for the worker to use as it sees fit.
     *
     * `display_errors=stderr` is forced ahead of the group's phpArgs so a dying worker
     * always explains itself: with the production-ini `display_errors=Off` a fatal
     * (parse error, missing extension, OOM) would leave nothing in the journal but
     * "exited code=255", and with the CLI default (`On` = stdout) the error text would
     * be logged as INFO instead of ERROR. A later `-d` wins, so phpArgs can still
     * override this deliberately.
     *
     * @return list<string>
     */
    protected function buildCommand(): array
    {
        return [
            $this->config->phpBinary,
            '-d',
            'display_errors=stderr',
            ...$this->config->phpArgs,
            $this->config->workerScript,
            ...$this->config->argumentFlags(),
            ...$this->config->workerArgs,
            WorkerMaster::MASTER_PID_ARG . '=' . $this->masterPid,
        ];
    }

    /**
     * The worker environment: the inherited environment, the pool label, then the
     * group's own env over both. No master metadata is injected here — that goes via
     * argv (see buildCommand).
     *
     * The label is "<group>:<slot>", which is what the worker puts on the snapshots it
     * pushes. It carries the slot so two workers of one pool are told apart, and the
     * group so the collector can add up a pool rather than a whole master — with
     * several pools under one supervisor, a master-wide sum would add unlike things.
     *
     * @return array<string, string>
     */
    protected function buildEnv(int $index): array
    {
        $env = getenv();

        // Labelled per worker and not per master, so the collector can tell the pools of
        // one master apart and aggregate each on its own.
        $env['SCONCUR_SERVER_NAME'] = $this->config->name . ':' . $index;

        if ($this->telemetrySocket !== '') {
            $env['SCONCUR_TELEMETRY_SOCKET'] = $this->telemetrySocket;
        }

        // Tells the worker which descriptor its master opened for the liveness pipe. Set
        // only with the watchdog on, so a worker under a master that does not watch does
        // not look for a pipe that is not there.
        if ($this->config->watchdogTimeoutMs > 0) {
            $env[Heartbeat::FD_ENVIRONMENT_NAME] = (string) Heartbeat::FD;
        }

        // The group's own env wins on a collision: it is the more specific setting.
        foreach ($this->config->env as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }

    /**
     * @param list<WorkerOutputLine> $lines
     */
    protected function logWorkerLines(int $index, WorkerProcess $process, array $lines): void
    {
        foreach ($lines as $line) {
            $this->logWorker(
                level: $line->isError ? MasterLogger::ERROR : MasterLogger::INFO,
                pid: $process->pid(),
                index: $index,
                message: $line->line,
            );
        }
    }

    protected function logWorker(string $level, int $pid, int $index, string $message): void
    {
        $this->logger->worker(
            level: $level,
            workerPid: $pid,
            workerIndex: $index,
            message: $message,
            group: $this->config->name,
        );
    }
}
