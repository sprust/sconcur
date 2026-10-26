<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Process\ForkRefusedException;
use SConcur\Process\ProcessControl;
use SConcur\Process\ProcessSignalEnum;
use Throwable;

/**
 * The fork server of a fork group: a php the master starts for each generation of the group,
 * which loads the application once (the group's bootstrap, see bin/sconcur-fork-server) and
 * forks the workers from it. It runs the group's WorkerGroup as the master runs an exec
 * group's — spawning, reaping, the watchdog, restarts — so the workers are its children
 * and their pipes are its own.
 *
 * A reload does not touch it: the master starts the next generation's fork server, which loads
 * the application afresh from disk, and moves the slots across one at a time — "spawn" to
 * the new fork server, "release" to the old one. What the master needs to know comes back as
 * events: loaded, a slot up, a slot gone, a replacement that failed, the live pids, the
 * watchdog, a group that never came up.
 *
 * Commands arrive on stdin and events leave on EVENTS_FD, one JSON object per line. The
 * fork server writes the journal itself, into the same file as the master, scoped
 * `fork-server: pid group`; its stderr is read by the master.
 */
class ForkServer
{
    /** The environment variable the master hands the spec over in. */
    public const string SPEC_ENVIRONMENT_NAME = 'SCONCUR_FORK_SERVER_SPEC';

    /** The descriptor the master opens the fork server's events pipe under. */
    public const int EVENTS_FD = 3;

    /**
     * The environment variable that names EVENTS_FD to the fork server. The extension claims
     * the pipe from it as it loads — close-on-exec before the bootstrap runs, so nothing the
     * bootstrap or a worker starts inherits it — and writes the events itself; PHP opens no
     * stream on it (php://fd would open a copy without close-on-exec).
     *
     * Rust: ENVIRONMENT_NAME in ext/src/process/events.rs.
     */
    public const string EVENTS_FD_ENVIRONMENT_NAME = 'SCONCUR_FORK_SERVER_EVENTS_FD';

    public const string COMMAND_SPAWN   = 'spawn';
    public const string COMMAND_RELEASE = 'release';

    public const string EVENT_LOADED   = 'loaded';
    public const string EVENT_REFUSED  = 'refused';
    public const string EVENT_READY    = 'ready';
    public const string EVENT_FAILED   = 'failed';
    public const string EVENT_GONE     = 'gone';
    public const string EVENT_ALIVE    = 'alive';
    public const string EVENT_WATCHDOG = 'watchdog';
    public const string EVENT_NEVER    = 'never';
    public const string EVENT_FINISHED = 'finished';

    protected const int TICK_MICROSECONDS = 100_000;

    protected const float SIGKILL_GRACE_SECONDS = 2.0;

    protected WorkerGroup $group;

    protected MasterLogger $logger;

    protected string $commandBuffer = '';

    /** @var array<int, int> slot => pid of a worker spawned on command, not up yet */
    protected array $awaited = [];

    /** @var array<int, int> slot => pid of the worker already reported up */
    protected array $reportedReady = [];

    /** @var array<int, DrainingWorker|null> slot => the worker a release let go of */
    protected array $released = [];

    /** @var list<int> */
    protected array $reportedPids = [];

    protected bool $neverReported = false;

    /** Set once the master asked for a slot to be released: this generation is being replaced. */
    protected bool $replaced = false;

    /**
     * @param array<string, mixed> $spec what the master wrote into SPEC_ENVIRONMENT_NAME, see
     *                                   ForkServerGroup::spec()
     */
    public function __construct(protected array $spec)
    {
    }

    /**
     * Runs the fork server until the master asks it to stop or goes away. The application's
     * error handler is kept out of the loop as WorkerMaster::run keeps it out of the
     * master's; ForkedWorker gives it back to every worker.
     *
     * @param array<string, mixed> $spec
     */
    public static function run(array $spec): int
    {
        set_error_handler(static fn(): bool => false);

        try {
            return new self(spec: $spec)->supervise();
        } finally {
            restore_error_handler();
        }
    }

