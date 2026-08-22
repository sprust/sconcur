<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Exceptions\Worker\InvalidConfigException;
use SConcur\Exceptions\Worker\InvalidWorkerCountException;
use SConcur\Exceptions\Worker\MasterAlreadyRunningException;
use SConcur\Exceptions\Worker\MissingPcntlException;
use SConcur\Exceptions\Worker\RuntimePathException;
use SConcur\Telemetry\TelemetryRuntime;

/**
 * Supervises a pool of worker processes (one per slot), each a separate `php
 * workerScript` process spawned via proc_open (pcntl_fork after loading the
 * extension is forbidden). The master is server-agnostic — it supervises any worker
 * script; with an SO_REUSEPORT-based server, for example, the workers bind one port
 * and the kernel load-balances connections across them.
 *
 * Lifecycle of run(): acquire a single-instance lock, write the state file, install
 * signal handlers, spawn the workers, then loop — draining each worker's output into
 * the log, restarting exited workers per RestartPolicy (with crash-loop backoff),
 * and on SIGTERM/SIGINT forwarding the signal, draining in-flight workers within
 * shutdownTimeoutMs (SIGKILL stragglers), and exiting cleanly.
 *
 * The master itself does NOT load the sconcur extension — it is a plain supervisor.
 * See docs/worker-master.md.
 */
class WorkerMaster
{
    /**
     * The master injects its pid as this worker argv flag (alongside the expanded
     * `server` flags) so a worker can self-terminate when orphaned. It is just another
     * `--key=value` argv entry; how — or whether — a worker uses it is up to the
     * worker script (the bundled HttpServer wires it into its orphan check).
     */
    public const string MASTER_PID_ARG = '--masterPid';

    protected const int TICK_MICROSECONDS = 100_000; // 100 ms supervision tick

    protected const float SIGKILL_GRACE_SECONDS = 2.0; // give up waiting this long after SIGKILL

    protected MasterLogger $logger;

    protected MasterLock $lock;

    protected MasterStateFile $stateFile;

    protected MasterReloadFile $reloadFile;

    protected int $masterPid = 0;

    protected float $startedAt = 0.0;

    protected string $cwd = '.';

    /** @var array<string, WorkerGroup> live pools by group name */
    protected array $pools = [];

    protected bool $stopping = false;

    protected bool $termSent = false;

    protected bool $killSent = false;

    protected float $stopDeadline = 0.0;

    protected float $killDeadline = 0.0;

    protected ?TelemetryRuntime $telemetry = null;

    /**
     * @param string                  $runtimeDir holds the lock and state file (local fs)
     * @param null|string             $logDir     log directory (defaults to runtimeDir)
     * @param string                  $name       prefix for the log and state file names
     * @param int                     $rotateDays keep this many days of daily log files
     * @param list<WorkerGroupConfig> $groups     the pools to supervise
     * @param LogTarget               $logTo      where the master writes its journal (file/stdout/both)
     * @param int                     $panelPort  port for the embedded telemetry panel (0 = telemetry off). With a
     *                                            token set, the master collects worker snapshots over a unix socket
     *                                            and serves GET /api/stats and the live panel on this port.
     * @param string                  $adminToken Bearer token gating the telemetry panel; required (with panelPort)
     *                                            to enable telemetry. Empty = off.
     */
    public function __construct(
        protected readonly string $runtimeDir,
        protected readonly ?string $logDir = null,
        protected readonly string $name = 'sconcur-server',
        protected readonly int $rotateDays = 3,
        protected array $groups = [],
        protected readonly LogTarget $logTo = LogTarget::File,
        protected readonly int $panelPort = 0,
        protected readonly string $adminToken = '',
    ) {
    }

