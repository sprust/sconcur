<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Connection\Extension;
use SConcur\Exceptions\ExtensionNotLoadedException;
use SConcur\Exceptions\Process\ForkRefusedException;
use SConcur\Exceptions\Process\ProcessException;
use SConcur\Exceptions\Worker\InvalidConfigException;
use SConcur\Exceptions\Worker\InvalidWorkerCountException;
use SConcur\Exceptions\Worker\RuntimePathException;
use SConcur\Process\ProcessControl;
use SConcur\Process\ProcessSignalEnum;
use SConcur\Telemetry\TelemetryRuntime;

/**
 * Supervises a pool of worker processes (one per slot), each a separate `php
 * workerScript` process spawned via proc_open. The master is server-agnostic — it
 * supervises any worker script; with an SO_REUSEPORT-based server, for example, the
 * workers bind one port and the kernel load-balances connections across them.
 *
 * Lifecycle of run(): acquire a single-instance lock, write the state file, start
 * watching for signals, spawn the workers, then loop — draining each worker's output
 * into the log, restarting exited workers per RestartPolicy (with crash-loop backoff),
 * and on SIGTERM/SIGINT forwarding the signal, draining in-flight workers within
 * shutdownTimeoutMs (SIGKILL stragglers), and exiting cleanly.
 *
 * The master loads the sconcur extension and takes its signals and signals its
 * workers through it (SConcur\Process\ProcessControl), so it needs neither ext-pcntl
 * nor ext-posix. It never starts the extension's runtime. See docs/worker-master.md.
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

    protected MasterReloadFile $reloadFile;

    protected int $masterPid = 0;

    protected float $startedAt = 0.0;

    protected string $cwd = '.';

    /** @var array<string, WorkerGroup> live pools by group name */
    protected array $pools = [];

    /**
     * @var array<string, int> the listening socket of each group with `listen`, by group
     *                         name: bound once and held for as long as the group runs, so
     *                         the port stays open through every worker's restart and every
     *                         rolling reload
     */
    protected array $listeners = [];

    /**
     * @var list<int> the descriptors open when the master started: the application's that
     *                started it — a log file it opened while loading, a socket systemd
     *                passed — which forked workers keep, where every descriptor the master
     *                opens itself is shut out of them
     */
    protected array $inheritedDescriptors = [];

    protected bool $stopping = false;

    /** What run() returns: 0, or EXIT_NEVER_CAME_UP once a group has failed to start. */
    protected int $exitCode = 0;

    /**
     * The reload request currently being rolled, null when none is. Its signature is what
     * keeps the clear at the end of a roll from deleting a second request an operator
     * wrote while the first was still going.
     */
    protected ?MasterReloadRequest $servedReloadRequest = null;

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
     * The process may have loaded an application before calling this, for forked workers
     * to start from. Its error handler is kept out of the master's loop for as long as this
     * runs: a handler that turns warnings into exceptions, as frameworks install, would end
     * the master at the first warning it silences, and the master silences one on every
     * tick. A forked worker gets the handler back (ForkedWorker).
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

        // First, before the master opens anything of its own.
        $this->inheritedDescriptors = ProcessControl::openDescriptors();

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
     * Refuses to start groups launched by fork in a process that cannot fork, before
     * anything is written: the application that started the master made an asynchronous
     * call while loading, so the extension's runtime runs, or another extension started
     * threads. Refused at the first worker instead, the reason would come after the
     * journal had already said the pool started.
     */
    protected function assertForkable(): void
    {
        foreach ($this->groups as $group) {
            if ($group->launch !== LaunchMode::Fork) {
                continue;
            }

            try {
                ProcessControl::assertForkable();
            } catch (ForkRefusedException $exception) {
                throw new ForkRefusedException(
                    message: sprintf(
                        'group "%s" is launched by fork, and this process cannot fork: %s. Nothing may use'
                        . ' the extension before the master starts; open connections and make calls in'
                        . ' the worker script',
                        $group->name,
                        $exception->getMessage(),
                    ),
                    previous: $exception,
                );
            }

            return;
        }
    }

    /**
     * Builds one live pool per configured group. Ordered as the config lists them, so
     * the journal and `status` read the way the file does.
     */
    protected function buildPools(): void
    {
        $this->pools = [];

        $this->bindListeners($this->groups);

        foreach ($this->groups as $group) {
            $this->pools[$group->name] = $this->newPool(
                group: $group,
                guardsStartup: true,
            );
        }
    }

    /**
     * @param bool $guardsStartup whether the group failing to start stops the master — only
     *                            for the groups it starts with: one a reload adds must not
     *                            take a running pool down
     */
    protected function newPool(WorkerGroupConfig $group, bool $guardsStartup = false): WorkerGroup
    {
        return new WorkerGroup(
            config: $group,
            logger: $this->logger,
            masterPid: $this->masterPid,
            cwd: $this->cwd,
            telemetrySocket: $this->telemetrySocket(),
            onWatchdogEvent: $this->watchdogReporter(),
            listenFd: $this->listeners[$group->name] ?? null,
            inheritedDescriptors: $this->inheritedDescriptors,
            guardsStartup: $guardsStartup,
        );
    }

    /**
     * Binds the listening socket of every group with `listen` that has none yet. All or
     * nothing: a bind that fails closes the ones this call made before it and refuses the
     * lot, so neither a start nor a reload is left holding half its ports.
     *
     * @param list<WorkerGroupConfig> $groups
     */
    protected function bindListeners(array $groups): void
    {
        $bound = [];

        foreach ($groups as $group) {
            if ($group->listen === '' || isset($this->listeners[$group->name])) {
                continue;
            }

            try {
                $bound[$group->name] = ProcessControl::bind($group->listen);
            } catch (ProcessException $exception) {
                foreach ($bound as $descriptor) {
                    ProcessControl::close($descriptor);
                }

                throw new InvalidConfigException(
                    message: sprintf(
                        'config: group "%s" cannot listen on %s: %s',
                        $group->name,
                        $group->listen,
                        $exception->getMessage(),
                    ),
                    previous: $exception,
                );
            }
        }

        $this->listeners = [...$this->listeners, ...$bound];
    }

    /**
     * Refuses a reload that moves the listening socket of a group already running. The
     * workers serving now accept on the socket the master bound at the start; moving the
     * port means a socket the running workers do not have, and a gap while it changes
     * hands — a restart of the master is the honest way to do that.
     *
     * @param list<WorkerGroupConfig> $groups
     */
    protected function assertListenersUnchanged(array $groups): void
    {
        foreach ($groups as $group) {
            $pool = $this->pools[$group->name] ?? null;

            if ($pool === null || $pool->config()->listen === $group->listen) {
                continue;
            }

            throw new InvalidConfigException(
                message: sprintf(
                    'config: group "%s" listens on %s and the config says %s; a listening socket'
                    . ' does not move on a reload, restart the master to change it',
                    $group->name,
                    $pool->config()->listen === '' ? 'no socket of the master\'s' : $pool->config()->listen,
                    $group->listen === '' ? 'none' : $group->listen,
                ),
            );
        }
    }

    /**
     * What the pools call when the watchdog acts: counts the kill for the panel and then
     * hands the event on to the application's own handler, if it gave one.
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

            ($this->onWatchdogEvent)($event);
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
        static::assertLaunchable($this->groups);

        // After the config is known to be sound, so a config error is named as one, and
        // before anything is created.
        $this->assertForkable();

        $directories = array_unique([$this->runtimeDir, $this->logDir ?? $this->runtimeDir]);

        foreach ($directories as $directory) {
            $this->ensureWritableDir($directory);
        }
    }

    /**
     * Refuses a set of groups naming a script that is not there.
     *
     * Run on every reload as well as at startup: a config that parses but points at a
     * path that does not exist would otherwise roll every worker of the group — SIGTERM
     * to a healthy process, then `php /wrong/path` exiting 1 — and leave the pool in a
     * crash loop. A typo must never take a working pool down.
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

    /**
     * Refuses a fork group whose ini settings this master cannot honour. A forked worker
     * runs on the master's interpreter and applies the group's -d flags with ini_set, so
     * an extension has to be loaded here already, and a directive has to be one PHP lets a
     * script change. Checked here rather than with the rest of the config, because the
     * answer is this process's: the CLI that writes a reload request may well run without
     * the extensions the master has.
     *
     * Run on every reload as well as at startup, like assertWorkerScripts.
     *
     * @param list<WorkerGroupConfig> $groups
     */
    protected static function assertLaunchable(array $groups): void
    {
        $directives = ini_get_all(details: true);

        foreach ($groups as $group) {
            if ($group->launch !== LaunchMode::Fork) {
                continue;
            }

            foreach ($group->iniSettings() as [$directive, $value]) {
                if ($directive === 'extension' || $directive === 'zend_extension') {
                    if (!static::isLoaded(extension: $value)) {
                        throw new InvalidConfigException(
                            message: sprintf(
                                'config: group "%s" is launched by fork and asks for %s=%s, which the master'
                                . ' has not loaded; load it in the master, or use launch=exec',
                                $group->name,
                                $directive,
                                $value,
                            ),
                        );
                    }

                    continue;
                }

                // INI_USER is the bit that lets ini_set change a directive; INI_SYSTEM and
                // INI_PERDIR ones are fixed once the process has started.
                if ((($directives[$directive]['access'] ?? 0) & INI_USER) === 0) {
                    throw new InvalidConfigException(
                        message: sprintf(
                            'config: group "%s" is launched by fork and sets %s, which %s; set it for the'
                            . ' master, or use launch=exec',
                            $group->name,
                            $directive,
                            isset($directives[$directive])
                                ? 'cannot be changed once php has started'
                                : 'this php does not know',
                        ),
                    );
                }
            }
        }
    }

    /**
     * Whether the extension a `-d extension=` names is loaded: by its name or by the file
     * it was given as (`/path/to/sconcur.so`, `php_redis.dll`), and under the "Zend "
     * prefix a Zend extension's module carries (OPcache is "Zend OPcache").
     */
    protected static function isLoaded(string $extension): bool
    {
        $name = preg_replace('/^php_|\.(so|dll)$/', '', basename($extension)) ?? $extension;

        return extension_loaded($name) || extension_loaded('Zend ' . $name);
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
            $this->logger->master(MasterLogger::WARN, 'state file removed; shutting down gracefully');

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
                $this->checkReloadSignal();

                $reloading = false;

                foreach ($this->pools as $pool) {
                    $reloading = $pool->driveReload() || $reloading;

                    $pool->driveDraining();

                    $pool->driveWatchdog();

                    $pool->respawnDue();
                }

                $this->retireDrainedPools();

                $this->finishReload($reloading);

                // Before the check below: a group whose workers all failed to start, under
                // a policy that does not restart them, is a failed start too, not a pool
                // that finished its work. The drain begins on the next tick.
                $this->checkNeverCameUp();

                // RestartPolicy::Never (or a clean exit under OnFailure): once every
                // worker has finished and nothing is pending, there is nothing left
                // to supervise. A reload in progress keeps the master alive even when a
                // slot is momentarily empty between the drain and its replacement.
                if (!$this->stopping && !$reloading && $this->allSlotsEmpty() && !$this->hasPendingRespawns()) {
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
        // A request still being served is still in the trigger file. A roll given up while
        // its pool was reaped ends before finishReload() has cleared it, and reading it here
        // again would start the same failing roll once a tick, for ever.
        if ($this->servedReloadRequest !== null || $this->anyPoolReloading()) {
            return;
        }

        $request = $this->reloadFile->pending();

        if ($request === null) {
            return;
        }

        $this->logger->master(
            level: MasterLogger::INFO,
            message: $request->group === ''
                ? 'reload requested'
                : sprintf('reload requested for group %s', $request->group),
        );

        $groups = $this->reloadedGroups($request);

        if ($groups === null) {
            return;
        }

        if ($request->group !== '') {
            if (!$this->applyOneGroup(groups: $groups, request: $request)) {
                return;
            }
        } else {
            $this->groups = $groups;

            $this->applyGroups();
        }

        // Written down only once the reload was applied. A refused one ends its own
        // request (refuseReload) and leaves nothing behind — recording it here would have
        // finishReload log "reload complete" on the tick after "reload refused".
        $this->servedReloadRequest = $request;

        $this->refreshState();
    }

    /**
     * Ends a reload request the master will not honour: says why in the journal and to the
     * CLI that asked, and keeps the config it has.
     */
    protected function refuseReload(MasterReloadRequest $request, string $reason): void
    {
        $this->logger->master(
            level: MasterLogger::ERROR,
            message: $reason,
        );

        $this->reloadFile->fail(
            request: $request,
            reason: $reason,
        );
    }

    /**
     * The groups a reload asks the master to run, or null when the request cannot be
     * honoured — in which case the master keeps the config it has and says why.
     *
     * @return list<WorkerGroupConfig>|null
     */
    protected function reloadedGroups(MasterReloadRequest $request): ?array
    {
        if ($request->configPath === '') {
            // A trigger written by hand: roll the workers onto the config already loaded.
            return $this->groups;
        }

        if (!is_file($request->configPath)) {
            // The CLI resolves the path before writing it, so this is a trigger written
            // by something else — or a config that has since been moved. Saying so beats
            // rolling the workers onto the old config while reporting a reload.
            $this->refuseReload(
                request: $request,
                reason: sprintf('reload refused: no config file at %s', $request->configPath),
            );

            return null;
        }

        try {
            $groups = MasterConfig::fromFile($request->configPath)->groups();

            static::assertWorkerScripts($groups);
            static::assertLaunchable($groups);

            $this->assertListenersUnchanged($groups);

            // Last, so nothing is bound for a reload refused for another reason. A reload
            // narrowed to one group adds no group, so it binds nothing either.
            if ($request->group === '') {
                $this->bindListeners($groups);
            }

            return $groups;
        } catch (InvalidConfigException | RuntimePathException $exception) {
            $this->refuseReload(
                request: $request,
                reason: 'reload refused, keeping the running config: ' . $exception->getMessage(),
            );

            return null;
        }
    }

    /**
     * Rewrites the state file after a reload — unless it has been removed meanwhile,
     * which is how a stop is asked for. Writing it again there would swallow that stop:
     * the master checks for the file once a tick, and a fresh one looks like no stop was
     * ever requested.
     */
    protected function refreshState(): void
    {
        if (!is_file($this->stateFile->path())) {
            $this->logger->master(MasterLogger::WARN, 'state file removed; shutting down gracefully');

            $this->stopping = true;

            return;
        }

        $this->writeState();
    }

    /**
     * Applies the reload to a single named group and leaves the others alone — the
     * scoped form of a reload, so one pool can be rolled without touching its
     * neighbours. Answers false when the config no longer describes that group, which
     * is a request that cannot be honoured rather than an instruction to retire it.
     *
     * @param list<WorkerGroupConfig> $groups
     */
    protected function applyOneGroup(array $groups, MasterReloadRequest $request): bool
    {
        $name = $request->group;

        foreach ($groups as $group) {
            if ($group->name !== $name) {
                continue;
            }

            $pool = $this->pools[$name] ?? null;

            // Checked before anything is written down: a group the master is not running
            // is a request it cannot honour, and recording its settings anyway would let
            // the next plain reload bring up a pool nobody asked for.
            if ($pool === null) {
                $this->refuseReload(
                    request: $request,
                    reason: sprintf('reload --group=%s: the master is not running that group', $name),
                );

                return false;
            }

            // Replaced by name, not by position: the index is the one this group has in
            // the file just read, and the running list is ordered however it was when
            // the master started. Reordering the file and reloading one group would
            // otherwise overwrite a different group and drop it from the list, and the
            // next full reload would retire a pool that is still serving.
            $this->groups = $this->withGroupReplaced(
                groups: $this->groups,
                group: $group,
            );

            // The same put-back applyGroups() makes, and for the same reason: a retiring
            // pool spawns nothing, so reconfiguring it would leave every slot empty and
            // retireDrainedPools() would drop it a tick later. Naming the group is if
            // anything the clearer way to ask for it back, and without this the pool
            // could only be revived by a full reload.
            if ($pool->isRetiring()) {
                $this->logger->master(
                    level: MasterLogger::INFO,
                    message: sprintf('group %s: back in the config while draining; kept', $group->name),
                );

                $pool->unretire();
            }

            $pool->reconfigure($group);

            return true;
        }

        $this->refuseReload(
            request: $request,
            reason: sprintf('reload --group=%s: the config describes no such group', $name),
        );

        return false;
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

                $pool = $this->newPool($group);

                $this->pools[$group->name] = $pool;

                $pool->spawnAll();

                continue;
            }

            // A pool put back into the config while it was still draining out of it is
            // the same pool again, not a gap. Without this its slots stay empty — a
            // retiring pool spawns nothing — and retireDrainedPools() would drop it a
            // tick later, so the group would only come back on the reload after next.
            if ($pool->isRetiring()) {
                $this->logger->master(
                    level: MasterLogger::INFO,
                    message: sprintf('group %s: back in the config while draining; kept', $group->name),
                );

                $pool->unretire();
            }

            $pool->reconfigure($group);
        }

        foreach ($this->pools as $name => $pool) {
            if (!isset($configured[$name])) {
                $pool->retire();
            }
        }
    }

    /**
     * The running group list with one entry replaced by name, or the new group appended
     * when the master is not running it yet.
     *
     * @param list<WorkerGroupConfig> $groups
     *
     * @return list<WorkerGroupConfig>
     */
    protected function withGroupReplaced(array $groups, WorkerGroupConfig $group): array
    {
        $replaced = [];
        $found    = false;

        foreach ($groups as $running) {
            if ($running->name === $group->name) {
                $replaced[] = $group;
                $found      = true;

                continue;
            }

            $replaced[] = $running;
        }

        if (!$found) {
            $replaced[] = $group;
        }

        return $replaced;
    }

    /** Drops the pools that finished draining after being removed from the config. */
    protected function retireDrainedPools(): void
    {
        foreach ($this->pools as $name => $pool) {
            if ($pool->isRetiring() && $pool->allSlotsEmpty()) {
                unset($this->pools[$name]);

                // Its workers are gone, so nobody accepts on its socket any more: closed
                // here, the port is free for a group that may come back with it.
                if (isset($this->listeners[$name])) {
                    ProcessControl::close($this->listeners[$name]);

                    unset($this->listeners[$name]);
                }

                $this->logger->master(
                    level: MasterLogger::INFO,
                    message: sprintf('group %s: drained and removed', $name),
                );
            }
        }
    }

    /**
     * Ends the reload request once every pool has finished rolling, which is what the
     * waiting CLI is polling for. A pool that gave its roll up has put its previous
     * settings back, and the master's group list follows it, so the next reload — and a
     * hand-written trigger that rolls the config already loaded — starts from what is
     * running.
     */
    protected function finishReload(bool $reloading): void
    {
        if ($reloading || $this->servedReloadRequest === null) {
            return;
        }

        $request = $this->servedReloadRequest;

        $this->servedReloadRequest = null;

        $failures = [];

        foreach ($this->pools as $pool) {
            $failure = $pool->takeReloadFailure();

            if ($failure === '') {
                continue;
            }

            $failures[] = $failure;

            $this->groups = $this->withGroupReplaced(
                groups: $this->groups,
                group: $pool->config(),
            );
        }

        // Only the request that was actually rolled is cleared. An operator who asked for
        // a second reload while the first was still going would otherwise have it deleted
        // unread, and the change would never reach the workers.
        if ($failures === []) {
            $this->reloadFile->clear($request->signature);

            $this->logger->master(MasterLogger::INFO, 'reload complete');

            return;
        }

        $this->refreshState();

        $this->refuseReload(
            request: $request,
            reason: 'reload given up: ' . implode('; ', $failures),
        );
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
                    'group %s: no worker came up — %d failed before any said it was up; stopping with code %d',
                    $pool->name(),
                    $pool->workerCount(),
                    self::EXIT_NEVER_CAME_UP,
                ),
            );

            $this->stopping = true;
            $this->exitCode = self::EXIT_NEVER_CAME_UP;

            return;
        }
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
     * The collector socket, or an empty string when telemetry is off (no panel port, no
     * token) — workers pointed at a socket nobody listens on would dial it every interval.
     *
     * Derived here rather than baked into the group configs, so a group brought up by a
     * reload gets the socket of the master that is actually running instead of one built
     * from the freshly read file. Those differ the moment a config names another
     * runtimeDir or name, and the collector would then be listening on a path the new
     * workers never push to.
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
    protected function driveShutdown(int $nowNs): void
    {
        if (!$this->termSent) {
            $this->termSent       = true;
            $this->stopDeadlineNs = $nowNs + $this->maxShutdownTimeoutMs() * 1_000_000;

            $this->logger->master(MasterLogger::INFO, 'shutdown requested; forwarding SIGTERM to workers');

            $this->signalAll(ProcessSignalEnum::Terminate);

            return;
        }

        if (!$this->killSent && $nowNs > $this->stopDeadlineNs) {
            $alive = $this->aliveSlotCount();

            if ($alive > 0) {
                $this->logger->master(
                    level: MasterLogger::WARN,
                    message: sprintf('shutdown timeout; sending SIGKILL to %d worker(s)', $alive),
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
