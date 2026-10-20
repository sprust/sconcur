<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Exceptions\Worker\WorkerSpawnException;
use SConcur\Process\ProcessControl;
use SConcur\Process\ProcessSignalEnum;

/**
 * A fork group as the master runs it: through a fork server per generation (ForkServer), which
 * loads the application once and forks the workers from it. The fork server supervises its
 * workers itself; what is left here is the generations.
 *
 * - Start: the first generation brings every slot up on its own.
 * - Reload: the next generation's fork server is started with no slots, which loads the
 *   application afresh from disk. Then slot by slot: the new fork server brings the slot up,
 *   and once that worker is up the old fork server lets its worker of the slot go. When the
 *   old fork server has no slots left it is stopped. A fork server that does not load, or a
 *   replacement that does not come up within readyTimeoutMs, gives the reload up: the
 *   slots already moved are moved back the same way, and the new fork server is stopped, so
 *   the group stays one generation. The new fork server dying gives the reload up the same
 *   way; the old one dying while the slots are moved back leaves the group on the new one.
 * - A fork server that dies: its workers notice they are orphaned and stop (the master stops
 *   the ones it still knows of as well), and a new generation is started — at once after one
 *   that ran healthily, after a growing backoff when they keep dying soon after they start.
 */
class ForkServerGroup implements SupervisedGroup
{
    /** How long past shutdownTimeoutMs a released slot may take to be reported gone. */
    public const int RELEASE_GRACE_MS = 5_000;

    protected int $workers = 0;

    protected int $generationCount = 0;

    protected ?ForkServerGeneration $current = null;

    protected ?ForkServerGeneration $next = null;

    /** @var list<ForkServerGeneration> generations asked to stop, until they have */
    protected array $stopping = [];

    /** hrtime at which to start a new generation after the last one died; null when none is due. */
    protected ?int $respawnAtNs = null;

    /**
     * Consecutive generations that could not be started, or died before they had run
     * healthily past their load (drives the backoff).
     */
    protected int $fastFails = 0;

    protected bool $retiring = false;

    protected bool $cameUp = false;

    /**
     * Why the group failed to start, before it ever came up: a generation said its workers
     * never came up, or one died before it loaded or could not be started. Empty until then.
     */
    protected string $startFailure = '';

    protected bool $reloading = false;

    protected ?WorkerGroupConfig $configBeforeReload = null;

    protected string $reloadFailure = '';

    /** Set once a reload is given up: the steps left move slots back to the old generation. */
    protected bool $reverting = false;

    /** Set once the next generation has loaded and its moves are queued. */
    protected bool $planned = false;

    /** hrtime by which the next generation must load. */
    protected int $loadDeadlineNs = 0;

    /** @var list<SlotMove> the moves still to do */
    protected array $steps = [];

    /** The move under way, null between moves. */
    protected ?SlotMove $step = null;

    protected ?SlotMovePhaseEnum $stepPhase = null;

    protected int $stepDeadlineNs = 0;

    /** @var list<int> the slots moved into the next generation so far */
    protected array $moved = [];

