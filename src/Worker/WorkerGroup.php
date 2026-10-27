<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Exceptions\Worker\WorkerSpawnException;
use SConcur\Process\ProcessSignalEnum;

/**
 * One live pool inside a master: the processes of a single WorkerGroupConfig, plus the
 * bookkeeping that keeps them up — the slots, the crash-loop backoff per slot and the
 * watchdog.
 *
 * The master owns what is process-wide (the lock, the state file, signals, telemetry)
 * and asks each group to reap and respawn itself. Slot indices are local to a group, so
 * "worker 0" is always read together with the group it belongs to.
 *
 * A worker that gives up its slot while still running — leaving at its own limit with its
 * replacement already started — finishes outside the slots, in the draining list, so the
 * slot belongs to the worker that serves from it.
 */
class WorkerGroup
{
    public const float HEALTHY_UPTIME_SECONDS = 1.0; // shorter run counts as a fast fail

    /** How long past its SIGKILL a worker has to still be there before that is reported. */
    protected const float SIGKILL_REPORT_AFTER_SECONDS = 2.0;

    /** @var array<int, WorkerProcess|null> live worker per slot (null while awaiting respawn) */
    protected array $slots = [];

    /** @var array<int, int> slot index => hrtime at which to respawn it */
    protected array $respawnAtNs = [];

    /** @var array<int, int> slot index => consecutive fast-fail count (drives backoff) */
    protected array $fastFails = [];

    protected int $workers = 0;

    /** @var list<DrainingWorker> workers out of their slots, finishing before they exit */
    protected array $draining = [];

    /**
     * Whether a worker of this group has been up since the master started it: said so, or
     * kept running for longer than readyTimeoutMs. Until then a failure to start counts
     * towards neverCameUp().
     */
    protected bool $cameUp = false;