    /**
     * Runs the supervisor until a shutdown signal drains all workers. Returns the
     * process exit code (0 on clean shutdown).
     *
     * @throws MissingPcntlException        ext-pcntl/ext-posix missing
     * @throws InvalidWorkerCountException  workerCount is negative
     * @throws RuntimePathException         a required path is missing/not writable
     * @throws MasterAlreadyRunningException another master holds the lock
     */
    public function run(): int
    {
        $this->assertPcntl();
        $this->ensureDirectories();

        $this->masterPid = (int) getmypid();
        $this->startedAt = microtime(true);
        $this->cwd       = getcwd() ?: '.';

        $logDir = $this->logDir ?? $this->runtimeDir;

        $this->logger = new MasterLogger(
            logDir: $logDir,
            name: $this->name,
            rotateDays: $this->rotateDays,
            masterPid: $this->masterPid,
            logTo: $this->logTo,
        );

        $this->lock = new MasterLock(
            path: $this->runtimeDir . '/' . $this->name . '.lock',
        );

        $this->stateFile = new MasterStateFile(
            path: $this->runtimeDir . '/' . $this->name . '-state.json',
        );

        $this->reloadFile = new MasterReloadFile(
            path: $this->runtimeDir . '/' . $this->name . '.reload',
        );

        // Acquire the single-instance lock first: a second master fails fast here.
        $this->lock->acquire();

        // Everything after the lock is acquired runs under the finally so the lock
        // and signal handlers are always restored — even if writeState() throws.
        $restoreSignals = null;

        try {
            $restoreSignals = $this->installSignalHandlers();

            $this->buildPools();

            $this->logger->master(
                level: MasterLogger::INFO,
                message: sprintf(
                    'start groups=%d workers=%d runtimeDir=%s',
                    count($this->pools),
                    $this->totalWorkerCount(),
                    $this->runtimeDir,
                ),
            );

            $this->writeState();

            // Drop any stale trigger so a leftover file does not fire a spurious reload
            // of the workers we are about to spawn.
            $this->reloadFile->clear();

            // Bring up the telemetry plane before the workers so the collector socket
            // exists when they first try to push.
            $this->startTelemetry();

            foreach ($this->pools as $pool) {
                $pool->spawnAll();
            }

            $this->supervise();
        } finally {
            $this->telemetry?->stop();

            if ($restoreSignals !== null) {
                $restoreSignals();
            }

            $this->stateFile->clear();
            $this->lock->release();

            $this->logger->master(MasterLogger::INFO, 'stopped');
            $this->logger->close();
        }

        return 0;
    }

