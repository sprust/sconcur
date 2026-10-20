<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Exceptions\Worker\WorkerSpawnException;
use SConcur\Process\Dto\ForkResult;
use SConcur\Process\ProcessSignalEnum;
use Throwable;

/**
 * One live pool inside a master: the processes of a single WorkerGroupConfig, plus the
 * bookkeeping that keeps them up — the slots, the crash-loop backoff per slot and the
 * rolling reload in progress.
 *
 * The master owns what is process-wide (the lock, the state file, signals, telemetry)
 * and asks each group to reap, respawn and roll itself. Slot indices are local to a
 * group, so "worker 0" is always read together with the group it belongs to.
 *
 * A worker that gives up its slot while still running — replaced by a reload, or leaving
 * at its own limit with its replacement already started — finishes outside the slots, in
 * the draining list, so the slot belongs to the worker that serves from it.
 */
class WorkerGroup implements SupervisedGroup
{
    public const float HEALTHY_UPTIME_SECONDS = 1.0; // shorter run counts as a fast fail

    /** The argv flag that tells a server which descriptor is its listening socket. */
    protected const string LISTEN_FD_ARG = '--listenFd';

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

    /** slot whose replacement is starting, or -1 if none */
    protected int $reloadingIndex = -1;

    /** The replacement the reload is waiting on to say it is up, outside the slots until it does. */
    protected ?WorkerProcess $reloadCandidate = null;

    /** hrtime past which the replacement has not come up in time and the reload is given up. */
    protected int $reloadCandidateDeadlineNs = 0;

    /** The worker the last replacement took over from: the next slot waits until it is gone. */
    protected ?DrainingWorker $reloadOutgoing = null;

    /** The settings in force before the reload under way, put back if it is given up. */
    protected ?WorkerGroupConfig $configBeforeReload = null;