    protected function supervise(): int
    {
        $pid    = (int) getmypid();
        $config = WorkerGroupConfig::fromResolvedArray((array) $this->spec['group']);

        $this->logger = new MasterLogger(
            logDir: (string) $this->spec['logDir'],
            name: (string) $this->spec['name'],
            rotateDays: (int) $this->spec['rotateDays'],
            masterPid: $pid,
            logTo: LogTarget::from((string) $this->spec['logTo']),
            scope: sprintf('fork-server: %d %s', $pid, $config->name),
        );

        if (!ProcessControl::hasForkServerEvents()) {
            fwrite(STDERR, 'fork server: the events pipe is not open' . PHP_EOL);

            return 1;
        }

        stream_set_blocking(STDIN, false);

        // The bootstrap ran before this: an asynchronous call there started the extension's
        // runtime, and a process with threads cannot fork.
        try {
            ProcessControl::assertForkable();
        } catch (ForkRefusedException $exception) {
            $reason = sprintf(
                'this generation cannot fork: %s. Nothing may use the extension while the bootstrap'
                . ' loads; open connections and make calls in the worker script',
                $exception->getMessage(),
            );

            $this->logger->master(
                level: MasterLogger::ERROR,
                message: $reason,
            );

            $this->logger->close();

            $this->emit(
                event: self::EVENT_REFUSED,
                data: ['reason' => $reason],
            );

            return 1;
        }

        $listenFd = isset($this->spec['listenFd']) ? (int) $this->spec['listenFd'] : null;

        // Taken after the bootstrap, so what the application opened while loading — a log
        // file, a socket systemd passed — stays open in its workers. The events pipe is the
        // fork server's alone, and the listening socket is handed to the workers anyway.
        $inherited = static::applicationDescriptors(
            exclude: [self::EVENTS_FD, $listenFd ?? -1],
        );

        ProcessControl::watchSignals([
            ProcessSignalEnum::Terminate,
            ProcessSignalEnum::Interrupt,
        ]);

        $this->group = new WorkerGroup(
            config: $config,
            logger: $this->logger,
            masterPid: $pid,
            cwd: (string) $this->spec['cwd'],
            telemetrySocket: (string) $this->spec['telemetrySocket'],
            onWatchdogEvent: function (WatchdogEvent $event): void {
                $this->emit(
                    event: self::EVENT_WATCHDOG,
                    data: [
                        'kind'       => $event->event->value,
                        'slot'       => $event->slot,
                        'pid'        => $event->pid,
                        'ageSeconds' => $event->ageSeconds,
                    ],
                );
            },
            listenFd: $listenFd,
            inheritedDescriptors: $inherited,
            guardsStartup: (bool) $this->spec['guardsStartup'],
            accessLogPrefix: MasterLogger::accessLogPrefix(
                logDir: (string) $this->spec['logDir'],
                name: (string) $this->spec['name'],
            ),
        );

        $this->logger->master(
            level: MasterLogger::INFO,
            message: 'loaded; forking workers from here',
        );

        $this->emit(event: self::EVENT_LOADED);

        if ((bool) $this->spec['spawnAll']) {
            $this->group->spawnAll();
        }

        $this->loop();

        ProcessControl::restoreSignals();

        $this->logger->master(
            level: MasterLogger::INFO,
            message: 'stopped',
        );

        $this->logger->close();

        return 0;
    }

    protected function loop(): void
    {
        $masterPid      = (int) $this->spec['masterPid'];
        $stopping       = false;
        $killSent       = false;
        $stopDeadlineNs = 0;
        $killDeadlineNs = 0;

        while (true) {
            $nowNs = hrtime(true);

            $this->group->reapAndLog($stopping);

            if (!$stopping) {
                $orphaned = ProcessControl::parentPid() !== $masterPid;

                if ($orphaned || ProcessControl::takeSignals() !== []) {
                    $stopping       = true;
                    $stopDeadlineNs = $nowNs + $this->group->shutdownTimeoutMs() * 1_000_000;

                    $this->logger->master(
                        level: $orphaned ? MasterLogger::WARN : MasterLogger::INFO,
                        message: $orphaned
                            ? 'the master is gone; stopping the workers'
                            : 'stopping; forwarding SIGTERM to the workers',
                    );

                    $this->group->signalAll(ProcessSignalEnum::Terminate);
                }
            }

            if ($stopping) {
                if ($this->group->allSlotsEmpty()) {
                    break;
                }

                if (!$killSent && $nowNs > $stopDeadlineNs) {
                    $killSent       = true;
                    $killDeadlineNs = $nowNs + (int) (self::SIGKILL_GRACE_SECONDS * 1_000_000_000);

                    $this->group->signalAll(ProcessSignalEnum::Kill);
                } elseif ($killSent && $nowNs > $killDeadlineNs) {
                    break;
                }
            } else {
                $this->takeCommands();

                $this->group->tick();
            }

            $this->report();

            if (!$stopping && $this->finished()) {
                $this->logger->master(
                    level: MasterLogger::INFO,
                    message: 'every worker finished; nothing to restart',
                );

                $this->emit(event: self::EVENT_FINISHED);

                break;
            }

            $this->logger->flush();

            usleep(self::TICK_MICROSECONDS);
        }

        $this->report();
    }

    /**
     * Whether the group is done: it started its slots itself, every worker has exited
     * and the restart policy brings none back. A generation being replaced is not done
     * when its last slot is released — the master stops it.
     */
    protected function finished(): bool
    {
        return (bool) $this->spec['spawnAll']
            && !$this->replaced
            && $this->awaited === []
            && $this->group->allSlotsEmpty()
            && !$this->group->hasPendingRespawns();
    }

