<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Exceptions\Worker\InvalidWorkerCountException;
use SConcur\Exceptions\Worker\MasterAlreadyRunningException;
use SConcur\Exceptions\Worker\MissingPcntlException;
use SConcur\Exceptions\Worker\RuntimePathException;
use SConcur\Exceptions\Worker\WorkerSpawnException;
use SConcur\Features\HttpServer\HttpServer;

/**
 * Supervises a pool of worker processes (one per slot), each a separate `php
 * workerScript` process spawned via proc_open (pcntl_fork after loading the
 * extension is forbidden). Pairs with the HttpServer SO_REUSEPORT feature: the
 * workers bind one port and the kernel load-balances connections across them.
 *
 * Lifecycle of run(): acquire a single-instance lock, write the state file, install
 * signal handlers, spawn the workers, then loop — draining each worker's output into
 * the log, restarting exited workers per RestartPolicy (with crash-loop backoff),
 * and on SIGTERM/SIGINT forwarding the signal, draining in-flight workers within
 * shutdownTimeoutMs (SIGKILL stragglers), and exiting cleanly.
 *
 * The master itself does NOT load the sconcur extension — it is a plain supervisor.
 * See .ai/plans/worker-master.md.
 */
class WorkerMaster
{
    /**
     * The master injects its pid as this worker argv flag for the orphan check;
     * HttpServer::fromArgs() maps it onto the HttpServer `masterPid` constructor
     * parameter (so the `--` prefix and the name must match it).
     *
     * @see HttpServer::$masterPid
     */
    protected const string MASTER_PID_ARG = '--masterPid';

    protected const int TICK_MICROSECONDS = 100_000; // 100 ms supervision tick

    protected const float HEALTHY_UPTIME_SECONDS = 1.0; // shorter run counts as a fast fail

    protected const float SIGKILL_GRACE_SECONDS = 2.0; // give up waiting this long after SIGKILL

    protected MasterLogger $logger;

    protected MasterLock $lock;

    protected MasterStateFile $stateFile;

    protected int $masterPid = 0;

    protected string $cwd = '.';

    protected int $workers = 0;

    /** @var array<int, WorkerProcess|null> live worker per slot (null while awaiting respawn) */
    protected array $slots = [];

    /** @var array<int, float> slot index => unix time at which to respawn it */
    protected array $respawnAt = [];

    /** @var array<int, int> slot index => consecutive fast-fail count (drives backoff) */
    protected array $fastFails = [];

    protected bool $stopping = false;

    protected bool $termSent = false;

    protected bool $killSent = false;

    protected float $stopDeadline = 0.0;

    protected float $killDeadline = 0.0;