    /** Why the last reload was given up, until the master takes it; empty when it was not. */
    protected string $reloadFailure = '';

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
     * @param string                            $telemetrySocket      the collector socket the master listens on, empty
     *                                                                when telemetry is off. It reaches the workers through
     *                                                                their environment rather than their argv, so the
     *                                                                worker-agnostic master never feeds a non-matching
     *                                                                worker an unknown --flag
     * @param null|Closure(WatchdogEvent): void $onWatchdogEvent      called when the watchdog
     *                                                                acts on a worker of this pool. Failures in it are
     *                                                                logged and swallowed: watching must not be able to
     *                                                                take the supervisor down
     * @param list<int>                         $inheritedDescriptors the descriptors the master found open when it
     *                                                                started, which a forked worker keeps
     * @param string                            $accessLogPrefix      where the workers write their access lines when
     *                                                                the group says `accessLog: file`, see
     *                                                                MasterLogger::accessLogPrefix
     * @param bool                              $guardsStartup        whether a group that never comes up stops the
     *                                                                master (neverCameUp): true for the groups the
     *                                                                master started with, false for one a reload
     *                                                                added, which must not take a running pool down
     */
    public function __construct(
        protected WorkerGroupConfig $config,
        protected MasterLogger $logger,
        protected int $masterPid,
        protected string $cwd,
        protected string $telemetrySocket = '',
        protected ?Closure $onWatchdogEvent = null,
        protected ?int $listenFd = null,
        protected array $inheritedDescriptors = [],
        protected bool $guardsStartup = false,
        protected string $accessLogPrefix = '',
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

    /** The listening socket the master bound for this group, or null when it has none. */
    public function listenFd(): ?int
    {
        return $this->listenFd;
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

    /** Whether no process of the group is left: none in a slot, draining or starting. */
    public function allSlotsEmpty(): bool
    {
        return $this->aliveSlotCount() === 0;
    }

    /** Every process of the group still running: in a slot, draining, or starting for a reload. */
    public function aliveSlotCount(): int
    {
        $alive = count($this->draining);

        if ($this->reloadCandidate !== null) {
            ++$alive;
        }

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
        return $this->guardsStartup && !$this->cameUp && $this->failedStarts >= $this->workers;
    }

    /** Why the last reload was given up, once; empty when it was not. */
    public function takeReloadFailure(): string
    {
        $failure = $this->reloadFailure;

        $this->reloadFailure = '';

        return $failure;
    }

    /** @return list<int> the slots the group keeps, empty ones awaiting a respawn included */
    public function slotIndices(): array
    {
        return array_keys($this->slots);
    }

    /** The worker of a slot, or null while the slot is empty or not this group's. */
    public function slotWorker(int $index): ?WorkerProcess
    {
        return $this->slots[$index] ?? null;
    }

    /**
     * Every process of the group still running — in a slot, draining, or starting for a
     * reload — by pid: what the master stops itself when the fork server that ran them is gone.
     *
     * @return list<int>
     */
    public function livePids(): array
    {
        $pids = [];

        foreach ($this->slots as $process) {
            if ($process !== null) {
                $pids[] = $process->pid();
            }
        }

        foreach ($this->draining as $draining) {
            $pids[] = $draining->process->pid();
        }

        if ($this->reloadCandidate !== null) {
            $pids[] = $this->reloadCandidate->pid();
        }

        return $pids;
    }

    /**
     * Brings a slot up on a command of the master: a reload moving the slot into this
     * generation from the one before it. A slot that already has its worker is left as it
     * is.
     */
    public function spawnSlot(int $index): void
    {
        if (($this->slots[$index] ?? null) !== null) {
            return;
        }

        $this->slots[$index] = null;

        $this->spawn(
            index: $index,
            message: 'spawned as a replacement; waiting for it to be up',
        );
    }

    /**
     * Gives a slot up on a command of the master: a reload moved it to the next generation,
     * or stops a slot the next config no longer has. Its worker is asked to stop and drains
     * outside the slots, and the slot is not refilled. Answers the draining worker, for
     * the caller to see when it is gone, or null when the slot had none.
     */
    public function releaseSlot(int $index, string $reason): ?DrainingWorker
    {
        $process = $this->slots[$index] ?? null;

        unset($this->slots[$index], $this->respawnAtNs[$index]);

        $this->forgetWatchdog($index);

        if ($process === null) {
            return null;
        }

        return $this->drain(
            index: $index,
            process: $process,
            reason: $reason,
        );
    }

    /** Whether a worker the group let go of is still finishing. */
    public function isDraining(DrainingWorker $draining): bool
    {
        return in_array($draining, $this->draining, true);
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

        $this->reloadCandidate?->signal($signal);

        foreach ($this->draining as $draining) {
            $draining->process->signal($signal);
        }
    }

    /**
     * Drains each live worker's output into the log and handles any that have exited.
     * $stopping suppresses the restart policy: the whole master is going away.
     *
     * The same for the processes outside the slots: the replacement a reload is waiting on,
     * whose death ends the reload, and the workers draining out.
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

        $this->reapCandidate($stopping);

        $this->reapDraining();
    }

    public function respawnDue(): void
    {
        $nowNs = hrtime(true);

        foreach ($this->respawnAtNs as $index => $dueAtNs) {
            // The slot a reload is bringing a replacement up for gets that replacement.
            if ($index === $this->reloadingIndex) {
                continue;
            }

            if ($dueAtNs <= $nowNs && ($this->slots[$index] ?? null) === null) {
                unset($this->respawnAtNs[$index]);

                $this->spawn($index);
            }
        }
    }

    /**
     * Kills the draining workers that overstayed their deadline. Called every tick, a
     * reload or not: a worker leaving at its limit drains here too.
     */
    public function driveDraining(): void
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
     * Starts a rolling restart of every slot. One slot at a time, and each replacement
     * has to say it is up before the worker it replaces is stopped, so the group serves
     * at full strength throughout and a version that does not come up goes no further
     * than one replacement.
     */
    public function startReload(): void
    {
        if ($this->reloading) {
            return;
        }

        $this->reloading      = true;
        $this->reloadQueue    = array_keys($this->slots);
        $this->reloadingIndex = -1;
        $this->reloadOutgoing = null;
        $this->configBeforeReload ??= $this->config;

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
     * read. A roll that is given up puts the settings it replaced back.
     */
    public function reconfigure(WorkerGroupConfig $config): void
    {
        $this->configBeforeReload ??= $this->config;

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

        // After the flag: a roll given up fills its missing slots, which a retiring group
        // must not do.
        if ($this->reloading) {
            $this->abortReload('the group was removed from the config');
        }

        $this->logger->master(
            level: MasterLogger::INFO,
            message: sprintf('group %s: removed from the config; draining', $this->config->name),
        );

        $this->signalAll(ProcessSignalEnum::Terminate);
    }

    /**
     * Drives the rolling reload, one slot at a time: start a replacement beside the
     * worker, wait for it to say it is up (Scheduler::markReady), only then put it in the
     * slot and stop the worker it replaces, and wait for that one to be gone before the
     * next slot. A replacement that dies first, or is not up within readyTimeoutMs, gives
     * the reload up: the slots not reached yet keep their workers, and the settings the
     * reload brought are put back for the respawns to come. Answers whether the reload is
     * still running.
     */
    public function driveReload(): bool
    {
        if (!$this->reloading) {
            return false;
        }

        if ($this->reloadCandidate !== null) {
            if ($this->reloadCandidate->isReady()) {
                $this->promoteCandidate();

                return true;
            }

            if (hrtime(true) > $this->reloadCandidateDeadlineNs) {
                $this->abortReload(
                    sprintf(
                        'the replacement for worker %d did not say it was up within %dms',
                        $this->reloadingIndex,
                        $this->config->readyTimeoutMs,
                    ),
                );

                return false;
            }

            return true;
        }

        // The worker the last replacement took over from is still finishing. Waiting for
        // it keeps the group at most one process over its count while it rolls.
        if ($this->reloadOutgoing !== null) {
            if (in_array($this->reloadOutgoing, $this->draining, true)) {
                return true;
            }

            $this->reloadOutgoing = null;
        }

        if ($this->reloadQueue === []) {
            $this->finishReload();

            return false;
        }

        $index = array_shift($this->reloadQueue);

        // A slot past the (possibly shrunk) worker count is retired rather than replaced —
        // that is how a smaller workerCount takes effect on a reload.
        if ($index >= $this->workers) {
            $process = $this->slots[$index] ?? null;

            unset($this->slots[$index], $this->respawnAtNs[$index]);

            if ($process !== null) {
                $this->reloadOutgoing = $this->drain(
                    index: $index,
                    process: $process,
                    reason: 'removed by the reload',
                );
            }

            return true;
        }

        try {
            $candidate = $this->launch($index);
        } catch (WorkerSpawnException $exception) {
            $this->abortReload(
                sprintf('the replacement for worker %d could not be started: %s', $index, $exception->getMessage()),
            );

            return false;
        }

        $this->reloadingIndex            = $index;
        $this->reloadCandidate           = $candidate;
        $this->reloadCandidateDeadlineNs = hrtime(true) + $this->config->readyTimeoutMs * 1_000_000;

        $this->logWorker(
            level: MasterLogger::INFO,
            pid: $candidate->pid(),
            index: $index,
            message: 'spawned as a replacement; waiting for it to be up',
        );

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

            $process->signal(ProcessSignalEnum::Terminate);

            $this->reportWatchdogEvent(
                event: WatchdogEventEnum::HeartbeatLost,
                index: $index,
                pid: $process->pid(),
                ageSeconds: $ageSeconds,
            );
        }
    }

    /** The reload's replacement said it is up: it takes the slot, and the worker there leaves. */
    protected function promoteCandidate(): void
    {
        $index     = $this->reloadingIndex;
        $candidate = $this->reloadCandidate;

        if ($candidate === null) {
            return;
        }

        $outgoing = $this->slots[$index] ?? null;

        $this->reloadCandidate = null;
        $this->reloadingIndex  = -1;

        unset($this->respawnAtNs[$index]);

        // The slot starts unjudged: the replacement inherits nothing the watchdog decided
        // about the worker before it.
        $this->forgetWatchdog($index);

        $this->slots[$index] = $candidate;

        $this->logWorker(
            level: MasterLogger::INFO,
            pid: $candidate->pid(),
            index: $index,
            message: 'up; took over the slot',
        );

        if ($outgoing !== null) {
            $this->reloadOutgoing = $this->drain(
                index: $index,
                process: $outgoing,
                reason: 'reloading',
            );
        }
    }

    /**
     * Gives the reload up. The replacement being waited on is stopped, the slots not
     * reached keep the workers they have, and the settings in force before the reload are
     * put back, so a worker that crashes later is respawned on what was running rather
     * than on what failed to come up. The slots already rolled keep their new workers:
     * those came up.
     */
    protected function abortReload(string $reason): void
    {
        if ($this->reloadCandidate !== null) {
            $this->drain(
                index: $this->reloadingIndex,
                process: $this->reloadCandidate,
                reason: 'the reload was given up',
            );
        }

        if ($this->configBeforeReload !== null) {
            $this->config  = $this->configBeforeReload;
            $this->workers = $this->config->workerCount > 0 ? $this->config->workerCount : Cpu::count();
        }

        $this->reloading          = false;
        $this->reloadQueue        = [];
        $this->reloadingIndex     = -1;
        $this->reloadCandidate    = null;
        $this->reloadOutgoing     = null;
        $this->configBeforeReload = null;
        $this->reloadFailure      = sprintf('group %s: %s', $this->config->name, $reason);

        $this->logger->master(
            level: MasterLogger::ERROR,
            message: sprintf(
                'group %s: reload given up: %s; the workers it had not reached keep running',
                $this->config->name,
                $reason,
            ),
        );

        // A slot the settings put back count but the group lacks is brought up on them.
        $this->fillMissingSlots();
    }

    protected function finishReload(): void
    {
        $this->reloading          = false;
        $this->configBeforeReload = null;

        $this->fillMissingSlots();

        $this->logger->master(
            level: MasterLogger::INFO,
            message: sprintf('group %s: reload complete', $this->config->name),
        );
    }

    /**
     * Logs the reload's replacement and, when it died before it was up, gives the reload
     * up. Reaped here rather than left to the slots' loop: it holds no slot until it is up.
     */
    protected function reapCandidate(bool $stopping): void
    {
        $candidate = $this->reloadCandidate;

        if ($candidate === null) {
            return;
        }

        $this->logWorkerLines(
            index: $this->reloadingIndex,
            process: $candidate,
            lines: $candidate->drainOutput(),
        );

        $candidate->drainHeartbeat();

        if ($candidate->isRunning()) {
            return;
        }

        $this->logWorkerLines(
            index: $this->reloadingIndex,
            process: $candidate,
            lines: $candidate->drainFinalOutput(),
        );

        $reason = $this->exitReason($candidate);

        $this->logWorker(
            level: $stopping ? MasterLogger::INFO : MasterLogger::ERROR,
            pid: $candidate->pid(),
            index: $this->reloadingIndex,
            message: sprintf('exited %s uptime=%.1fs before it was up', $reason, $candidate->uptimeSeconds()),
        );

        $candidate->close();

        $this->reloadCandidate = null;

        if ($stopping) {
            $this->reloadingIndex = -1;

            return;
        }

        $this->abortReload(
            sprintf('the replacement for worker %d exited %s before it was up', $this->reloadingIndex, $reason),
        );
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
                    $this->exitReason($process),
                    $process->uptimeSeconds(),
                    $draining->reason,
                ),
            );

            $process->close();
        }

