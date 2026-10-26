<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Connection\Extension;
use SConcur\Exceptions\ExtensionNotLoadedException;
use SConcur\Exceptions\Worker\InvalidWorkerCountException;
use SConcur\Exceptions\Worker\RuntimePathException;
use SConcur\Process\ProcessControl;
use SConcur\Process\ProcessSignalEnum;
use SConcur\Telemetry\TelemetryRuntime;
use Throwable;

/**
 * Supervises pools of worker processes (one per slot). An exec group's workers are php
 * processes of their own the master starts via proc_open; a fork group's are forks of the
 * master itself, so whatever the process loaded before it started the master — an
 * application — is in every worker's memory and shared with it. The master is
 * server-agnostic — it supervises any worker script; with an SO_REUSEPORT-based server,
 * for example, the workers bind one port and the kernel load-balances connections across
 * them.
 *
 * Lifecycle of run(): acquire a single-instance lock, write the state file, start
 * watching for signals, spawn the workers, then loop — draining each worker's output
 * into the log, restarting exited workers per RestartPolicy (with crash-loop backoff),
 * and on SIGTERM/SIGINT forwarding the signal, draining in-flight workers within
 * shutdownTimeoutMs (SIGKILL stragglers), and exiting cleanly.
 *
 * The master loads the sconcur extension and takes its signals and signals its
 * workers through it (SConcur\Process\ProcessControl), so it needs neither ext-pcntl
 * nor ext-posix. It never starts the extension's runtime: a process running it could
 * not fork. See docs/worker-master.md.
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

    /**
     * What run() returns when a group the master started with never came up: as many of
     * its workers as it has slots died before any of them said it was up. EX_SOFTWARE from
     * sysexits.h, the code Rapira uses for the same case, so systemd or an orchestrator
     * sees a broken deploy rather than a master respawning into it forever.
     */
    public const int EXIT_NEVER_CAME_UP = 70;

    protected const int TICK_MICROSECONDS = 100_000; // 100 ms supervision tick

    protected const float SIGKILL_GRACE_SECONDS = 2.0; // give up waiting this long after SIGKILL

    protected MasterLogger $logger;

    protected MasterLock $lock;

    protected MasterStateFile $stateFile;

    protected int $masterPid = 0;

    protected float $startedAt = 0.0;

    protected string $cwd = '.';

    /** @var array<string, WorkerGroup> live pools by group name */
    protected array $pools = [];

    /**
     * @var list<int> the descriptors the process had open when run() was entered — the
     *                application's — which a forked worker keeps; everything the master
     *                opens after that is pointed at /dev/null in it
     */
    protected array $inheritedDescriptors = [];

    protected bool $stopping = false;

    /** What run() returns: 0, or EXIT_NEVER_CAME_UP once a group has failed to start. */
    protected int $exitCode = 0;

    protected bool $termSent = false;

    protected bool $killSent = false;

    protected int $stopDeadlineNs = 0;

    protected int $killDeadlineNs = 0;

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
        protected readonly ?Closure $onWatchdogEvent = null,
    ) {
    }

    /**
     * Runs the supervisor until a shutdown signal drains all workers. Returns the
     * process exit code (0 on clean shutdown).
     *
     * The process may have loaded an application before calling this, and a fork group's
     * workers start with it in memory. Its error handler is kept out of the master's loop
     * for as long as this runs: a handler that turns warnings into exceptions, as
     * frameworks install, would end the master at the first warning it silences, and the
     * master silences one on every tick. Every forked worker runs under it again
     * (ForkedWorker::prepare).
     */
    public function run(): int
    {
        // Returning false hands every error on to PHP's own handling, which respects @ and
        // error_reporting — the master behaves as if nothing had been installed.
        set_error_handler(static fn(): bool => false);

        try {
            return $this->runSupervisor();
        } finally {
            restore_error_handler();
        }
    }

    protected function runSupervisor(): int
    {
        $this->assertExtension();

        // First, before the master opens anything of its own: what is open now is the
        // application's, and a forked worker keeps it.
        $this->inheritedDescriptors = static::applicationDescriptors();

        $this->ensureDirectories();

        // After the config is checked, so a mistake in it is what is reported first.
        $this->assertForkable();

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

            $this->logger->master(
                level: MasterLogger::INFO,
                message: 'stopped',
            );

            $this->logger->close();
        }

        return $this->exitCode;
    }

    /**
     * The master takes its signals and signals its workers through the extension, so it
     * has to be loaded here too, not only in the workers. Extension::get() then checks
     * that it is the version this package expects.
     */
    protected function assertExtension(): void
    {
        if (!extension_loaded('sconcur')) {
            throw new ExtensionNotLoadedException(
                message: 'The worker master needs the "sconcur" extension loaded: start it as'
                    . ' php -d extension=/path/to/sconcur.so bin/sconcur-server start ..., or enable the'
                    . ' extension in php.ini.',
            );
        }

        Extension::get();
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
                telemetrySocket: $this->telemetrySocket(),
                onWatchdogEvent: $this->watchdogReporter(),
                inheritedDescriptors: $this->inheritedDescriptors,
                accessLogPrefix: MasterLogger::accessLogPrefix(
                    logDir: $this->logDir ?? $this->runtimeDir,
                    name: $this->name,
                ),
            );
        }
    }

    /**
     * Refuses up front, with the reason, a fork group in a process that cannot fork: one
     * that already runs the extension's runtime, or threads of another extension (an APM
     * agent started when php did). Every respawn is a fork from here, so the refusal would
     * otherwise come with the first worker.
     */
    protected function assertForkable(): void
    {
        foreach ($this->groups as $group) {
            if ($group->launch === LaunchMode::Fork) {
                ProcessControl::assertForkable();

                return;
            }
        }
    }

    /**
     * The descriptors this process has open that the application opened. Left out: the
     * ones on /dev/null and the script PHP runs — neither is the application's, and a
     * worker that kept them would hold them for nothing. They are pointed at /dev/null in
     * the worker like everything else it does not keep.
     *
     * @return list<int>
     */
    protected static function applicationDescriptors(): array
    {
        $notTheApplications = ['/dev/null', (string) realpath(get_included_files()[0])];

        $descriptors = [];

        foreach (ProcessControl::openDescriptors() as $descriptor) {
            if (in_array(@readlink('/proc/self/fd/' . $descriptor), $notTheApplications, true)) {
                continue;
            }

            $descriptors[] = $descriptor;
        }

        return $descriptors;
    }

    /**
     * What the pools call when the watchdog acts: counts the kill for the panel and then
     * hands the event on to the application's own handler, if it gave one. Anything that
     * handler throws is written to the journal and dropped here: an application's alerting
     * must not be able to stop the supervisor from supervising.
     *
     * @return Closure(WatchdogEvent): void
     */
    protected function watchdogReporter(): Closure
    {
        return function (WatchdogEvent $event): void {
            if ($event->event === WatchdogEventEnum::HeartbeatLost) {
                $this->telemetry?->recordWatchdogKill($event->group);
            }

            if ($this->onWatchdogEvent === null) {
                return;
            }

            try {
                ($this->onWatchdogEvent)($event);
            } catch (Throwable $exception) {
                $this->logger->worker(
                    level: MasterLogger::ERROR,
                    workerPid: $event->pid,
                    workerIndex: $event->slot,
                    message: sprintf('watchdog handler failed: %s', $exception->getMessage()),
                    group: $event->group,
                );
            }
        };
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

        static::assertWorkerScripts($this->groups);

        $directories = array_unique([$this->runtimeDir, $this->logDir ?? $this->runtimeDir]);

        foreach ($directories as $directory) {
            $this->ensureWritableDir($directory);
        }
    }

    /**
     * Refuses a set of groups naming a script that is not there, before any worker is
     * started: a typo is a refused start rather than a pool in a crash loop.
     *
     * @param list<WorkerGroupConfig> $groups
     */
    protected static function assertWorkerScripts(array $groups): void
    {
        foreach ($groups as $group) {
            if (!is_file($group->workerScript)) {
                throw new RuntimePathException(
                    message: sprintf('Worker script not found for group "%s": %s', $group->name, $group->workerScript),
                );
            }
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
     * Starts watching SIGTERM/SIGINT and returns a restorer that puts the previous
     * handlers back. What arrived is taken once per tick (checkStopSignals): no PHP code
     * runs inside a signal handler, and a tick is the soonest the loop could act on one
     * anyway.
     *
     * @return Closure(): void
     */
    protected function installSignalHandlers(): Closure
    {
        ProcessControl::watchSignals([
            ProcessSignalEnum::Terminate,
            ProcessSignalEnum::Interrupt,
        ]);

        return static function (): void {
            ProcessControl::restoreSignals();
        };
    }

    /** Turns a SIGTERM or SIGINT that arrived since the last tick into a graceful stop. */
    protected function checkStopSignals(): void
    {
        if (ProcessControl::takeSignals() !== []) {
            $this->stopping = true;
        }
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
            $this->logger->master(
                level: MasterLogger::WARN,
                message: 'state file removed; shutting down gracefully',
            );

            $this->stopping = true;
        }
    }

    protected function supervise(): void
    {
        while (true) {
            $nowNs = hrtime(true);

            foreach ($this->pools as $pool) {
                $pool->reapAndLog($this->stopping);
            }

            $this->checkStopSignals();
            $this->checkStateFileStopSignal();

            if ($this->stopping) {
                $this->driveShutdown($nowNs);

                if ($this->allSlotsEmpty()) {
                    break;
                }

                // A worker that survives even SIGKILL (e.g. stuck in uninterruptible
                // I/O) must not hang the master forever: after a grace period give up
                // and exit — the kernel reaps the leftover children once we are gone.
                if ($this->killSent && $nowNs > $this->killDeadlineNs) {
                    $this->logger->master(
                        level: MasterLogger::ERROR,
                        message: sprintf('%d worker(s) still alive after SIGKILL; exiting anyway', $this->aliveSlotCount()),
                    );

                    break;
                }
            } else {
                foreach ($this->pools as $pool) {
                    $pool->tick();
                }

                // Before the check below: a group whose workers all failed to start, under
                // a policy that does not restart them, is a failed start too, not a pool
                // that finished its work. The drain begins on the next tick.
                $this->checkNeverCameUp();

                // RestartPolicy::Never (or a clean exit under OnFailure): once every
                // worker has finished and nothing is pending, there is nothing left
                // to supervise.
                if (!$this->stopping && $this->allSlotsEmpty() && !$this->hasPendingRespawns()) {
                    $this->logger->master(
                        level: MasterLogger::INFO,
                        message: 'all workers finished; exiting',
                    );

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

    /** Stops the master, with EXIT_NEVER_CAME_UP, when a group it started with never came up. */
    protected function checkNeverCameUp(): void
    {
        foreach ($this->pools as $pool) {
            if (!$pool->neverCameUp()) {
                continue;
            }

            $this->logger->master(
                level: MasterLogger::ERROR,
                message: sprintf(
                    'group %s: no worker came up — %s; stopping with code %d',
                    $pool->name(),
                    $pool->neverCameUpReason(),
                    self::EXIT_NEVER_CAME_UP,
                ),
            );

            $this->stopping = true;
            $this->exitCode = self::EXIT_NEVER_CAME_UP;

            return;
        }
    }

    /**
     * The collector socket, or an empty string when telemetry is off (no panel port, no
     * token) — workers pointed at a socket nobody listens on would dial it every interval.
     */
    protected function telemetrySocket(): string
    {
        if ($this->panelPort <= 0 || $this->adminToken === '') {
            return '';
        }

        return $this->runtimeDir . '/' . $this->name . '.telemetry.sock';
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
            socketPath: $this->telemetrySocket(),
            panelPort: $this->panelPort,
            adminToken: $this->adminToken,
            name: $this->name,
            masterStartedAtMs: (int) ($this->startedAt * 1000),
            logError: static function (string $message) use ($logger): void {
                $logger->master(
                    level: MasterLogger::ERROR,
                    message: $message,
                );
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
    protected function driveShutdown(int $nowNs): void
    {
        if (!$this->termSent) {
            $this->termSent       = true;
            $this->stopDeadlineNs = $nowNs + $this->maxShutdownTimeoutMs() * 1_000_000;

            $this->logger->master(
                level: MasterLogger::INFO,
                message: 'shutdown requested; forwarding SIGTERM to workers',
            );

            $this->signalAll(ProcessSignalEnum::Terminate);

            return;
        }

        if (!$this->killSent && $nowNs > $this->stopDeadlineNs) {
            if (!$this->allSlotsEmpty()) {
                $this->logger->master(
                    level: MasterLogger::WARN,
                    message: sprintf('shutdown timeout; sending SIGKILL to %d worker(s)', $this->aliveSlotCount()),
                );

                $this->signalAll(ProcessSignalEnum::Kill);
            }

            $this->killSent       = true;
            $this->killDeadlineNs = $nowNs + (int) (self::SIGKILL_GRACE_SECONDS * 1_000_000_000);
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

    protected function signalAll(ProcessSignalEnum $signal): void
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