    /**
     * @param null|Closure(WatchdogEvent): void $onWatchdogEvent
     * @param bool                              $guardsStartup   as WorkerGroup's: a group the master started with
     *                                                           that never comes up stops the master
     */
    public function __construct(
        protected WorkerGroupConfig $config,
        protected MasterLogger $logger,
        protected int $masterPid,
        protected string $cwd,
        protected string $logDir,
        protected string $name,
        protected int $rotateDays,
        protected LogTarget $logTo,
        protected string $telemetrySocket = '',
        protected ?Closure $onWatchdogEvent = null,
        protected ?int $listenFd = null,
        protected bool $guardsStartup = false,
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

    public function isReloading(): bool
    {
        return $this->reloading;
    }

    public function isRetiring(): bool
    {
        return $this->retiring;
    }

    public function hasPendingRespawns(): bool
    {
        return $this->respawnAtNs !== null;
    }

    public function allSlotsEmpty(): bool
    {
        return $this->generations() === [];
    }

    /** The workers of every generation, as their fork servers last reported them; not the fork servers. */
    public function aliveSlotCount(): int
    {
        $alive = 0;

        foreach ($this->generations() as $generation) {
            $alive += count($generation->pids);
        }

        return $alive;
    }

    public function neverCameUp(): bool
    {
        return $this->guardsStartup && !$this->cameUp && $this->startFailure !== '';
    }

    public function neverCameUpReason(): string
    {
        return $this->startFailure;
    }

    public function takeReloadFailure(): string
    {
        $failure = $this->reloadFailure;

        $this->reloadFailure = '';

        return $failure;
    }

    public function spawnAll(): void
    {
        $this->current = $this->launch(
            config: $this->config,
            spawnAll: true,
        );
    }

    public function signalAll(ProcessSignalEnum $signal): void
    {
        foreach ($this->generations() as $generation) {
            $generation->process->signal($signal);

            // A fork server that is killed leaves its workers to their orphan check; a kill is
            // what is sent when there is no time left for that.
            if ($signal === ProcessSignalEnum::Kill) {
                $this->signalWorkers(
                    generation: $generation,
                    signal: $signal,
                );
            }
        }
    }

    public function reapAndLog(bool $stopping): void
    {
        foreach ($this->generations() as $generation) {
            $this->logForkServerLines(
                generation: $generation,
                lines: $generation->process->drainOutput(),
            );

            foreach ($generation->process->takeEvents() as $event) {
                $this->handleEvent(
                    generation: $generation,
                    event: $event,
                );
            }

            if (!$generation->isRunning()) {
                $this->handleExit(
                    generation: $generation,
                    stopping: $stopping,
                );
            }
        }
    }

    /**
     * Starts the next generation once a dead one's backoff is over. The drains, the watchdog
     * and the respawns of single workers are each fork server's own, over its own workers.
     */
    public function tick(): void
    {
        if ($this->respawnAtNs === null || $this->retiring || hrtime(true) < $this->respawnAtNs) {
            return;
        }

        $this->respawnAtNs = null;

        $this->spawnAll();
    }

    /** The master asks for it only while no reload is under way (WorkerMaster::checkReloadSignal). */
    public function reconfigure(WorkerGroupConfig $config): void
    {
        $this->configBeforeReload = $this->config;
        $this->config             = $config;
        $this->workers            = $config->resolvedWorkerCount();

        // Nothing running to roll from — the last generation died and the next one is still
        // waiting out its backoff, or the group was being retired: the new settings simply
        // start the next generation whole.
        if ($this->current === null) {
            $this->respawnAtNs        = null;
            $this->configBeforeReload = null;

            $this->spawnAll();

            return;
        }

        $this->next = $this->launch(
            config: $config,
            spawnAll: false,
        );

        if ($this->next === null) {
            $this->abortReload('the next generation could not be started');

            return;
        }

        $this->reloading      = true;
        $this->reverting      = false;
        $this->planned        = false;
        $this->steps          = [];
        $this->step           = null;
        $this->moved          = [];
        $this->loadDeadlineNs = hrtime(true) + $config->readyTimeoutMs * 1_000_000;

        $this->logger->master(
            level: MasterLogger::INFO,
            message: sprintf(
                'group %s: rolling %d worker(s) onto generation %d',
                $config->name,
                $this->workers,
                $this->next->number,
            ),
        );
    }

    public function driveReload(): bool
    {
        if (!$this->reloading) {
            return false;
        }

        $next = $this->next;

        // A reload being given up goes on without the next generation: when its fork server
        // died, the slots it had taken still have to be brought back up in the old one.
        if ($next === null && !$this->reverting) {
            $this->finishReload();

            return false;
        }

        // The next generation loads first: nothing is touched until it has.
        if ($next !== null && !$next->loaded) {
            if ($next->refusedReason !== '') {
                $this->abortReload($next->refusedReason);
            } elseif (hrtime(true) > $this->loadDeadlineNs) {
                $this->abortReload(
                    sprintf('the next generation did not load within %dms', $this->config->readyTimeoutMs),
                );
            }

            return $this->reloading;
        }

        if (!$this->planned) {
            $this->planSteps();
        }

        $this->driveStep();

        // A step can give the reload up, and an abort with nothing to move back ends it
        // there and then.
        if (!$this->reloading) {
            return false;
        }

        if ($this->step === null && $this->steps === []) {
            $this->finishReload();

            return false;
        }

        return true;
    }

    /** The master asks for it only while no reload is under way (WorkerMaster::checkReloadSignal). */
    public function retire(): void
    {
        if ($this->retiring) {
            return;
        }

        $this->retiring    = true;
        $this->respawnAtNs = null;

        $this->logger->master(
            level: MasterLogger::INFO,
            message: sprintf('group %s: removed from the config; draining', $this->config->name),
        );

        foreach ($this->generations() as $generation) {
            $this->stop($generation);
        }

        $this->current = null;
        $this->next    = null;
    }

    public function unretire(): void
    {
        $this->retiring = false;
    }

    /** One move per slot: into the next generation, or out of the group for a slot the new count drops. */
    protected function planSteps(): void
    {
        $this->planned = true;

        $slots = max($this->workers, count($this->current->slots ?? []));

        for ($slot = 0; $slot < $slots; ++$slot) {
            $this->steps[] = new SlotMove(
                slot: $slot,
                from: $this->current,
                to: $slot < $this->workers ? $this->next : null,
            );
        }
    }

    /**
     * One move at a time: bring the slot up in `to` and wait until it is; then let it go in
     * `from` and wait until it is gone. A move with no `to` only lets go, one with no
     * `from` (or a `from` that is gone) only brings up.
     */
    protected function driveStep(): void
    {
        if ($this->step === null) {
            $step = array_shift($this->steps);

            if ($step === null) {
                return;
            }

            $this->step = $step;

            if ($step->to !== null) {
                $step->to->spawn($step->slot);

                $this->stepPhase      = SlotMovePhaseEnum::Spawn;
                $this->stepDeadlineNs = hrtime(true) + $this->config->readyTimeoutMs * 1_000_000;
            } else {
                $this->startRelease();
            }

            return;
        }

        $step = $this->step;
        $slot = $step->slot;

        if ($this->stepPhase === SlotMovePhaseEnum::Spawn) {
            $to = $step->to;

            if ($to === null) {
                $this->startRelease();

                return;
            }

            if (isset($to->ready[$slot])) {
                $this->logger->worker(
                    level: MasterLogger::INFO,
                    workerPid: $to->ready[$slot],
                    workerIndex: $slot,
                    message: 'up; took over the slot',
                    group: $this->config->name,
                );

                if (!$this->reverting) {
                    $this->moved[] = $slot;
                }

                $this->startRelease();

                return;
            }

            if ($to->isRunning()) {
                $failure = $to->failed[$slot] ?? null;
            } else {
                // Moving back, this is the running generation dying, and the next reap keeps
                // the next one in its place (handleExit). Moving forward, the move has failed,
                // and there is no point waiting out the deadline for it.
                if ($this->reverting) {
                    return;
                }

                $failure = sprintf(
                    'the fork server of generation %d exited before worker %d was up',
                    $to->number,
                    $slot,
                );
            }

            if ($failure === null && hrtime(true) > $this->stepDeadlineNs) {
                $failure = sprintf(
                    'the replacement for worker %d did not say it was up within %dms',
                    $slot,
                    $this->config->readyTimeoutMs,
                );
            }

            if ($failure === null) {
                return;
            }

            if ($this->reverting) {
                // Moving back onto the generation that was running: its fork server keeps bringing
                // the slot up with its own backoff, so the move goes on rather than stops.
                $this->logger->master(
                    level: MasterLogger::WARN,
                    message: sprintf('group %s: moving worker %d back: %s', $this->config->name, $slot, $failure),
                );

                $this->startRelease();

                return;
            }

            $to->release(
                slot: $slot,
                reason: 'the reload was given up',
            );

            $this->abortReload($failure);

            return;
        }

        $from = $step->from;

        $gone = $from === null || isset($from->gone[$slot]) || !$from->isRunning();

        if ($gone || hrtime(true) > $this->stepDeadlineNs) {
            $this->step = null;
        }
    }

    /** Lets the step's slot go in the generation it is moving out of. */
    protected function startRelease(): void
    {
        $step = $this->step;

        if ($step === null) {
            return;
        }

        $from = $step->from;

        if ($from === null || !$from->isRunning() || !isset($from->slots[$step->slot])) {
            $this->step = null;

            return;
        }

        $from->release(
            slot: $step->slot,
            reason: $step->to === null ? 'removed by the reload' : 'reloading',
        );

        $this->stepPhase      = SlotMovePhaseEnum::Release;
        $this->stepDeadlineNs = hrtime(true)
            + ($this->config->shutdownTimeoutMs + self::RELEASE_GRACE_MS) * 1_000_000;
    }

    /**
     * Gives the reload up. The slots already moved go back to the generation that was
     * running, the same way they came, and the next generation is stopped once they have:
     * the group ends as one generation, on the settings it had.
     */
    protected function abortReload(string $reason): void
    {
        $this->reloadFailure = sprintf('group %s: %s', $this->config->name, $reason);

        $this->logger->master(
            level: MasterLogger::ERROR,
            message: sprintf(
                'group %s: reload given up: %s; %s',
                $this->config->name,
                $reason,
                $this->current === null
                    ? 'the running generation is gone too, keeping the next one as it is'
                    : 'the workers it had not reached keep running, and the ones it moved are moved back',
            ),
        );

        // Nothing to move back to: the generation that was running died during the reload.
        if ($this->current === null) {
            $this->keepNextGeneration();

            return;
        }

        if ($this->configBeforeReload !== null) {
            $this->config  = $this->configBeforeReload;
            $this->workers = $this->config->resolvedWorkerCount();
        }

        $this->reverting = true;
        $this->step      = null;
        $this->steps     = [];

        foreach ($this->moved as $slot) {
            $this->steps[] = new SlotMove(
                slot: $slot,
                from: $this->next,
                to: $this->current,
            );
        }

        $this->moved = [];

        if ($this->steps === []) {
            $this->finishReload();
        }
    }

    /**
     * Ends the reload on the next generation, whatever it was doing: the generation that was
     * running is gone, so the next one is all the group has. It stays, on the settings it was
     * started with, and every slot it does not have yet is brought up there; its fork server
     * keeps trying the ones that fail, with its own backoff. With the next one gone as well,
     * the group is left with no generation, on the settings from before the reload.
     */
    protected function keepNextGeneration(): void
    {
        $this->reverting = false;
        $this->steps     = [];
        $this->step      = null;

        $next = $this->next;

        // The settings follow the generation that is left. A reload being given up has
        // already put back the ones from before it, which the next generation does not run.
        $this->config  = $next->config ?? $this->configBeforeReload ?? $this->config;
        $this->workers = $this->config->resolvedWorkerCount();

        if ($next !== null) {
            for ($slot = 0; $slot < $this->workers; ++$slot) {
                if (!isset($next->slots[$slot])) {
                    $next->spawn($slot);
                }
            }
        }

        $this->finishReload();
    }

    /**
     * Ends the reload either way: done, the next generation takes over and the old one is
     * stopped; given up, the next one is stopped and the old one stays.
     */
    protected function finishReload(): void
    {
        $this->reloading          = false;
        $this->configBeforeReload = null;
        $this->step               = null;
        $this->steps              = [];
        $this->moved              = [];

        if ($this->reverting) {
            $this->reverting = false;

            if ($this->next !== null) {
                $this->stop($this->next);
            }

            $this->next = null;

            return;
        }

        if ($this->current !== null) {
            $this->stop($this->current);
        }

        $this->current = $this->next;
        $this->next    = null;

        // Both generations died during the reload: nothing took over, and the caller starts
        // a new one.
        if ($this->current === null) {
            return;
        }

        $this->logger->master(
            level: MasterLogger::INFO,
            message: sprintf('group %s: reload complete', $this->config->name),
        );
    }

    /** Asks a generation's fork server to stop; it drains its workers first. */
    protected function stop(ForkServerGeneration $generation): void
    {
        if (!in_array($generation, $this->stopping, true)) {
            $this->stopping[] = $generation;
        }

        $generation->process->signal(ProcessSignalEnum::Terminate);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleEvent(ForkServerGeneration $generation, array $event): void
    {
        $slot = (int) ($event['slot'] ?? -1);

        switch ($event['e']) {
            case ForkServer::EVENT_LOADED:
                $generation->loaded     = true;
                $generation->loadedAtNs = hrtime(true);

                break;
            case ForkServer::EVENT_REFUSED:
                $generation->refusedReason = (string) ($event['reason'] ?? 'the fork server refused to fork');

                break;
            case ForkServer::EVENT_READY:
                $generation->ready[$slot] = (int) ($event['pid'] ?? 0);
                $generation->slots[$slot] = true;

                $this->cameUp = true;

                break;
            case ForkServer::EVENT_FAILED:
                $generation->failed[$slot] = (string) ($event['reason'] ?? 'the replacement did not come up');

                break;
            case ForkServer::EVENT_GONE:
                $generation->gone[$slot] = true;

                break;
            case ForkServer::EVENT_ALIVE:
                // Only real pids: the master signals these when the fork server is gone, and
                // a malformed entry read as 0 would make that refuse.
                $generation->pids = array_values(array_filter(
                    array_map(intval(...), (array) ($event['pids'] ?? [])),
                    static fn(int $pid): bool => $pid > 0,
                ));

                break;
            case ForkServer::EVENT_NEVER:
                $this->startFailure = sprintf('%d failed before any said it was up', $this->workers);

                break;
            case ForkServer::EVENT_FINISHED:
                $generation->finished = true;

                break;
            case ForkServer::EVENT_WATCHDOG:
                $this->reportWatchdogEvent($event);

                break;
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function reportWatchdogEvent(array $event): void
    {
        $kind = WatchdogEventEnum::tryFrom((string) ($event['kind'] ?? ''));

        if ($kind === null || $this->onWatchdogEvent === null) {
            return;
        }

        // The master's handler (WorkerMaster::watchdogReporter) logs and drops what the
        // application's own throws.
        ($this->onWatchdogEvent)(new WatchdogEvent(
            event: $kind,
            group: $this->config->name,
            slot: (int) ($event['slot'] ?? -1),
            pid: (int) ($event['pid'] ?? 0),
            ageSeconds: isset($event['ageSeconds']) ? (float) $event['ageSeconds'] : null,
            watchdogTimeoutMs: $this->config->watchdogTimeoutMs,
        ));
    }

    /**
     * A fork server exited. One asked to stop is simply gone. Otherwise its workers are left
     * orphaned and stop by themselves, and the master stops the ones it knows of too. The
     * running generation dying starts a new one, or, while a reload is being given up, leaves
     * the group on the next one; the next one dying gives the reload up.
     */
    protected function handleExit(ForkServerGeneration $generation, bool $stopping): void
    {
        $this->logForkServerLines(
            generation: $generation,
            lines: $generation->process->drainFinalOutput(),
        );

        $reason = $generation->process->exitReason();

        // A fork server whose workers all finished under a policy that does not restart them
        // leaves on its own, and that is the group finishing, not a generation dying.
        $expected = $stopping || $generation->finished || in_array($generation, $this->stopping, true);

        $this->logger->master(
            level: $expected ? MasterLogger::INFO : MasterLogger::ERROR,
            message: sprintf(
                'group %s: fork server of generation %d exited %s',
                $this->config->name,
                $generation->number,
                $reason,
            ),
        );

        $this->signalWorkers(
            generation: $generation,
            signal: ProcessSignalEnum::Terminate,
        );

        $generation->process->close();

        $this->stopping = array_values(array_filter(
            $this->stopping,
            static fn(ForkServerGeneration $stopped): bool => $stopped !== $generation,
        ));

        if ($generation === $this->next) {
            $this->next = null;

            if ($this->reloading && !$this->reverting && !$expected) {
                $this->abortReload(
                    $generation->refusedReason !== ''
                        ? $generation->refusedReason
                        : sprintf('the fork server of the next generation exited %s', $reason),
                );
            }

            // The running generation died earlier in this reload, so this one was all the
            // group had: with both gone, a new one is started as after any generation that died.
            if ($this->current === null && !$this->reloading && !$expected && !$this->retiring) {
                $this->startNewGeneration($generation->ranHealthily());
            }

            return;
        }

        if ($generation !== $this->current) {
            return;
        }

        $this->current = null;

        if ($expected || $this->retiring) {
            return;
        }

        if ($this->reloading && $this->reverting) {
            // The slots were being moved back onto this generation, and there is nothing to move
            // them onto now: the next generation is all the group has.
            $this->logger->master(
                level: MasterLogger::ERROR,
                message: sprintf(
                    'group %s: the running generation died while the reload was being given up;'
                    . ' keeping the next one',
                    $this->config->name,
                ),
            );

            $this->keepNextGeneration();
        } elseif ($this->reloading) {
            // During a reload the next generation takes every slot anyway: the moves out of a
            // generation that is gone only bring the slot up.
            return;
        }

        if ($this->current !== null) {
            return;
        }

        // A bootstrap that does not load fails every generation the same way: for a group
        // the master started with, that is a failed start.
        if (!$generation->loaded && !$this->cameUp) {
            $this->startFailure = $generation->refusedReason !== ''
                ? $generation->refusedReason
                : sprintf('the fork server of generation %d exited %s before it loaded', $generation->number, $reason);
        }

        $this->startNewGeneration($generation->ranHealthily());
    }

    /**
     * Schedules the generation that replaces one that died or could not be started: at once
     * after one that ran healthily, otherwise after a backoff that doubles with each such
     * failure in a row. A fault every generation shares — a bootstrap that throws, a fork
     * server that crashes soon after it loads, a phpBinary that cannot be run — would
     * otherwise have the master start a php and load the application again on every tick.
     */
    protected function startNewGeneration(bool $ranHealthily): void
    {
        if ($ranHealthily) {
            $this->fastFails = 0;
        } else {
            ++$this->fastFails;
        }

        $backoffMs = $this->config->backoffMs($this->fastFails);

        $this->respawnAtNs = hrtime(true) + $backoffMs * 1_000_000;

        $this->logger->master(
            level: MasterLogger::ERROR,
            message: sprintf(
                'group %s: starting a new generation in %dms',
                $this->config->name,
                $backoffMs,
            ),
        );
    }

    /** Sends a signal to the workers a generation last reported, the fork server being gone or going. */
    protected function signalWorkers(ForkServerGeneration $generation, ProcessSignalEnum $signal): void
    {
        foreach ($generation->pids as $pid) {
            ProcessControl::kill(
                pid: $pid,
                signal: $signal,
            );
        }
    }

    /** Starts a generation's fork server; null, logged, when it could not be started. */
    protected function launch(WorkerGroupConfig $config, bool $spawnAll): ?ForkServerGeneration
    {
        $number = ++$this->generationCount;

        try {
            $process = new ForkServerProcess(
                command: [
                    $config->phpBinary,
                    '-d',
                    'display_errors=stderr',
                    ...$config->phpArgs,
                    dirname(__DIR__, 2) . '/bin/sconcur-fork-server',
                ],
                cwd: $this->cwd,
                env: [
                    ...getenv(),
                    ForkServer::SPEC_ENVIRONMENT_NAME => (string) json_encode(
                        $this->spec(
                            config: $config,
                            spawnAll: $spawnAll,
                        ),
                        JSON_UNESCAPED_SLASHES,
                    ),
                ],
                keepDescriptors: $this->listenFd === null ? [] : [$this->listenFd],
            );
        } catch (WorkerSpawnException $exception) {
            $failure = sprintf(
                'the fork server of generation %d could not be started: %s',
                $number,
                $exception->getMessage(),
            );

            $this->logger->master(
                level: MasterLogger::ERROR,
                message: sprintf('group %s: %s', $config->name, $failure),
            );

            // A generation started whole is all the group has, and is replaced as one that
            // died before it loaded; a reload's next one gives the reload up instead.
            if ($spawnAll) {
                if (!$this->cameUp) {
                    $this->startFailure = $failure;
                }

                $this->startNewGeneration(ranHealthily: false);
            }

            return null;
        }

        $this->logger->master(
            level: MasterLogger::INFO,
            message: sprintf(
                'group %s: fork server of generation %d started pid=%d',
                $config->name,
                $number,
                $process->pid(),
            ),
        );

        $generation = new ForkServerGeneration(
            number: $number,
            config: $config,
            process: $process,
        );

        if ($spawnAll) {
            $workers = $config->resolvedWorkerCount();

            for ($slot = 0; $slot < $workers; ++$slot) {
                $generation->slots[$slot] = true;
            }
        }

        return $generation;
    }

    /**
     * What a fork server is started with: the group, how the journal is written, and where the
     * workers push their telemetry. A group without a bootstrap loads the autoloader the
     * master loaded, found the way bin/sconcur-server finds it; with neither the fork server
     * would have nothing to load, and is not started.
     *
     * @return array<string, mixed>
     */
    protected function spec(WorkerGroupConfig $config, bool $spawnAll): array
    {
        $candidates = [
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            dirname(__DIR__, 4) . '/autoload.php',
        ];

        $autoload = '';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $autoload = $candidate;

                break;
            }
        }

        if ($autoload === '' && $config->bootstrap === '') {
            throw new WorkerSpawnException(
                message: sprintf(
                    'the group has no bootstrap, and no Composer autoloader was found for its fork server'
                    . ' at %s; give the group a bootstrap that requires the autoloader',
                    implode(' or ', $candidates),
                ),
            );
        }

        return [
            'group'           => $config->toArray(),
            'masterPid'       => $this->masterPid,
            'cwd'             => $this->cwd,
            'logDir'          => $this->logDir,
            'name'            => $this->name,
            'rotateDays'      => $this->rotateDays,
            'logTo'           => $this->logTo->value,
            'telemetrySocket' => $this->telemetrySocket,
            'listenFd'        => $this->listenFd,
            'spawnAll'        => $spawnAll,
            // Every generation started whole, for as long as the group the master started with
            // has not come up: one respawned after a broken first one fails the same way, and
            // its workers never coming up still stops the master. Once a worker has been up,
            // and for a reload's generation, a failure is a failed respawn or reload, not a
            // failed deploy.
            'guardsStartup'   => $this->guardsStartup && !$this->cameUp && $spawnAll,
            'autoload'        => $autoload,
        ];
    }

    /**
     * @param list<WorkerOutputLine> $lines
     */
    protected function logForkServerLines(ForkServerGeneration $generation, array $lines): void
    {
        foreach ($lines as $line) {
            $this->logger->master(
                level: $line->isError ? MasterLogger::ERROR : MasterLogger::INFO,
                message: sprintf(
                    'group %s: fork server %d: %s',
                    $this->config->name,
                    $generation->process->pid(),
                    $line->line,
                ),
            );
        }
    }

    /** @return list<ForkServerGeneration> every generation whose fork server is still there */
    protected function generations(): array
    {
        $generations = $this->stopping;

        foreach ([$this->current, $this->next] as $generation) {
            if ($generation !== null && !in_array($generation, $generations, true)) {
                $generations[] = $generation;
            }
        }

        return $generations;
    }
}
