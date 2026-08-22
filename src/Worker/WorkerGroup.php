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

    /** @var array<int, WorkerProcess|null> live worker per slot (null while awaiting respawn) */
    protected array $slots = [];

    /** @var array<int, float> slot index => unix time at which to respawn it */
    protected array $respawnAt = [];

    /** @var array<int, int> slot index => consecutive fast-fail count (drives backoff) */
    protected array $fastFails = [];

    protected int $workers = 0;

    protected bool $reloading = false;

    /** @var list<int> slot indices still to roll in the current rolling reload */
    protected array $reloadQueue = [];

    /** slot currently draining for reload (SIGTERM sent, awaiting exit), or -1 if none */
    protected int $reloadingIndex = -1;

    protected float $reloadDeadline = 0.0;

    protected bool $reloadKillSent = false;

    /** Set while the group is being retired: its workers are drained and not replaced. */
    protected bool $retiring = false;

    public function __construct(
        protected WorkerGroupConfig $config,
        protected MasterLogger $logger,
        protected int $masterPid,
        protected string $cwd,
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
        return $this->respawnAt !== [];
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

            $this->logWorkerLines($index, $process, $process->drainOutput());

            if (!$process->isRunning()) {
                $this->handleExit(index: $index, process: $process, stopping: $stopping);
            }
        }
    }

    public function respawnDue(float $now): void
    {
        foreach ($this->respawnAt as $index => $dueAt) {
            if ($dueAt <= $now && ($this->slots[$index] ?? null) === null) {
                unset($this->respawnAt[$index]);

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
     * Replaces the group's settings and rolls its workers onto them. Slot count changes
     * take effect at once: new slots come up immediately, and the ones that no longer
     * fit are drained by the roll and not replaced.
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

    /** Drains the group for good: its workers are asked to stop and never replaced. */
    public function retire(): void
    {
        if ($this->retiring) {
            return;
        }

        $this->retiring  = true;
        $this->respawnAt = [];

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
    public function driveReload(float $now): bool
    {
        if (!$this->reloading) {
            return false;
        }

        // A slot is mid-roll: wait for the worker to drain and exit (reapAndLog reaps it
        // into a null slot), escalating to SIGKILL once its drain deadline passes.
        if ($this->reloadingIndex !== -1) {
            $process = $this->slots[$this->reloadingIndex] ?? null;

            if ($process !== null) {
                if (!$this->reloadKillSent && $now > $this->reloadDeadline) {
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

            unset($this->respawnAt[$index]);

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
            unset($this->respawnAt[$index]);

            if ($index < $this->workers) {
                $this->spawn($index);
            } else {
                unset($this->slots[$index]);
            }

            return true;
        }

        $this->reloadingIndex = $index;
        $this->reloadKillSent = false;
        $this->reloadDeadline = $now + $this->config->shutdownTimeoutMs / 1000;

        $this->logWorker(MasterLogger::INFO, $process->pid(), $index, 'reloading; sending SIGTERM');

        $process->signal(SIGTERM);

        return true;
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

        try {
            $process = new WorkerProcess(
                command: $this->buildCommand(),
                cwd: $this->cwd,
                env: $this->buildEnv($index),
            );
        } catch (WorkerSpawnException $exception) {
            $backoffMs = $this->nextBackoffMs($index, uptimeSeconds: 0.0);

            $this->slots[$index]     = null;
            $this->respawnAt[$index] = microtime(true) + $backoffMs / 1000;

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

        unset($this->respawnAt[$index]);

        $this->logWorker(MasterLogger::INFO, $process->pid(), $index, 'spawned');
    }

    protected function handleExit(int $index, WorkerProcess $process, bool $stopping): void
    {
        $this->logWorkerLines($index, $process, $process->drainFinalOutput());

        $pid           = $process->pid();
        $uptimeSeconds = $process->uptimeSeconds();
        $exitedCleanly = $process->exitedCleanly();

        $reason = $process->termSignal() !== null
            ? sprintf('signal=%d', $process->termSignal())
            : sprintf('code=%d', (int) $process->exitCode());

        $process->close();

        $this->slots[$index] = null;

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

        $backoffMs = $this->nextBackoffMs($index, $uptimeSeconds);

        $this->respawnAt[$index] = microtime(true) + $backoffMs / 1000;

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
     */
    protected function nextBackoffMs(int $index, float $uptimeSeconds): int
    {
        if ($uptimeSeconds >= self::HEALTHY_UPTIME_SECONDS) {
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

        $env['SCONCUR_SERVER_NAME'] = $this->config->name . ':' . $index;

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