    protected function assertPcntl(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('posix_kill')) {
            throw new MissingPcntlException(
                message: 'WorkerMaster requires ext-pcntl and ext-posix for signal-driven supervision.',
            );
        }
    }

    /**
     * Builds one live pool per configured group. Ordered as the config lists them, so
     * the journal and `status` read the way the file does.
     */
    protected function buildPools(): void
    {
        $this->pools = [];

        foreach ($this->groups as $group) {
            $this->pools[$group->name] = new WorkerGroup(
                config: $group,
                logger: $this->logger,
                masterPid: $this->masterPid,
                cwd: $this->cwd,
            );
        }
    }

    protected function totalWorkerCount(): int
    {
        $total = 0;

        foreach ($this->pools as $pool) {
            $total += $pool->workerCount();
        }

        return $total;
    }

    protected function ensureDirectories(): void
    {
        if ($this->groups === []) {
            throw new InvalidWorkerCountException(
                message: 'WorkerMaster needs at least one group to supervise.',
            );
        }

        foreach ($this->groups as $group) {
            if (!is_file($group->workerScript)) {
                throw new RuntimePathException(
                    message: sprintf('Worker script not found for group "%s": %s', $group->name, $group->workerScript),
                );
            }
        }

        $directories = array_unique([$this->runtimeDir, $this->logDir ?? $this->runtimeDir]);

        foreach ($directories as $directory) {
            $this->ensureWritableDir($directory);
        }
    }

    protected function ensureWritableDir(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimePathException(
                message: 'Cannot create directory: ' . $directory,
            );
        }

        if (!is_writable($directory)) {
            throw new RuntimePathException(
                message: 'Directory is not writable: ' . $directory,
            );
        }
    }

    /**
     * Installs SIGTERM/SIGINT handlers that request a graceful stop and returns a
     * restorer that puts the previous handlers (and async-signals mode) back.
     *
     * @return Closure(): void
     */
    protected function installSignalHandlers(): Closure
    {
        $signals = [SIGTERM, SIGINT];

        $previousAsync = pcntl_async_signals();

        /** @var array<int, callable|int> $previousHandlers */
        $previousHandlers = [];

        foreach ($signals as $signal) {
            $previousHandlers[$signal] = pcntl_signal_get_handler($signal);
        }

        pcntl_async_signals(true);

        $handler = function (): void {
            $this->stopping = true;
        };

        foreach ($signals as $signal) {
            pcntl_signal($signal, $handler);
        }

        return static function () use ($signals, $previousHandlers, $previousAsync): void {
            foreach ($signals as $signal) {
                pcntl_signal($signal, $previousHandlers[$signal]);
            }

            pcntl_async_signals($previousAsync);
        };
    }

    protected function writeState(): void
    {
        $groups = [];

        foreach ($this->pools as $pool) {
            $groups[] = new MasterGroupState(
                name: $pool->name(),
                workerCount: $pool->workerCount(),
                workerScript: $pool->config()->workerScript,
            );
        }

        $written = $this->stateFile->write(
            new MasterState(
                pid: $this->masterPid,
                startedAt: $this->startedAt,
                workerCount: $this->totalWorkerCount(),
                groups: $groups,
            ),
        );

        // The state file doubles as the control file (its removal is the stop
        // signal), so a master with no state file would self-stop on the first
        // tick. Fail fast with a clear error instead of that misleading shutdown.
        if (!$written) {
            throw new RuntimePathException(
                message: 'Cannot write master state file: ' . $this->stateFile->path(),
            );
        }
    }

    /**
     * The state file doubles as the control file: removing it (via the `stop` command
     * or by hand) is the signal to shut the whole pool down gracefully. Detect its
     * disappearance and start the same drain as a SIGTERM. A wiped runtime dir (e.g. a
     * /tmp cleaner) therefore stops the master too — an external supervisor then
     * brings it back.
     */
    protected function checkStateFileStopSignal(): void
    {
        if ($this->stopping) {
            return;
        }

        if (!is_file($this->stateFile->path())) {
            $this->logger->master(MasterLogger::WARN, 'state file removed; shutting down gracefully');

            $this->stopping = true;
        }
    }

    protected function supervise(): void
    {
        while (true) {
            $now = microtime(true);

            foreach ($this->pools as $pool) {
                $pool->reapAndLog($this->stopping);
            }

            $this->checkStateFileStopSignal();

            if ($this->stopping) {
                $this->driveShutdown($now);

                if ($this->allSlotsEmpty()) {
                    break;
                }

                // A worker that survives even SIGKILL (e.g. stuck in uninterruptible
                // I/O) must not hang the master forever: after a grace period give up
                // and exit — the kernel reaps the leftover children once we are gone.
                if ($this->killSent && $now > $this->killDeadline) {
                    $this->logger->master(
                        level: MasterLogger::ERROR,
                        message: sprintf('%d worker(s) still alive after SIGKILL; exiting anyway', $this->aliveSlotCount()),
                    );

                    break;
                }
            } else {
                $this->checkReloadSignal();

                $reloading = false;

                foreach ($this->pools as $pool) {
                    $reloading = $pool->driveReload($now) || $reloading;

                    $pool->respawnDue($now);
                }

                $this->retireDrainedPools();

                $this->finishReload($reloading);

                // RestartPolicy::Never (or a clean exit under OnFailure): once every
                // worker has finished and nothing is pending, there is nothing left
                // to supervise. A reload in progress keeps the master alive even when a
                // slot is momentarily empty between the drain and its replacement.
                if (!$reloading && $this->allSlotsEmpty() && !$this->hasPendingRespawns()) {
                    $this->logger->master(MasterLogger::INFO, 'all workers finished; exiting');

                    break;
                }
            }

            // Flush the log once per tick (not per line) so STDOUT (docker logs) and
            // the file stay timely without a syscall per access-log line.
            $this->logger->flush();

            // Pace the loop. When telemetry is on, the tick is spent servicing its
            // sockets (select with the tick as timeout) instead of a blind sleep —
            // supervision keeps its cadence and is never blocked on telemetry I/O.
            if ($this->telemetry !== null) {
                $this->telemetry->poll(self::TICK_MICROSECONDS);
            } else {
                usleep(self::TICK_MICROSECONDS);
            }
        }
    }

    /**
     * Picks up a reload request and applies the config file afresh: a group may be
     * added, removed, resized or re-armed with new arguments, and every surviving group
     * rolls its workers onto the new settings one at a time.
     *
     * A config that does not parse is refused and the master keeps running on the one
     * it has. A typo must never take a working pool down.
     */
    protected function checkReloadSignal(): void
    {
        if (!$this->reloadFile->requested() || $this->anyPoolReloading()) {
            return;
        }

        $this->logger->master(MasterLogger::INFO, 'reload requested');

        $configPath = $this->reloadFile->configPath();

        if ($configPath !== '') {
            try {
                $this->groups = MasterConfig::fromFile($configPath)->toWorkerMaster()->groups;
            } catch (InvalidConfigException $exception) {
                $this->logger->master(
                    level: MasterLogger::ERROR,
                    message: 'reload refused, keeping the running config: ' . $exception->getMessage(),
                );

                $this->reloadFile->clear();

                return;
            }
        }

        $this->applyGroups();

        $this->writeState();
    }

    /**
     * Reconciles the live pools with the configured groups: new ones are spawned, gone
     * ones are drained, and the rest roll onto their (possibly unchanged) settings.
     */
    protected function applyGroups(): void
    {
        $configured = [];

        foreach ($this->groups as $group) {
            $configured[$group->name] = $group;

            $pool = $this->pools[$group->name] ?? null;

            if ($pool === null) {
                $this->logger->master(
                    level: MasterLogger::INFO,
                    message: sprintf('group %s: added by the reload', $group->name),
                );

                $pool = new WorkerGroup(
                    config: $group,
                    logger: $this->logger,
                    masterPid: $this->masterPid,
                    cwd: $this->cwd,
                );

                $this->pools[$group->name] = $pool;

                $pool->spawnAll();

                continue;
            }

            $pool->reconfigure($group);
        }

        foreach ($this->pools as $name => $pool) {
            if (!isset($configured[$name])) {
                $pool->retire();
            }
        }
    }

    /** Drops the pools that finished draining after being removed from the config. */
    protected function retireDrainedPools(): void
    {
        foreach ($this->pools as $name => $pool) {
            if ($pool->isRetiring() && $pool->allSlotsEmpty()) {
                unset($this->pools[$name]);

                $this->logger->master(
                    level: MasterLogger::INFO,
                    message: sprintf('group %s: drained and removed', $name),
                );
            }
        }
    }

    /**
     * Clears the trigger file once every pool has finished rolling, which is what the
     * waiting CLI is polling for.
     */
    protected function finishReload(bool $reloading): void
    {
        if ($reloading || !$this->reloadFile->requested()) {
            return;
        }

        $this->reloadFile->clear();

        $this->logger->master(MasterLogger::INFO, 'reload complete');
    }

    protected function anyPoolReloading(): bool
    {
        foreach ($this->pools as $pool) {
            if ($pool->isReloading()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Brings up the embedded telemetry plane (collector unix socket + HTTP/SSE panel)
     * when a panel port and an admin token are configured. A bind failure disables
     * telemetry (logged) but never stops the master.
     */
    protected function startTelemetry(): void
    {
        if ($this->panelPort <= 0 || $this->adminToken === '') {
            return;
        }

        $logger = $this->logger;

        $this->telemetry = new TelemetryRuntime(
            socketPath: $this->runtimeDir . '/' . $this->name . '.telemetry.sock',
            panelPort: $this->panelPort,
            adminToken: $this->adminToken,
            name: $this->name,
            masterStartedAtMs: (int) ($this->startedAt * 1000),
            logError: static function (string $message) use ($logger): void {
                $logger->master(MasterLogger::ERROR, $message);
            },
        );

        $this->telemetry->start();

        $this->logger->master(
            level: MasterLogger::INFO,
            message: sprintf('telemetry enabled (panel :%d)', $this->panelPort),
        );
    }

    /**
     * Drives the graceful stop: forward SIGTERM once and arm the deadline, then
     * SIGKILL any stragglers once the deadline passes. The deadline is the longest a
     * single group allows, so no group is cut short by a stricter neighbour.
     */
    protected function driveShutdown(float $now): void
    {
        if (!$this->termSent) {
            $this->termSent     = true;
            $this->stopDeadline = $now + $this->maxShutdownTimeoutMs() / 1000;

            $this->logger->master(MasterLogger::INFO, 'shutdown requested; forwarding SIGTERM to workers');

            $this->signalAll(SIGTERM);

            return;
        }

        if (!$this->killSent && $now > $this->stopDeadline) {
            $alive = $this->aliveSlotCount();

            if ($alive > 0) {
                $this->logger->master(
                    level: MasterLogger::WARN,
                    message: sprintf('shutdown timeout; sending SIGKILL to %d worker(s)', $alive),
                );

                $this->signalAll(SIGKILL);
            }

            $this->killSent     = true;
            $this->killDeadline = $now + self::SIGKILL_GRACE_SECONDS;
        }
    }

    protected function maxShutdownTimeoutMs(): int
    {
        $timeoutMs = 0;

        foreach ($this->pools as $pool) {
            $timeoutMs = max($timeoutMs, $pool->shutdownTimeoutMs());
        }

        return $timeoutMs;
    }

    protected function signalAll(int $signal): void
    {
        foreach ($this->pools as $pool) {
            $pool->signalAll($signal);
        }
    }

    protected function aliveSlotCount(): int
    {
        $alive = 0;

        foreach ($this->pools as $pool) {
            $alive += $pool->aliveSlotCount();
        }

        return $alive;
    }

    protected function allSlotsEmpty(): bool
    {
        foreach ($this->pools as $pool) {
            if (!$pool->allSlotsEmpty()) {
                return false;
            }
        }

        return true;
    }

    protected function hasPendingRespawns(): bool
    {
        foreach ($this->pools as $pool) {
            if ($pool->hasPendingRespawns()) {
                return true;
            }
        }

        return false;
    }
}