    /** Reads what the master asked for since the last tick and does it. */
    protected function takeCommands(): void
    {
        while (($chunk = fread(STDIN, 65536)) !== false && $chunk !== '') {
            $this->commandBuffer .= $chunk;
        }

        while (($newline = strpos($this->commandBuffer, "\n")) !== false) {
            $line = substr($this->commandBuffer, 0, $newline);

            $this->commandBuffer = substr($this->commandBuffer, $newline + 1);

            $command = json_decode($line, true);

            if (!is_array($command) || !isset($command['c'], $command['slot'])) {
                continue;
            }

            $slot = (int) $command['slot'];

            if ($command['c'] === self::COMMAND_SPAWN) {
                $this->group->spawnSlot($slot);

                $worker = $this->group->slotWorker($slot);

                if ($worker === null) {
                    $this->emit(
                        event: self::EVENT_FAILED,
                        data: [
                            'slot'   => $slot,
                            'reason' => sprintf('the replacement for worker %d could not be started', $slot),
                        ],
                    );

                    continue;
                }

                $this->awaited[$slot] = $worker->pid();
            } elseif ($command['c'] === self::COMMAND_RELEASE) {
                unset($this->awaited[$slot], $this->reportedReady[$slot]);

                $this->replaced = true;

                $this->released[$slot] = $this->group->releaseSlot(
                    index: $slot,
                    reason: (string) ($command['reason'] ?? 'released'),
                );
            }
        }
    }

    /** Tells the master what changed since the last tick. */
    protected function report(): void
    {
        foreach ($this->awaited as $slot => $pid) {
            $worker = $this->group->slotWorker($slot);

            if ($worker !== null && $worker->pid() === $pid) {
                continue;
            }

            unset($this->awaited[$slot]);

            $this->emit(
                event: self::EVENT_FAILED,
                data: [
                    'slot'   => $slot,
                    'reason' => sprintf('the replacement for worker %d exited before it was up', $slot),
                ],
            );
        }

        foreach ($this->group->slotIndices() as $slot) {
            $worker = $this->group->slotWorker($slot);

            if ($worker === null || !$worker->isReady() || ($this->reportedReady[$slot] ?? 0) === $worker->pid()) {
                continue;
            }

            $this->reportedReady[$slot] = $worker->pid();

            unset($this->awaited[$slot]);

            $this->emit(
                event: self::EVENT_READY,
                data: [
                    'slot' => $slot,
                    'pid'  => $worker->pid(),
                ],
            );
        }

        foreach ($this->released as $slot => $draining) {
            if ($draining !== null && $this->group->isDraining($draining)) {
                continue;
            }

            unset($this->released[$slot]);

            $this->emit(
                event: self::EVENT_GONE,
                data: ['slot' => $slot],
            );
        }

        $pids = $this->group->livePids();

        sort($pids);

        if ($pids !== $this->reportedPids) {
            $this->reportedPids = $pids;

            $this->emit(
                event: self::EVENT_ALIVE,
                data: ['pids' => $pids],
            );
        }

        if (!$this->neverReported && $this->group->neverCameUp()) {
            $this->neverReported = true;

            $this->emit(event: self::EVENT_NEVER);
        }
    }

    /**
     * The descriptors this process has open that the application opened, but for $exclude.
     * Left out besides: the placeholders on /dev/null the master's spawn put over its own
     * descriptors, and the script PHP runs, bin/sconcur-fork-server — neither is the
     * application's, and a worker that kept them would hold them for nothing. They are
     * pointed at /dev/null in the worker like everything else it does not keep.
     *
     * @param list<int> $exclude
     *
     * @return list<int>
     */
    protected static function applicationDescriptors(array $exclude): array
    {
        $notTheApplications = ['/dev/null', (string) realpath(get_included_files()[0])];

        $descriptors = [];

        foreach (ProcessControl::openDescriptors() as $descriptor) {
            if (in_array($descriptor, $exclude, true)) {
                continue;
            }

            if (in_array(@readlink('/proc/self/fd/' . $descriptor), $notTheApplications, true)) {
                continue;
            }

            $descriptors[] = $descriptor;
        }

        return $descriptors;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function emit(string $event, array $data = []): void
    {
        try {
            // Not written when the master is gone or going; the orphan check ends this.
            ProcessControl::writeForkServerEvent(
                json_encode(['e' => $event, ...$data], JSON_THROW_ON_ERROR) . "\n",
            );
        } catch (Throwable) {
            // An event that cannot be encoded or written is lost rather than taking the
            // fork server and its workers down with it.
        }
    }
}