    /**
     * @param string                $workerScript        consumer's worker script (constructs HttpServer and serves)
     * @param string                $runtimeDir          holds the lock and state file (local fs)
     * @param null|string           $logDir              log directory (defaults to runtimeDir)
     * @param string                $name                prefix for the log and state file names
     * @param int                   $rotateDays          keep this many days of daily log files
     * @param int                   $workerCount         number of workers (0 = number of CPU cores)
     * @param string                $phpBinary           interpreter used to run the worker script
     * @param list<string>          $phpArgs             extra interpreter flags (e.g. -d extension=...)
     * @param list<string>          $workerArgs          argv passed to the worker script
     * @param array<string, string> $env                 extra env merged over the inherited environment
     * @param RestartPolicy         $restartPolicy       when to respawn an exited worker
     * @param int                   $shutdownTimeoutMs   how long to wait for workers to drain before SIGKILL
     * @param int                   $restartBackoffMs    base of the exponential crash-loop backoff
     * @param int                   $maxRestartBackoffMs cap of the crash-loop backoff
     * @param LogTarget             $logTo               where the master writes its journal (file/stdout/both)
     */
    public function __construct(
        protected readonly string $workerScript,
        protected readonly string $runtimeDir,
        protected readonly ?string $logDir = null,
        protected readonly string $name = 'sconcur-http-server',
        protected readonly int $rotateDays = 3,
        protected readonly int $workerCount = 0,
        protected readonly string $phpBinary = PHP_BINARY,
        protected readonly array $phpArgs = [],
        protected readonly array $workerArgs = [],
        protected readonly array $env = [],
        protected readonly RestartPolicy $restartPolicy = RestartPolicy::Always,
        protected readonly int $shutdownTimeoutMs = 10_000,
        protected readonly int $restartBackoffMs = 200,
        protected readonly int $maxRestartBackoffMs = 30_000,
        protected readonly LogTarget $logTo = LogTarget::File,
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
        $this->cwd       = getcwd() ?: '.';
        $this->workers   = $this->resolveWorkerCount();

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

            $this->logger->master(
                level: MasterLogger::INFO,
                message: sprintf(
                    'start workers=%d script=%s runtimeDir=%s',
                    $this->workers,
                    $this->workerScript,
                    $this->runtimeDir,
                ),
            );

            $this->writeState();

            $this->spawnAll();
            $this->supervise();
        } finally {
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

    protected function resolveWorkerCount(): int
    {
        if ($this->workerCount < 0) {
            throw new InvalidWorkerCountException(
                message: 'workerCount must be >= 0 (0 = number of CPU cores).',
            );
        }

        return $this->workerCount > 0 ? $this->workerCount : Cpu::count();
    }

    protected function ensureDirectories(): void
    {
        if (!is_file($this->workerScript)) {
            throw new RuntimePathException(
                message: 'Worker script not found: ' . $this->workerScript,
            );
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
        $written = $this->stateFile->write(
            new MasterState(
                pid: $this->masterPid,
                startedAt: microtime(true),
                workerCount: $this->workers,
                workerScript: $this->workerScript,
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

    protected function spawnAll(): void
    {
        for ($index = 0; $index < $this->workers; $index++) {
            $this->slots[$index] = null;
            $this->spawn($index);
        }
    }

    protected function spawn(int $index): void
    {
        try {
            $process = new WorkerProcess(
                command: $this->buildCommand(),
                cwd: $this->cwd,
                env: $this->buildEnv(),
            );
        } catch (WorkerSpawnException $exception) {
            $backoffMs = $this->nextBackoffMs($index, uptimeSeconds: 0.0);

            $this->slots[$index]     = null;
            $this->respawnAt[$index] = microtime(true) + $backoffMs / 1000;

            $this->logger->master(
                level: MasterLogger::ERROR,
                message: sprintf('worker %d spawn failed: %s; retry in %dms', $index, $exception->getMessage(), $backoffMs),
            );

            return;
        }

        $this->slots[$index] = $process;

        unset($this->respawnAt[$index]);

        $this->logger->worker(MasterLogger::INFO, $process->pid(), $index, 'spawned');
    }

    /**
     * Builds the worker command. The master appends its pid as the `--masterPid` argv
     * flag — the same channel as the address and the consumer's workerArgs, no
     * environment involved — and HttpServer::fromArgs() wires it into the orphan
     * check.
     *
     * @return list<string>
     */
    protected function buildCommand(): array
    {
        return [
            $this->phpBinary,
            ...$this->phpArgs,
            $this->workerScript,
            ...$this->workerArgs,
            static::MASTER_PID_ARG . '=' . $this->masterPid,
        ];
    }

    /**
     * The worker environment: the inherited environment with the consumer's extra
     * env merged over it. No master metadata is injected here — that goes via argv
     * (see buildCommand).
     *
     * @return array<string, string>
     */
    protected function buildEnv(): array
    {
        $env = getenv();

        foreach ($this->env as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }

    protected function supervise(): void
    {
        while (true) {
            $now = microtime(true);

            $this->reapAndLog();
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
                $this->respawnDue($now);

                // RestartPolicy::Never (or a clean exit under OnFailure): once every
                // worker has finished and nothing is pending, there is nothing left
                // to supervise.
                if ($this->allSlotsEmpty() && $this->respawnAt === []) {
                    $this->logger->master(MasterLogger::INFO, 'all workers finished; exiting');

                    break;
                }
            }

            // Flush the log once per tick (not per line) so STDOUT (docker logs) and
            // the file stay timely without a syscall per access-log line.
            $this->logger->flush();

            usleep(self::TICK_MICROSECONDS);
        }
    }

    /**
     * Drains each live worker's output into the log and handles any that have exited.
     */
    protected function reapAndLog(): void
    {
        foreach ($this->slots as $index => $process) {
            if ($process === null) {
                continue;
            }

            $this->logWorkerLines($index, $process, $process->drainOutput());

            if (!$process->isRunning()) {
                $this->handleExit($index, $process);
            }
        }
    }

    protected function handleExit(int $index, WorkerProcess $process): void
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

        if ($this->stopping) {
            $this->logger->worker(
                level: MasterLogger::INFO,
                workerPid: $pid,
                workerIndex: $index,
                message: sprintf('exited %s uptime=%.1fs (master stopping)', $reason, $uptimeSeconds),
            );

            return;
        }

        if (!$this->restartPolicy->shouldRestart($exitedCleanly)) {
            $this->logger->worker(
                level: MasterLogger::INFO,
                workerPid: $pid,
                workerIndex: $index,
                message: sprintf('exited %s uptime=%.1fs; not restarting (policy=%s)', $reason, $uptimeSeconds, $this->restartPolicy->value),
            );

            return;
        }

        $backoffMs = $this->nextBackoffMs($index, $uptimeSeconds);

        $this->respawnAt[$index] = microtime(true) + $backoffMs / 1000;

        $this->logger->worker(
            level: $exitedCleanly ? MasterLogger::INFO : MasterLogger::ERROR,
            workerPid: $pid,
            workerIndex: $index,
            message: sprintf('exited %s uptime=%.1fs; restarting in %dms', $reason, $uptimeSeconds, $backoffMs),
        );
    }

    /**
     * Computes the next respawn backoff for a slot: 0 when the worker ran long enough
     * to be considered healthy, otherwise an exponential delay that grows with each
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

        $backoffMs = $this->restartBackoffMs * (2 ** ($fails - 1));

        return (int) min($backoffMs, $this->maxRestartBackoffMs);
    }

    protected function respawnDue(float $now): void
    {
        foreach ($this->respawnAt as $index => $dueAt) {
            if ($dueAt <= $now && ($this->slots[$index] ?? null) === null) {
                unset($this->respawnAt[$index]);

                $this->spawn($index);
            }
        }
    }

    /**
     * Drives the graceful stop: forward SIGTERM once and arm the deadline, then
     * SIGKILL any stragglers once the deadline passes.
     */
    protected function driveShutdown(float $now): void
    {
        if (!$this->termSent) {
            $this->termSent     = true;
            $this->stopDeadline = $now + $this->shutdownTimeoutMs / 1000;
            $this->respawnAt    = [];

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

    protected function signalAll(int $signal): void
    {
        foreach ($this->slots as $process) {
            $process?->signal($signal);
        }
    }

    /**
     * @param list<WorkerOutputLine> $lines
     */
    protected function logWorkerLines(int $index, WorkerProcess $process, array $lines): void
    {
        foreach ($lines as $line) {
            $this->logger->worker(
                level: $line->isError ? MasterLogger::ERROR : MasterLogger::INFO,
                workerPid: $process->pid(),
                workerIndex: $index,
                message: $line->line,
            );
        }
    }

    protected function aliveSlotCount(): int
    {
        $count = 0;

        foreach ($this->slots as $process) {
            if ($process !== null) {
                $count++;
            }
        }

        return $count;
    }

    protected function allSlotsEmpty(): bool
    {
        return $this->aliveSlotCount() === 0;
    }
}