        $this->draining = $remaining;
    }

    /**
     * Takes a worker out of its slot to finish outside it, and asks it to stop unless it
     * is stopping already (one that reached its own limit). Its deadline is the group's
     * shutdownTimeoutMs, past which driveDraining() kills it.
     */
    protected function drain(int $index, WorkerProcess $process, string $reason, bool $terminate = true): DrainingWorker
    {
        $draining = new DrainingWorker(
            process: $process,
            index: $index,
            deadlineNs: hrtime(true) + $this->config->shutdownTimeoutMs * 1_000_000,
            reason: $reason,
        );

        $this->draining[] = $draining;

        if ($terminate) {
            $this->logWorker(
                level: MasterLogger::INFO,
                pid: $process->pid(),
                index: $index,
                message: sprintf('%s; sending SIGTERM', $reason),
            );

            $process->signal(ProcessSignalEnum::Terminate);
        }

        return $draining;
    }

    /**
     * A worker said it is leaving because it reached its own limit: its replacement is
     * started now, while it is still finishing its last requests, so the slot is not
     * empty for the length of a drain and a start. Under Never nothing replaces it, and
     * it stays in its slot to be reaped there. The slot a reload is bringing a
     * replacement up for is left to that replacement.
     */
    protected function recycle(int $index, WorkerProcess $process): void
    {
        if ($this->retiring || !$this->config->restartPolicy->shouldRestart(cleanExit: true, reachedLimit: true)) {
            return;
        }

        $this->slots[$index] = null;

        $this->forgetWatchdog($index);

        $this->drain(
            index: $index,
            process: $process,
            reason: 'reached its limit',
            terminate: false,
        );

        $this->logWorker(
            level: MasterLogger::INFO,
            pid: $process->pid(),
            index: $index,
            message: 'reached its limit; starting its replacement',
        );

        if ($index !== $this->reloadingIndex && $index < $this->workers) {
            $this->spawn($index);
        }
    }

    /**
     * Notes that the group came up: a worker said it was up, or kept running for longer
     * than readyTimeoutMs — the second for a worker script that never says so, which is
     * then judged the way it was before readiness existed.
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

    protected function exitReason(WorkerProcess $process): string
    {
        return $process->termSignal() !== null
            ? sprintf('signal=%d', $process->termSignal())
            : sprintf('code=%d', (int) $process->exitCode());
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

    /**
     * Hands the event to the master's handler, if it has one. Anything the handler throws
     * is written to the journal and dropped: an application's alerting must not be able to
     * stop the supervisor from supervising.
     */
    protected function reportWatchdogEvent(
        WatchdogEventEnum $event,
        int $index,
        int $pid,
        ?float $ageSeconds = null,
    ): void {
        if ($this->onWatchdogEvent === null) {
            return;
        }

        try {
            ($this->onWatchdogEvent)(new WatchdogEvent(
                event: $event,
                group: $this->config->name,
                slot: $index,
                pid: $pid,
                ageSeconds: $ageSeconds,
                watchdogTimeoutMs: $this->config->watchdogTimeoutMs,
            ));
        } catch (Throwable $exception) {
            $this->logWorker(
                level: MasterLogger::ERROR,
                pid: $pid,
                index: $index,
                message: sprintf('watchdog handler failed: %s', $exception->getMessage()),
            );
        }
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

    protected function spawn(int $index, string $message = 'spawned'): void
    {
        if ($this->retiring) {
            return;
        }

        // The slot starts unjudged: the new worker inherits nothing the watchdog decided
        // about the one before it.
        $this->forgetWatchdog($index);

        try {
            $process = $this->launch($index);
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
            message: $message,
        );
    }

    /** Starts the worker of a slot, the way its group is launched. */
    protected function launch(int $index): WorkerProcess
    {
        return match ($this->config->launch) {
            LaunchMode::Exec => new ExecWorkerProcess(
                command: $this->buildCommand(),
                cwd: $this->cwd,
                env: $this->buildEnv($index),
                keepDescriptors: $this->keepDescriptors(),
            ),
            LaunchMode::Fork => $this->fork($index),
        };
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
        $reachedLimit  = $process->isRecycling();
        $reason        = $this->exitReason($process);

        // Died before it was up, and before it had run long enough to count as up without
        // saying so: a failure to start, which is what neverCameUp() is counting.
        if (
            !$stopping
            && !$this->retiring
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

        if ($stopping || $this->retiring) {
            $this->logWorker(
                level: MasterLogger::INFO,
                pid: $pid,
                index: $index,
                message: sprintf('exited %s uptime=%.1fs (draining)', $reason, $uptimeSeconds),
            );

            return;
        }

        $shouldRestart = $this->config->restartPolicy->shouldRestart(
            cleanExit: $exitedCleanly,
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

        $backoffMs = $this->config->restartBackoffMs * (2 ** ($fails - 1));

        return (int) min($backoffMs, $this->config->maxRestartBackoffMs);
    }

    /**
     * Forks this process — the group's fork server — into the worker of a slot. The child half
     * never returns: it becomes the worker in ForkedWorker::run() and leaves from there.
     * What it needs is taken here, before the fork, so the child reads plain values rather
     * than this group.
     */
    protected function fork(int $index): ForkedWorkerProcess
    {
        $title       = sprintf('sconcur-server: %s #%d', $this->config->name, $index);
        $cwd         = $this->cwd;
        $environment = $this->workerEnvironment($index);
        $argv        = $this->workerArgv();

        return new ForkedWorkerProcess(
            // A forked worker is the fork server's copy, the application its bootstrap loaded
            // included, and keeps what that application had open — a log file a logger
            // opened while loading would otherwise write to /dev/null in every worker. An
            // exec'd worker is a php of its own and loads its own.
            keepDescriptors: [...$this->keepDescriptors(), ...$this->inheritedDescriptors],
            child: static function (ForkResult $fork) use ($title, $cwd, $environment, $argv): never {
                ForkedWorker::run(
                    fork: $fork,
                    title: $title,
                    cwd: $cwd,
                    environment: $environment,
                    argv: $argv,
                );
            },
        );
    }

    /**
     * Builds the worker command for a group launched by exec: the interpreter, its flags
     * and the worker's argv (workerArgv).
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
            ...$this->workerArgv(),
        ];
    }

    /**
     * The worker's argv, whichever way it is launched: the script, the expanded `server`
     * flags, the group's workerArgs, and the master's pid as the `--masterPid` flag — the
     * same channel as the rest, no environment involved — for the worker to use as it
     * sees fit. A group with a listening socket of the master's adds `--listenFd`, the
     * number the socket has in the worker.
     *
     * @return list<string>
     */
    protected function workerArgv(): array
    {
        $argv = [
            $this->config->workerScript,
            ...$this->config->argumentFlags(),
            ...$this->config->workerArgs,
            WorkerMaster::MASTER_PID_ARG . '=' . $this->masterPid,
        ];

        if ($this->listenFd !== null) {
            $argv[] = self::LISTEN_FD_ARG . '=' . $this->listenFd;
        }

        return $argv;
    }

    /**
     * The descriptors a worker of this group keeps from the master: its listening socket.
     *
     * @return list<int>
     */
    protected function keepDescriptors(): array
    {
        return $this->listenFd === null ? [] : [$this->listenFd];
    }

    /**
     * The environment of a worker launched by exec: the inherited one, the marks pipe's
     * descriptor, then workerEnvironment() over both. No master metadata is injected here
     * — that goes via argv (see workerArgv).
     *
     * @return array<string, string>
     */
    protected function buildEnv(int $index): array
    {
        $env = getenv();

        // Tells the worker which descriptor its master opened for the pipe it marks itself
        // on. Always there: the watchdog reads it when it is on, and a reload reads it for
        // the mark that says a replacement is up. A forked worker is handed the pipe itself
        // and needs no such variable.
        $env[Heartbeat::FD_ENVIRONMENT_NAME] = (string) Heartbeat::FD;

        foreach ($this->workerEnvironment($index) as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }

    /**
     * What a worker's environment adds to the master's, whichever way it is launched: the
     * pool label, then the group's own env over it.
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