    /** Workers that failed before the group ever came up. */
    protected int $failedStarts = 0;

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
     * @param string                            $telemetrySocket      the collector socket the master listens on, empty
     *                                                                when telemetry is off. It reaches the workers through
     *                                                                their environment (SCONCUR_TELEMETRY_SOCKET), which a
     *                                                                server reads as it starts
     * @param null|Closure(WatchdogEvent): void $onWatchdogEvent      called when the watchdog
     *                                                                acts on a worker of this pool. It must not throw:
     *                                                                the master's (WorkerMaster::watchdogReporter) logs
     *                                                                and drops what the application's handler throws
     * @param list<int>                         $inheritedDescriptors the descriptors the master's process had open
     *                                                                before the master started — the application's —
     *                                                                which a forked worker keeps
     * @param string                            $accessLogPrefix      where the workers write their access lines when
     *                                                                the group's accessLog is AccessLogTarget::File, see
     *                                                                MasterLogger::accessLogPrefix
     */
    public function __construct(
        protected WorkerGroupConfig $config,
        protected MasterLogger $logger,
        protected int $masterPid,
        protected string $cwd,
        protected string $telemetrySocket = '',
        protected ?Closure $onWatchdogEvent = null,
        protected array $inheritedDescriptors = [],
        protected string $accessLogPrefix = '',
    ) {
        $this->workers = $config->resolvedWorkerCount();
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

    public function hasPendingRespawns(): bool
    {
        return $this->respawnAtNs !== [];
    }

    /** Whether no process of the group is left: none in a slot, draining or starting. */
    public function allSlotsEmpty(): bool
    {
        return $this->aliveSlotCount() === 0;
    }

    /** Every process of the group still running: in a slot or draining. */
    public function aliveSlotCount(): int
    {
        $alive = count($this->draining);

        foreach ($this->slots as $process) {
            if ($process !== null) {
                ++$alive;
            }
        }

        return $alive;
    }

    /**
     * Whether the group failed to start: as many of its workers as it has slots died
     * before any of them came up. A broken deploy then ends the master with an exit code
     * that systemd or an orchestrator acts on, instead of respawning into the same failure
     * with growing delays for as long as nobody reads the journal.
     */
    public function neverCameUp(): bool
    {
        return !$this->cameUp && $this->failedStarts >= $this->workers;
    }

    public function neverCameUpReason(): string
    {
        return sprintf('%d failed before any said it was up', $this->workers);
    }

    public function spawnAll(): void
    {
        for ($index = 0; $index < $this->workers; ++$index) {
            $this->slots[$index] = null;

            $this->spawn($index);
        }
    }

    public function signalAll(ProcessSignalEnum $signal): void
    {
        foreach ($this->slots as $process) {
            $process?->signal($signal);
        }

        foreach ($this->draining as $draining) {
            $draining->process->signal($signal);
        }
    }

    /**
     * Drains each live worker's output into the log and handles any that have exited.
     * $stopping suppresses the restart policy: the whole master is going away.
     *
     * The same for the workers draining out of their slots.
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

            $this->noteCameUp($process);

            if (!$process->isRunning()) {
                $this->handleExit(
                    index: $index,
                    process: $process,
                    stopping: $stopping,
                );

                continue;
            }

            if ($process->isRecycling() && !$stopping) {
                $this->recycle(
                    index: $index,
                    process: $process,
                );
            }
        }

        $this->reapDraining();
    }

    /**
     * What the group does once a tick besides reaping: kills the draining
     * workers that overstayed, runs the watchdog and respawns the slots that are due.
     */
    public function tick(): void
    {
        $this->driveDraining();

        $this->driveWatchdog();

        $this->respawnDue();
    }

    protected function respawnDue(): void
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
     * Kills the draining workers that overstayed their deadline: a worker leaving at its
     * limit that takes longer than the group's shutdownTimeoutMs to finish.
     */
    protected function driveDraining(): void
    {
        $nowNs = hrtime(true);

        foreach ($this->draining as $draining) {
            if ($draining->killSent || $nowNs <= $draining->deadlineNs) {
                continue;
            }

            $draining->killSent = true;

            $this->logWorker(
                level: MasterLogger::WARN,
                pid: $draining->process->pid(),
                index: $draining->index,
                message: sprintf('drain timeout (%s); sending SIGKILL', $draining->reason),
            );

            $draining->process->signal(ProcessSignalEnum::Kill);
        }
    }

    /**
     * Kills a worker whose PHP thread stopped turning its serve loop, so its slot can be
     * refilled by the ordinary restart path. The evidence is the worker's own liveness
     * pipe (Scheduler::markAlive), written by that thread and drained by reapAndLog; a worker
     * that has never written to it is left alone, because a role without a scheduler loop
     * never will.
     *
     * SIGTERM first, SIGKILL after the group's shutdownTimeoutMs: a thread stuck in a
     * native call is usually freed by the signal itself (it interrupts the system call)
     * and then shuts down in order, while a CPU loop needs the second one.
     */
    protected function driveWatchdog(): void
    {
        foreach ($this->slots as $index => $process) {
            if ($process === null) {
                continue;
            }

            // An armed slot is carried to its end: the worker has already been terminated,
            // and the SIGKILL that frees its slot must follow.
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

            $process->signal(ProcessSignalEnum::Terminate);

            $this->reportWatchdogEvent(
                event: WatchdogEventEnum::HeartbeatLost,
                index: $index,
                pid: $process->pid(),
                ageSeconds: $ageSeconds,
            );
        }
    }

    /** Logs the draining workers and lets go of the ones that are gone. */
    protected function reapDraining(): void
    {
        $remaining = [];

        foreach ($this->draining as $draining) {
            $process = $draining->process;

            $this->logWorkerLines(
                index: $draining->index,
                process: $process,
                lines: $process->drainOutput(),
            );

            $process->drainHeartbeat();

            if ($process->isRunning()) {
                $remaining[] = $draining;

                continue;
            }

            $this->logWorkerLines(
                index: $draining->index,
                process: $process,
                lines: $process->drainFinalOutput(),
            );

            $this->logWorker(
                level: MasterLogger::INFO,
                pid: $process->pid(),
                index: $draining->index,
                message: sprintf(
                    'exited %s uptime=%.1fs (%s)',
                    $process->exitReason(),
                    $process->uptimeSeconds(),
                    $draining->reason,
                ),
            );

            $process->close();
        }

        $this->draining = $remaining;
    }

    /**
     * Takes a worker that is stopping already — one that reached its own limit — out of its
     * slot to finish outside it. Its deadline is the group's shutdownTimeoutMs, past which
     * driveDraining() kills it.
     */
    protected function drain(int $index, WorkerProcess $process, string $reason): void
    {
        $this->draining[] = new DrainingWorker(
            process: $process,
            index: $index,
            deadlineNs: hrtime(true) + $this->config->shutdownTimeoutMs * 1_000_000,
            reason: $reason,
        );
    }

    /**
     * A worker said it is leaving because it reached its own limit: its replacement is
     * started now, while it is still finishing its last requests, so the slot is not
     * empty for the length of a drain and a start. Under Never nothing replaces it, and
     * it stays in its slot to be reaped there.
     */
    protected function recycle(int $index, WorkerProcess $process): void
    {
        if (!$this->config->restartPolicy->shouldRestart(cleanExit: true, reachedLimit: true)) {
            return;
        }

        $this->slots[$index] = null;

        // It served up to its limit: whatever failed in this slot before it is over, and
        // its replacement crashing starts the backoff afresh.
        $this->fastFails[$index] = 0;

        $this->forgetWatchdog($index);

        $this->drain(
            index: $index,
            process: $process,
            reason: 'reached its limit',
        );

        $this->logWorker(
            level: MasterLogger::INFO,
            pid: $process->pid(),
            index: $index,
            message: 'reached its limit; starting its replacement',
        );

        $this->spawn($index);
    }

    /**
     * Notes that the group came up: a worker said it was up, or kept running for longer
     * than readyTimeoutMs — the second for a role that never says so (no markReady()),
     * which is then judged the way it was before readiness existed.
     */
    protected function noteCameUp(WorkerProcess $process): void
    {
        if ($this->cameUp) {
            return;
        }

        if ($process->isReady() || ($process->uptimeSeconds() * 1000) >= $this->config->readyTimeoutMs) {
            $this->cameUp = true;
        }
    }

    /**
     * Counts a worker that failed before the group ever came up — exited with an error or
     * by a signal before it said it was up, or could not be started at all.
     */
    protected function noteFailedStart(): void
    {
        if (!$this->cameUp) {
            ++$this->failedStarts;
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

        $process->signal(ProcessSignalEnum::Kill);

        $this->reportWatchdogEvent(
            event: WatchdogEventEnum::KillEscalated,
            index: $index,
            pid: $process->pid(),
        );
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

        $this->reportWatchdogEvent(
            event: WatchdogEventEnum::KillSurvived,
            index: $index,
            pid: $process->pid(),
        );
    }

    /** Hands the event to the group's handler, if it has one; see the constructor's $onWatchdogEvent. */
    protected function reportWatchdogEvent(
        WatchdogEventEnum $event,
        int $index,
        int $pid,
        ?float $ageSeconds = null,
    ): void {
        if ($this->onWatchdogEvent === null) {
            return;
        }

        ($this->onWatchdogEvent)(new WatchdogEvent(
            event: $event,
            group: $this->config->name,
            slot: $index,
            pid: $pid,
            ageSeconds: $ageSeconds,
            watchdogTimeoutMs: $this->config->watchdogTimeoutMs,
        ));
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

    protected function spawn(int $index): void
    {
        // The slot starts unjudged: the new worker inherits nothing the watchdog decided
        // about the one before it.
        $this->forgetWatchdog($index);

        try {
            $process = $this->fork($index);
        } catch (WorkerSpawnException $exception) {
            $this->noteFailedStart();

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

        // Read again now that the worker is reaped: the tick read the pipe before asking
        // whether it was running, and a worker that said Ready or Recycling and exited in
        // between left the mark there. Missed, a worker at its limit would be taken for one
        // that finished its job, and one that came up for one that failed to start.
        $process->drainHeartbeat();

        $this->noteCameUp($process);

        $pid           = $process->pid();
        $uptimeSeconds = $process->uptimeSeconds();
        $exitedCleanly = $process->exitedCleanly();
        $reachedLimit  = $process->isRecycling();
        $reason        = $process->exitReason();

        // Died before it was up, and before it had run long enough to count as up without
        // saying so: a failure to start, which is what neverCameUp() is counting.
        if (
            !$stopping
            && !$exitedCleanly
            && !$process->isReady()
            && ($uptimeSeconds * 1000) < $this->config->readyTimeoutMs
        ) {
            $this->noteFailedStart();
        }

        $process->close();

        $this->slots[$index] = null;

        // Read before it is cleared: a death the watchdog caused is not a healthy worker
        // finishing, however long it had been up. Null when the watchdog had nothing to do
        // with this exit.
        $servedSeconds = $this->watchdogServedSeconds[$index] ?? null;

        $this->forgetWatchdog($index);

        if ($stopping) {
            $this->logWorker(
                level: MasterLogger::INFO,
                pid: $pid,
                index: $index,
                message: sprintf('exited %s uptime=%.1fs (draining)', $reason, $uptimeSeconds),
            );

            return;
        }

        // A worker the watchdog stopped failed, whatever code it left with: SIGTERM usually
        // frees the native call it hung in and it shuts down in order, with 0.
        $shouldRestart = $this->config->restartPolicy->shouldRestart(
            cleanExit: $exitedCleanly && $servedSeconds === null,
            reachedLimit: $reachedLimit,
        );

        if (!$shouldRestart) {
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

        // It asked to be replaced: at once, and without counting the exit against the slot.
        // Usually it said so while still draining and recycle() took it out of the slot
        // then; this is the one that said so and exited within the same tick.
        if ($reachedLimit) {
            $this->respawnAtNs[$index] = hrtime(true);
            $this->fastFails[$index]   = 0;

            $this->logWorker(
                level: MasterLogger::INFO,
                pid: $pid,
                index: $index,
                message: sprintf('exited %s uptime=%.1fs at its limit; restarting', $reason, $uptimeSeconds),
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

        return $this->config->backoffMs($fails);
    }

    /**
     * Forks this process — the master — into the worker of a slot. The child half
     * never returns: it becomes the worker in ForkedWorker::run() and leaves from there.
     * What it needs is taken here, before the fork, so the child reads plain values rather
     * than this group.
     */
    protected function fork(int $index): ForkedWorkerProcess
    {
        $title       = sprintf('sconcur-server: %s #%d', $this->config->name, $index);
        $cwd         = $this->cwd;
        $environment = $this->workerEnvironment($index);
        $role        = $this->config->role;
        $context     = new WorkerContext(
            group: $this->config->name,
            slot: $index,
            masterPid: $this->masterPid,
        );

        return new ForkedWorkerProcess(
            // A forked worker is the master's copy, the application loaded before the master
            // started included, and keeps what that application had open — a log file a
            // logger opened while loading would otherwise write to /dev/null in every worker.
            keepDescriptors: $this->inheritedDescriptors,
            child: static function () use ($title, $cwd, $environment, $role, $context): never {
                ForkedWorker::run(
                    title: $title,
                    cwd: $cwd,
                    environment: $environment,
                    role: $role,
                    context: $context,
                );
            },
        );
    }

    /**
     * What a worker's environment adds to the master's: the pool label, then the group's
     * own env over it.
     *
     * The label is "<group>:<slot>", which is what the worker puts on the snapshots it
     * pushes. It carries the slot so two workers of one pool are told apart, and the
     * group so the collector can add up a pool rather than a whole master — with
     * several pools under one supervisor, a master-wide sum would add unlike things.
     *
     * @return array<string, string>
     */
    protected function workerEnvironment(int $index): array
    {
        // Labelled per worker and not per master, so the collector can tell the pools of
        // one master apart and aggregate each on its own.
        $environment = ['SCONCUR_SERVER_NAME' => $this->config->name . ':' . $index];

        if ($this->telemetrySocket !== '') {
            $environment['SCONCUR_TELEMETRY_SOCKET'] = $this->telemetrySocket;
        }

        // Read by the extension's logger when the worker writes its first line. Set in
        // every case, empty for stdout, so a worker never acts on a value it inherited.
        $environment['SCONCUR_ACCESS_LOG'] = match ($this->config->accessLog) {
            AccessLogTarget::Stdout => '',
            AccessLogTarget::File   => $this->accessLogPrefix,
            AccessLogTarget::Off    => 'off',
        };

        // The group's own env wins on a collision: it is the more specific setting.
        foreach ($this->config->env as $key => $value) {
            $environment[$key] = $value;
        }

        return $environment;
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
