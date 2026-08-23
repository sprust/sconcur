<?php

declare(strict_types=1);

namespace SConcur\Scheduler;

use Closure;
use Fiber;
use SConcur\Connection\Extension;
use SConcur\Dto\PendingNextDto;
use SConcur\Dto\PendingPushDto;
use SConcur\Dto\PendingSwitchDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\CallbackExecutionException;
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Exceptions\FiberStateException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Exceptions\InvalidCoroutineTimeoutException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Flow\CurrentFlow;
use SConcur\State;
use SConcur\WaitGroup;
use Throwable;

/**
 * Single process-wide cooperative scheduler. It is the only place that waits on
 * the extension (waitAny) and resumes fibers, so coroutines never nest on each
 * other's call stack: a suspend always returns control here. This is what lets
 * nested coroutines run concurrently with the outer flow instead of a nested
 * WaitGroup monopolising the single PHP thread.
 */
class Scheduler
{
    /**
     * How long the server loop blocks on one waitAny before looping to re-check
     * for a shutdown signal. Bounds the shutdown latency of an idle server.
     */
    private const int SERVE_POLL_INTERVAL_MS = 250;

    /** How often a stalled graceful drain reports what it is still waiting for. */
    private const int DRAIN_DIAGNOSTIC_INTERVAL_SECONDS = 5;

    /** How many coroutines that report names, so the line stays a log line. */
    private const int DRAIN_DIAGNOSTIC_MAX_COROUTINES = 8;

    /**
     * Default quantum of switch(): a coroutine yields at most once per this many
     * milliseconds, so the call can sit inside a hot loop and cost one hrtime()
     * comparison in the common case.
     */
    private const int DEFAULT_SWITCH_QUANTUM_MS = 5;

    /**
     * How many ready results one waitAnyBatch crossing may drain. Bounds the
     * peak multiframe size and, in the serve loop, the time between
     * shouldStop() checks; a batch never waits to fill up, so the value only
     * caps a burst.
     */
    private const int WAIT_BATCH_SIZE = 64;

    protected static ?Scheduler $instance = null;

    /**
     * Recycles the fibers of spawned coroutines: a request handler runs on a
     * pooled fiber whose stack is mapped once, not created and unmapped per
     * request. Stale-result safety does not depend on fiber identity: a pooled
     * fiber's spl_object_id repeats across requests by construction, but
     * resumeByResult() validates the awaited flow/task keys, and those are fed
     * by never-reused monotonic counters ($spawnCounter here, the task counter
     * in Extension::push).
     */
    protected FiberPool $fiberPool;

    /**
     * All live coroutines across every WaitGroup, keyed by fiber id.
     *
     * @var array<int, Coroutine>
     */
    protected array $coroutines = [];

    /**
     * Coroutines suspended inside a nested WaitGroup::iterate, waiting for that
     * child group to settle. Keyed by the awaited group's key, value is the
     * waiting fiber id.
     *
     * @var array<string, int>
     */
    protected array $groupWaiters = [];

    /**
     * Number of live spawned (groupless) coroutines — request handlers in the
     * server loop. Used to drain in-flight requests on graceful shutdown.
     */
    protected int $spawnedCount = 0;

    /**
     * FIFO of fiber ids parked by switch() (a cooperative yield). Drained by the
     * scheduler loops once nothing else is deliverable right now. A coroutine
     * unwound while parked (stop/shutdown) is purged from the queue immediately
     * (detach/forget): spl_object_id is reused after the fiber is freed, so a
     * stale id left behind could resume a future unrelated coroutine.
     *
     * @var array<int, int>
     */
    protected array $switchedCoroutines = [];

    /**
     * Deadlines of the coroutines that were given one: fiber id => hrtime(true) value.
     *
     * Kept beside the coroutines rather than scanned out of them: a coroutine without a
     * deadline is not in here at all, so the sweep and the wait bound below cost one
     * emptiness test on a process that never asks for one.
     *
     * @var array<int, int>
     */
    protected array $deadlines = [];

    /**
     * The earliest value in $deadlines, cached; 0 while there are none.
     *
     * Both users of the index — enforceDeadlines() and boundedByDeadline() — run on every
     * turn of both scheduler loops, and a server that gives each request a deadline
     * (handlerTimeoutMs, on by default) has one entry per in-flight request. Scanning the
     * index there made both a linear pass per delivered result.
     *
     * The cache is a lower bound, never a value that is later than the true minimum:
     * inserting keeps the smaller of the two, removing leaves it alone. So a stale cache
     * can only make enforceDeadlines() take its full sweep one turn too early, and that
     * sweep ends by recomputing the exact value — it cannot make an expiry go unnoticed.
     */
    protected int $nearestDeadlineNs = 0;

    /**
     * Per-coroutine hrtime(true) timestamps starting the current switch quantum:
     * recorded when the coroutine resumes from a switch() yield (and by its first
     * switch() call). Measuring from the resume — not the park — keeps queue
     * waiting time out of the quantum, so identical CPU loops share the thread
     * evenly. Keyed by fiber id; released in forget()/detach() with the
     * coroutine.
     *
     * @var array<int, int>
     */
    protected array $lastSwitchNs = [];

    /**
     * Ready results pulled from the extension in one waitAnyBatch crossing but
     * not consumed yet. The loops take one result per iteration from here (all
     * the per-event logic is unchanged) and cross into Go only when the queue
     * is empty. A queued result can go stale — handling an earlier result of
     * the same batch may stop its flow — see resumeByResult().
     *
     * @var list<TaskResultDto>
     */
    protected array $readyResults = [];

    /**
     * Counts the delivered results that found no owning coroutine and were
     * dropped (see resumeByResult / droppedResultsCount).
     */
    protected int $droppedResultsCount = 0;

    /**
     * Dispatches handed over from fiber stacks, waiting to run on the main C
     * stack: [coroutine, pending DTO]. A cgo call entering Go with the stack
     * pointer inside a fiber stack forces the Go runtime to re-derive its
     * system-stack bounds, which glibc answers for the process main thread with
     * a full /proc/self/maps read+parse — hundreds of microseconds per crossing
     * in a mapping-heavy process. Queued by dispatchPendingTask when it runs on
     * a fiber stack (a nested WaitGroup::add / spawn), drained by
     * takeReadyResult before any wait crossing.
     *
     * The queue holds the Coroutine, not the fiber: under the FiberPool one
     * Fiber object serves many coroutines in turn, so fiber identity no longer
     * identifies the owner of a queued dispatch (see drainPendingDispatches).
     *
     * @var list<array{0: Coroutine, 1: PendingPushDto|PendingNextDto}>
     */
    protected array $pendingDispatches = [];

    /**
     * Go-side stopFlow crossings deferred off fiber stacks (same reason as
     * $pendingDispatches; queued via deferStopFlow from State::deleteFlow when
     * a flow is deleted from inside a fiber, e.g. WaitGroup::stop in a nested
     * group's iterate). Drained after the pending dispatches: a queued push of
     * a live flow must land before an unrelated flow's stop, never after its
     * own — group flow keys are never reused, so the order across flows is
     * free, but draining pushes first keeps the invariant simple.
     *
     * @var list<string>
     */
    protected array $pendingStopFlows = [];

    /**
     * Monotonic counter feeding spawned-coroutine flow keys. A flow key only has
     * to be unique among live flows in this process, so a never-reused counter is
     * enough — and far cheaper than uniqid() on the per-request hot path.
     */
    protected int $spawnCounter = 0;

    public function __construct()
    {
        $this->fiberPool = new FiberPool();
    }

    public static function get(): Scheduler
    {
        if (static::$instance === null) {
            static::$instance = new Scheduler();

            // exit()/die() with live coroutines: unwind them while the extension
            // is still alive. Shutdown functions run before object destructors
            // (and also on fatal errors, where destructors are skipped), so the
            // coroutines' finally blocks and the flow teardown run
            // deterministically instead of racing the Extension destructor.
            register_shutdown_function(static function (): void {
                try {
                    static::$instance?->shutdown();
                } catch (Throwable) {
                    // A shutdown-path failure must not mask the script's own exit.
                }
            });
        }

        return static::$instance;
    }

    /**
     * Unwinds every live coroutine (FlowStoppedException, like WaitGroup::stop)
     * and stops their flows. Called from the shutdown handler registered in
     * get(): it turns exit()/die() with unfinished coroutines into a
     * deterministic cancellation — finally blocks run, transactions roll back,
     * cursors and flows are released. The results of unfinished tasks are lost
     * either way; finishing or stopping the work explicitly stays the
     * recommended path.
     */
    public function shutdown(): void
    {
        // Collect first: unwinding mutates the registry (stop() detaches members).
        $groups  = [];
        $spawned = [];

        foreach ($this->coroutines as $coroutine) {
            if ($coroutine->group !== null) {
                $groups[spl_object_id($coroutine->group)] = $coroutine->group;
            } else {
                $spawned[] = $coroutine;
            }
        }

        foreach ($groups as $group) {
            try {
                $group->stop();
            } catch (Throwable) {
                // Best-effort: shutdown must reach every remaining group.
            }
        }

        foreach ($spawned as $coroutine) {
            unset($this->coroutines[$coroutine->id]);

            if ($coroutine->fiber->isSuspended()) {
                try {
                    $suspendValue = $coroutine->fiber->throw(new FlowStoppedException(message: 'Flow stopped'));

                    // The pooled worker loop catches the unwind and parks idle
                    // again; recycle the fiber so the scheduler stays fully
                    // usable after a shutdown() call.
                    if ($suspendValue === FiberPoolSignal::Idle) {
                        $this->fiberPool->release($coroutine->fiber);
                    }
                } catch (Throwable) {
                    // The unwinding handler may surface an exception (or fiber
                    // switching may be forbidden in a fatal-error shutdown); it
                    // must not stop the remaining coroutines.
                }
            }

            if ($this->spawnedCount > 0) {
                --$this->spawnedCount;
            }

            // Same rule as forget(): a coroutine that only fired detached
            // (fire-and-forget) pushes never created its flow on the Go side,
            // so the stopFlow crossing is skipped.
            State::deleteFlow(
                flowKey: $coroutine->flowKey,
                stopExtensionFlow: $coroutine->flowUsed,
            );
        }

        $this->groupWaiters       = [];
        $this->switchedCoroutines = [];
        $this->deadlines          = [];
        $this->nearestDeadlineNs  = 0;
        $this->lastSwitchNs       = [];
        $this->readyResults       = [];

        // Queued dispatches belong to coroutines just unwound — drop them. The
        // queued stopFlows still name live Go-side flows: flush the crossings
        // (this is the main stack) so shutdown leaves nothing behind there.
        $this->pendingDispatches = [];

        while ($this->pendingStopFlows !== []) {
            Extension::get()->stopFlow(array_shift($this->pendingStopFlows));
        }
    }

    public function register(Coroutine $coroutine): void
    {
        $this->coroutines[$coroutine->id] = $coroutine;
    }

    /**
     * Runs a callback under a deadline: past it the scheduler unwinds the coroutine where
     * it stands, with CoroutineTimeoutException.
     *
     * The single entry point for bounding work, and Deadline::run() is its public face.
     * Scopes nest and the shorter allowance wins — a scope asking for longer than it is
     * already allowed does not get it, because the outer allowance is a promise someone
     * else is holding — and the previous deadline is put back on the way out.
     *
     * `$timeoutMs` of 0 means no deadline, so the callback runs under whatever bound it is
     * already under, and outside a tracked coroutine there is nothing to unwind, so the
     * callback simply runs.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     *
     * @return TReturn
     */
    public function withDeadline(int $timeoutMs, Closure $callback): mixed
    {
        static::assertTimeout($timeoutMs);

        $fiberId = $timeoutMs === 0 ? null : $this->trackedFiberId();

        if ($fiberId === null) {
            return $callback();
        }

        $previousNs = $this->deadlines[$fiberId] ?? 0;

        $this->enterDeadlineScope(
            fiberId: $fiberId,
            timeoutMs: $timeoutMs,
            previousNs: $previousNs,
        );

        try {
            return $callback();
        } finally {
            $this->leaveDeadlineScope(
                fiberId: $fiberId,
                previousNs: $previousNs,
            );
        }
    }

    /**
     * Gives a coroutine a deadline, counted from now. Past it the scheduler unwinds the
     * coroutine with CoroutineTimeoutException wherever it is — see enforceDeadlines().
     *
     * @internal the scheduler's own launch path (WaitGroup::launch, spawn), where the
     *           coroutine exists but is not running yet, so there is no scope to enter.
     *           Application code bounds work with withDeadline(). The timeout is already
     *           known positive here: add() and spawn() screen it before the coroutine is
     *           built, so nothing throws once a member is registered.
     */
    public function setDeadline(Coroutine $coroutine, int $timeoutMs): void
    {
        $this->rememberDeadline(
            fiberId: $coroutine->id,
            deadlineNs: hrtime(true) + $timeoutMs * 1_000_000,
        );
    }

    /**
     * Spawns a standalone coroutine outside any WaitGroup (fire-and-forget). Used
     * by the server loop to handle each incoming request in its own coroutine.
     * The coroutine gets its own flow, so its async calls run concurrently with
     * everything else and the flow is stopped when it finishes; its return value
     * is not collected. The callback is expected to handle its own errors.
     *
     * The coroutine runs on a fiber from the FiberPool, not a fresh one: the
     * per-request stack lifecycle (page faults on first touch, munmap TLB
     * shootdown) dominated the spawn cost. Completion is signaled by the worker
     * loop parking with FiberPoolSignal::Idle instead of the fiber terminating.
     *
     * $timeoutMs bounds the coroutine, as WaitGroup::add(timeoutMs:) bounds a group
     * member: past it the coroutine is unwound where it stands, and 0 means no deadline.
     * It is how a server gives its handlers a deadline — see docs/coroutine-timeout.md for
     * what the deadline can and cannot reach.
     */
    public function spawn(Closure $callback, int $timeoutMs = 0): void
    {
        static::assertTimeout($timeoutMs);

        $fiber   = $this->fiberPool->acquire();
        $fiberId = spl_object_id($fiber);
        $flowKey = 'sp_' . (++$this->spawnCounter);

        State::registerFiberFlow(
            fiberId: $fiberId,
            flow: new CurrentFlow(
                isAsync: true,
                key: $flowKey,
            ),
        );

        // Inherit the context of whoever spawned us — the current fiber, or the
        // root when spawned outside any fiber (the server loop). Recorded before
        // the resume so the handler's first run already sees the inherited keys.
        State::registerCoroutineContext(
            fiberId: $fiberId,
            parentFiberId: State::currentContextFiberId(),
        );

        $coroutine = new Coroutine(
            id: $fiberId,
            fiber: $fiber,
            group: null,
            callbackKey: '',
            flowKey: $flowKey,
            pooled: true,
        );

        $this->register($coroutine);

        if ($timeoutMs > 0) {
            $this->setDeadline(
                coroutine: $coroutine,
                timeoutMs: $timeoutMs,
            );
        }

        ++$this->spawnedCount;

        try {
            // Hand the job to the pooled worker loop; it runs up to the job's
            // first suspend (its first async call), like a fresh start() did.
            $suspendValue = $fiber->resume($callback);

            $suspendValue = $this->dispatchPendingTask(
                fiber: $fiber,
                fiberId: $fiberId,
                suspendValue: $suspendValue,
            );
        } catch (Throwable) {
            // Groupless: nowhere to report. Clean up and keep the loop alive.
            // The fiber's state is unknown here, so it is not returned to the
            // pool; the pool creates a replacement on demand.
            $this->forget($coroutine);

            return;
        }

        // The worker loop parked idle: the handler finished without awaiting
        // anything (fully synchronous, or only fire-and-forget pushes). Clean
        // up and recycle the fiber immediately.
        if ($suspendValue === FiberPoolSignal::Idle) {
            $this->finishPooled($coroutine);
        }
    }

    /**
     * Removes a coroutine from the registry and returns it (used by
     * WaitGroup::stop to unwind still-suspended fibers).
     */
    public function detach(int $fiberId): ?Coroutine
    {
        $coroutine = $this->coroutines[$fiberId] ?? null;

        unset($this->coroutines[$fiberId], $this->lastSwitchNs[$fiberId]);

        $this->dropDeadline($fiberId);

        $this->purgeSwitchedCoroutine($fiberId);

        return $coroutine;
    }

    /**
     * Whether the caller may still await something.
     *
     * The answer is no in exactly one situation: the code is running on a fiber that
     * carries one of the library's flows and that the scheduler no longer tracks. That
     * happens while a group is being stopped — the members are detached and then unwound,
     * so their finally blocks run on a fiber nothing will resume — and an awaited call
     * there would suspend for ever.
     *
     * Both halves of that predicate matter. Outside a fiber, and on an application's own
     * Fiber, a feature call takes the synchronous path: it blocks on the extension and
     * needs no resume from here, so the answer is yes even though the scheduler knows
     * nothing about the caller. A coroutine unwound by its own deadline is still tracked
     * and can still await: the scheduler is waiting for its result.
     *
     * It is what teardown code needs to know, and it is a question about the runtime
     * rather than about whichever exception happens to be in flight.
     */
    public function canAwait(): bool
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            return true;
        }

        $fiberId = spl_object_id($currentFiber);

        if (isset($this->coroutines[$fiberId])) {
            return true;
        }

        return !State::isAsyncFiber($fiberId);
    }

    public function clearGroupWaiter(string $groupKey): void
    {
        unset($this->groupWaiters[$groupKey]);
    }

    /**
     * Top-level loop: drives the scheduler until the given group has something
     * to yield (a ready result or a failure) or has no live coroutines left.
     * Called only from outside any fiber (the outermost iterate()).
     */
    public function run(WaitGroup $group): void
    {
        while (!$group->hasReadyOrFailure() && $group->isLive()) {
            $this->tick();
        }
    }

    /**
     * Nested case: the current coroutine is blocked in a nested WaitGroup's
     * iterate() waiting for that group to settle. Instead of blocking the
     * thread, record it as the group's waiter and suspend — control returns to
     * the scheduler, which resumes it once the child group is done.
     */
    public function awaitGroup(WaitGroup $group): void
    {
        $current = Fiber::getCurrent();

        if ($current === null) {
            throw new FiberStateException(message: 'awaitGroup called outside of a fiber.');
        }

        // Preemption must not park this coroutine between the waiter
        // registration and the suspend: the wake would land on the switch
        // parking and the real suspend below would then hang forever.
        State::markSuspending(spl_object_id($current));

        // Preemption may have parked this coroutine after the caller's
        // liveness check but before the markSuspending above; while it was
        // parked, the scheduler kept delivering results, so the group may have
        // settled by now — its wake ran with no waiter registered and will
        // never come again. Suspending would hang forever; return instead and
        // let iterate() re-check ready/members.
        if (!$group->isLive() || $group->hasReadyOrFailure()) {
            State::clearSuspending();

            return;
        }

        $this->groupWaiters[$group->key()] = spl_object_id($current);

        try {
            Fiber::suspend();
        } catch (FlowStoppedException $exception) {
            // The group was stopped while this coroutine awaited a nested group;
            // let the unwind propagate so iterate()'s finally can clean up.
            throw $exception;
        } catch (Throwable $exception) {
            throw new FiberStateException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        } finally {
            State::clearSuspending();
        }
    }

    /**
     * Cooperative yield for CPU-bound coroutine code: parks the current coroutine
     * and lets everything that is ready make progress — delivered results resume
     * their coroutines, the server loop keeps accepting new requests — then the
     * coroutine is resumed. Turns "a heavy handler starves all in-flight
     * neighbours" into "a heavy handler adds them at most a quantum of delay".
     * Throughput is unchanged (the PHP thread is still one); this is a latency
     * tool. See .ai/plans/coroutine-switcher.md.
     *
     * Cheap no-op (returns false) outside a fiber, from an untracked fiber, and
     * while the quantum has not elapsed — so the call can sit inside a hot loop:
     * the first call starts the quantum, later calls cost one hrtime() comparison
     * until it runs out. The quantum measures the coroutine's run time since it
     * was last resumed, not wall time since it last parked. $quantumMs <= 0
     * forces a yield on every call.
     */
    public function switch(int $quantumMs = self::DEFAULT_SWITCH_QUANTUM_MS): bool
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            return false;
        }

        $fiberId = spl_object_id($currentFiber);

        if (!isset($this->coroutines[$fiberId])) {
            return false;
        }

        if ($quantumMs > 0) {
            $nowNs        = hrtime(true);
            $lastSwitchNs = $this->lastSwitchNs[$fiberId] ?? null;

            if ($lastSwitchNs === null || (($nowNs - $lastSwitchNs) < ($quantumMs * 1_000_000))) {
                // The first call only starts the quantum; later ones wait it out.
                $this->lastSwitchNs[$fiberId] ??= $nowNs;

                return false;
            }
        }

        // Also guards against a nested preemption landing between here and the
        // suspend (the park itself is a suspend transition).
        State::markSuspending($fiberId);

        try {
            $resumeValue = Fiber::suspend(new PendingSwitchDto());
        } catch (FlowStoppedException $exception) {
            // The coroutine was stopped while parked; let the unwind propagate.
            throw $exception;
        } catch (Throwable $exception) {
            throw new FiberStateException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        } finally {
            State::clearSuspending();
        }

        // The switch queue resumes with null and nothing else may resume a
        // parked coroutine; anything different means the suspend bookkeeping
        // desynchronized — fail loudly instead of continuing on garbage.
        if ($resumeValue !== null) {
            throw new FiberStateException(
                message: 'Unexpected resume value delivered to a switch-parked coroutine.',
            );
        }

        // The quantum counts the coroutine's own run time, so it starts at the
        // resume, not at the park: time spent waiting in the switched queue must
        // not eat the next quantum, or a resumed coroutine would immediately
        // re-park and the CPU share of two identical loops would skew heavily.
        $this->lastSwitchNs[$fiberId] = hrtime(true);

        return true;
    }

    /**
     * The automatic-preemption hook: invoked by the extension's interrupt handler
     * between opcodes while a server has preemption armed (see
     * Extension::armPreemption and .ai/plans/coroutine-switcher.md, phase 2).
     * Force-parks the current coroutine; a no-op outside tracked coroutines (the
     * scheduler's own loop, the sync path), so only handler code is preempted.
     */
    public function preempt(): void
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            return;
        }

        $fiberId = spl_object_id($currentFiber);

        if (!isset($this->coroutines[$fiberId])) {
            return;
        }

        // Never park a coroutine inside a suspend transition (registering a
        // waiter / handing over a pending task): the interleaving desynchronizes
        // the suspend bookkeeping. It is about to yield by itself anyway.
        if (State::isSuspending($fiberId)) {
            return;
        }

        // This hook runs on the coroutine's own stack, which makes it the one place a
        // coroutine busy with PHP can be unwound: a fiber cannot be thrown into while it
        // is running, so enforceDeadlines() leaves this case to here. Without preemption
        // armed there is no such point at all, and a deadline on CPU-bound code is only
        // honoured at its next suspend.
        $deadlineNs = $this->deadlines[$fiberId] ?? 0;

        if ($deadlineNs !== 0 && $deadlineNs <= hrtime(true)) {
            $this->dropDeadline($fiberId);

            throw new CoroutineTimeoutException(message: 'Coroutine timed out');
        }

        $this->switch(quantumMs: 0);
    }

    /**
     * Enables automatic preemption: the extension's timer requests a VM interrupt
     * every $quantumMs and the preempt() hook parks the currently running tracked
     * coroutine, so CPU-bound code — including code that never calls switch() —
     * cannot starve the other coroutines. The convenience wrapper over
     * Extension::armPreemption for CLI scripts and library code; the servers
     * enable it themselves while serving (the preemptionQuantumMs option).
     * Re-enabling replaces the previous timer. Always pair with
     * disablePreemption() (e.g. in finally): the timer keeps firing until then.
     */
    public function enablePreemption(int $quantumMs = self::DEFAULT_SWITCH_QUANTUM_MS): void
    {
        Extension::get()->armPreemption(
            quantumMs: $quantumMs,
            preemptCallback: $this->preempt(...),
        );
    }

    public function disablePreemption(): void
    {
        Extension::get()->disarmPreemption();
    }

    /**
     * Serves a streaming flow whose batches are incoming requests (the HTTP
     * server). Each request is dispatched to a freshly spawned coroutine
     * (spawn-on-request); results of every other flow resume their coroutines.
     * The single wait loop (waitAnyTimeoutBatch, consumed one result per step)
     * multiplexes incoming requests and the async work their handlers do.
     *
     * Graceful shutdown: once $shouldStop() returns true the loop stops accepting
     * new requests and keeps running only to drain the in-flight handlers; when
     * the last one finishes it stops the server flow and returns. The check
     * happens after each delivered result and on every poll timeout.
     *
     * Bounded lifetime: when $maxRequests > 0 the loop starts the same graceful
     * drain once it has dispatched that many requests — a built-in mitigation for
     * handler memory leaks, letting a supervisor respawn a fresh process. The
     * limiting request is dispatched and drained like any in-flight one, and the
     * listener is closed before draining, so no accepted request is bounced.
     *
     * @param int                   $maxRequests         stop after dispatching this many requests (0 = unlimited)
     * @param Closure(string): void $onRequest           receives the raw request payload
     * @param Closure(): bool       $shouldStop          true once a shutdown was requested
     * @param Closure(): void       $onDrainStart        called once when draining begins, before
     *                                                   in-flight handlers finish (e.g. to stop the
     *                                                   listener from accepting so siblings take over)
     * @param Closure(string): void $onShutdownStep      receives a human-readable graceful-shutdown
     *                                                   step (drain begin, fully drained, stopped) for
     *                                                   the caller to log
     * @param int                   $preemptionQuantumMs arm automatic preemption with this quantum
     *                                                   while serving (a worker without a serve loop
     *                                                   does the same through
     *                                                   ServerRuntimeSupportTrait::withPreemption):
     *                                                   the extension's timer requests
     *                                                   a VM interrupt every quantum and preempt()
     *                                                   parks the running handler coroutine, so a
     *                                                   CPU-bound handler cannot starve the others
     *                                                   (0 disables)
     * @param int                   $handlerTimeoutMs    how long one handler coroutine may run before
     *                                                   it is unwound where it stands (0 disables).
     *                                                   Preemption is what lets it reach a handler
     *                                                   that never waits, so the two options work
     *                                                   together
     */
    public function serve(
        string $serverFlowKey,
        string $serverTaskKey,
        int $maxRequests,
        Closure $onRequest,
        Closure $shouldStop,
        Closure $onDrainStart,
        Closure $onShutdownStep,
        int $preemptionQuantumMs = 0,
        int $handlerTimeoutMs = 0,
    ): void {
        // Screened here rather than at the first request: the servers take it from argv,
        // where a negative one is only a typo, and the Go side clamps it to zero. Left to
        // spawn() it would bind the listener, serve nothing, and raise on every request.
        static::assertTimeout($handlerTimeoutMs);

        $draining = false;

        $lastDrainDiagnosticAt = 0.0;

        $dispatchedCount = 0;

        if ($preemptionQuantumMs > 0) {
            $this->enablePreemption(quantumMs: $preemptionQuantumMs);
        }

        // Whatever ends the loop — clean shutdown, a bind error, or an unexpected
        // throwable out of waitAny()/next() — the listener flow must be stopped so
        // it does not leak for the process lifetime.
        try {
            while (true) {
                if (!$draining && ($shouldStop() || ($maxRequests > 0 && $dispatchedCount >= $maxRequests))) {
                    // Stop accepting new requests; keep draining in-flight handlers.
                    $draining = true;

                    $lastDrainDiagnosticAt = microtime(true);

                    $reason = ($maxRequests > 0 && $dispatchedCount >= $maxRequests) ? 'limit' : 'signal';

                    $onShutdownStep(
                        sprintf('stop accepting (reason=%s), draining %d in-flight', $reason, $this->spawnedCount),
                    );

                    // Close the listener up front so the kernel reroutes new
                    // connections to SO_REUSEPORT siblings while we drain.
                    $onDrainStart();
                }

                if ($draining && $this->spawnedCount === 0) {
                    $onShutdownStep('drained all in-flight');

                    break;
                }

                // A handler unwound by its deadline may have been the last in-flight one,
                // so go back and re-check the drain instead of waiting for work.
                if ($this->enforceDeadlines()) {
                    continue;
                }

                // Poll rather than block forever: on an idle server this is the
                // only way the loop notices a shutdown signal (it flips a flag the
                // blocking cgo wait would not return for). A timeout just loops
                // back to re-check shouldStop()/drain above. With coroutines parked
                // by switch() the poll is non-blocking: results keep priority, and
                // a pause with nothing deliverable resumes the queue head. Results
                // arrive in batches (one crossing) and are consumed one per
                // iteration, so the per-event logic below is unchanged.
                $result = $this->takeReadyResult(
                    timeoutMs: $this->boundedByDeadline(
                        $this->switchedCoroutines === [] ? self::SERVE_POLL_INTERVAL_MS : 0,
                    ),
                );

                if ($result === null) {
                    // A stalled drain is otherwise silent: report what the loop
                    // still waits for, so a worker stuck on a lost result is
                    // diagnosable from its log instead of hanging namelessly.
                    if (
                        $draining
                        && (microtime(true) - $lastDrainDiagnosticAt) >= self::DRAIN_DIAGNOSTIC_INTERVAL_SECONDS
                    ) {
                        $lastDrainDiagnosticAt = microtime(true);

                        $onShutdownStep($this->describeDrainStall());
                    }

                    $this->resumeNextSwitched();

                    continue;
                }

                if ($result->flowKey === $serverFlowKey && $result->key === $serverTaskKey) {
                    // The server stream ended. A clean end (e.g. graceful shutdown)
                    // leaves the loop; an error end (e.g. the listener failed to
                    // bind) must surface instead of returning as if it ran fine.
                    if (!$result->hasNext) {
                        if ($result->isError) {
                            throw new TaskErrorException(
                                message: "http server stopped with error: {$result->payload}",
                            );
                        }

                        break;
                    }

                    // While draining, refuse new requests: leave them unhandled so
                    // the Go side answers them 503 when the server flow is stopped
                    // below, instead of running their handlers.
                    if ($draining) {
                        continue;
                    }

                    $payload = $result->payload;

                    ++$dispatchedCount;

                    // No per-event re-arm: the Go side pumps the next stream
                    // event itself for every server (the pull-paced next()
                    // protocol for serve streams no longer exists there).

                    $this->spawn(
                        callback: static function () use ($onRequest, $payload): void {
                            $onRequest($payload);
                        },
                        timeoutMs: $handlerTimeoutMs,
                    );

                    continue;
                }

                $this->resumeByResult($result);
            }
        } finally {
            if ($preemptionQuantumMs > 0) {
                $this->disablePreemption();
            }

            // Stop the listener and abort any connections not yet answered.
            Extension::get()->stopFlow($serverFlowKey);

            // Results still queued from the last drained batch are left in
            // place deliberately: they may belong to live coroutines outside
            // this loop (a group built before serve() and iterated after), and
            // the next scheduler cycle delivers or drops each one correctly.
            // Clearing here would hang such a group; the held memory is at most
            // one batch.

            $onShutdownStep('stopped');
        }
    }

    /**
     * Performs the Go-side push/next for a coroutine that suspended with a
     * pending task (PendingPushDto / PendingNextDto). Runs on the resuming side —
     * the scheduler loop or the code that started the fiber — so the cgo call
     * happens off the coroutine's stack: a fan-out of N live fibers that each
     * crossed the PHP<->Go boundary degrades quadratically (see
     * .ai/plans/async-fan-out-optimization.ru.md).
     *
     * A push failure is thrown back into the coroutine at its suspend point,
     * where it surfaces as TaskExecutionException; the coroutine may catch it
     * and suspend with another pending task, hence the loop. Whatever escapes
     * the coroutine propagates to the caller like any start()/resume() failure.
     * A suspend without a pending task (e.g. awaitGroup) is left untouched.
     *
     * Returns the final suspend value — the one the dispatch loop stopped on.
     * The fire-and-forget path resumes the coroutine internally, so the value
     * the caller originally saw may be stale by now; a pooled coroutine's
     * completion (FiberPoolSignal::Idle) is only visible in this returned value.
     */
    public function dispatchPendingTask(Fiber $fiber, int $fiberId, mixed $suspendValue): mixed
    {
        // On a fiber stack (a nested WaitGroup::add / spawn), don't perform the
        // cgo call here: entering Go with the stack pointer inside a fiber stack
        // makes the runtime re-derive its system-stack bounds through a full
        // /proc/self/maps read (see $pendingDispatches). Queue the dispatch for
        // the scheduler loop, which drains it on the main C stack before its
        // next wait crossing. No result can arrive before the push happens, so
        // deferring opens no routing window. An unregistered fiber cannot be
        // queued (the queue is keyed by Coroutine) — it falls through and
        // dispatches inline, which is correct, just not deferred.
        if (
            Fiber::getCurrent() !== null
            && ($suspendValue instanceof PendingPushDto || $suspendValue instanceof PendingNextDto)
            && ($queuedCoroutine = $this->coroutines[$fiberId] ?? null) !== null
        ) {
            $this->pendingDispatches[] = [$queuedCoroutine, $suspendValue];

            return $suspendValue;
        }

        // The dispatch may run on a coroutine's stack (a nested WaitGroup::add
        // starts members from inside the parent coroutine). Preempting the
        // caller between push() and the awaited-keys bookkeeping would let the
        // task's result arrive with no owner mapping, so the whole dispatch is a suspend
        // transition for the calling fiber (no-op outside fibers).
        $callingFiber = Fiber::getCurrent();

        if ($callingFiber !== null) {
            State::markSuspending(spl_object_id($callingFiber));
        }

        try {
            while (true) {
                if ($suspendValue instanceof PendingSwitchDto) {
                    $this->switchedCoroutines[] = $fiberId;

                    return $suspendValue;
                }

                if (!($suspendValue instanceof PendingPushDto) && !($suspendValue instanceof PendingNextDto)) {
                    return $suspendValue;
                }

                try {
                    if ($suspendValue instanceof PendingPushDto) {
                        // A fire-and-forget push is detached: the empty flow key
                        // tells the Go side to run the task without a flow (no
                        // stopFlow crossing will follow), and no owner is set —
                        // no result will ever come.
                        $runningTask = Extension::get()->push(
                            flowKey: $suspendValue->awaitResult ? $suspendValue->flowKey : '',
                            payload: $suspendValue->payload,
                            ownerFiberId: $suspendValue->awaitResult ? $fiberId : 0,
                        );
                    } else {
                        $runningTask = Extension::get()->next(
                            flowKey: $suspendValue->flowKey,
                            taskKey: $suspendValue->taskKey,
                            ownerFiberId: $fiberId,
                        );
                    }
                } catch (Throwable $exception) {
                    $suspendValue = $fiber->throw($exception);

                    // The throw ran the coroutine's handlers, whose own suspend
                    // transitions may have cleared the window — re-assert it for
                    // the next loop iteration.
                    if ($callingFiber !== null) {
                        State::markSuspending(spl_object_id($callingFiber));
                    }

                    continue;
                }

                // Fire-and-forget push (execNoResult): no result will ever come
                // to resume this fiber — resume it here and keep dispatching
                // whatever it suspends with next.
                if ($suspendValue instanceof PendingPushDto && !$suspendValue->awaitResult) {
                    $suspendValue = $fiber->resume(null);

                    if ($callingFiber !== null) {
                        State::markSuspending(spl_object_id($callingFiber));
                    }

                    continue;
                }

                // The result routes back via the frame-carried ownerFiberId; the
                // awaited keys stored on the coroutine validate it (spl_object_id
                // reuse safety), and flowUsed records that this coroutine's flow
                // exists on the Go side and needs a stopFlow at cleanup.
                $coroutine = $this->coroutines[$fiberId] ?? null;

                if ($coroutine !== null) {
                    $coroutine->awaitedFlowKey = $suspendValue->flowKey;
                    $coroutine->awaitedTaskKey = $runningTask->key;
                    $coroutine->flowUsed       = true;
                }

                return $suspendValue;
            }
        } finally {
            if ($callingFiber !== null) {
                State::clearSuspending();
            }
        }
    }

    /**
     * How many delivered results were dropped for having no owner. A non-zero
     * value is normal in stop/drain scenarios (a batch crosses the boundary
     * before the stop lands), but a steadily growing value with no stops in
     * sight is the signature of a routing desync — the silent drop is exactly
     * where such a bug would otherwise hide.
     */
    public function droppedResultsCount(): int
    {
        return $this->droppedResultsCount;
    }

    /**
     * Queues a Go-side stopFlow to run on the scheduler's main stack instead of
     * the current fiber's (see $pendingStopFlows). Called by State::deleteFlow
     * when a flow is deleted from inside a fiber. Flow keys are never reused,
     * so a deferred stop can only ever target its own flow.
     */
    public function deferStopFlow(string $flowKey): void
    {
        $this->pendingStopFlows[] = $flowKey;
    }

    /**
     * Screens a timeout the caller asked for. 0 is the whole library's way of saying "no
     * deadline", so only a negative one is a mistake — and it is screened before anything
     * is built, so a refusal never leaves a half-registered coroutine behind.
     */
    public static function assertTimeout(int $timeoutMs): void
    {
        if ($timeoutMs < 0) {
            throw new InvalidCoroutineTimeoutException(
                message: "A coroutine timeout cannot be negative, got $timeoutMs ms.",
            );
        }
    }

    /**
     * The id of the coroutine running right now, or null when there is none to bound —
     * outside a fiber, or on a fiber the scheduler does not track.
     */
    protected function trackedFiberId(): ?int
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            return null;
        }

        $fiberId = spl_object_id($currentFiber);

        return isset($this->coroutines[$fiberId]) ? $fiberId : null;
    }

    /**
     * Opens a deadline scope on a coroutine, keeping the shorter of the new allowance and
     * the one it is already under.
     */
    protected function enterDeadlineScope(int $fiberId, int $timeoutMs, int $previousNs): void
    {
        $deadlineNs = hrtime(true) + $timeoutMs * 1_000_000;

        if ($previousNs !== 0 && $previousNs < $deadlineNs) {
            $deadlineNs = $previousNs;
        }

        $this->rememberDeadline(
            fiberId: $fiberId,
            deadlineNs: $deadlineNs,
        );
    }

    /**
     * Closes a deadline scope, putting back the deadline it replaced.
     *
     * A previous deadline that has already passed is dropped rather than put back. It is
     * the case where an inner scope was cut short by an allowance it shares with the outer
     * one — asking for ten seconds inside a scope holding one gives both the same instant —
     * and re-arming it would deliver a second CoroutineTimeoutException into the very
     * cleanup the first one started. A deadline fires once.
     *
     * A scope also closes on the way out of a coroutine the scheduler has already let go
     * of, and then there is nothing to put the deadline back on: WaitGroup::stop() detaches
     * a member before it throws into it, so this runs during an unwind that nobody is
     * waiting on any more. Writing the entry back there would outlive the fiber, and the
     * index is keyed by fiber id — an id PHP hands to the next fiber it allocates, which
     * would be unwound at an instant it never asked for.
     */
    protected function leaveDeadlineScope(int $fiberId, int $previousNs): void
    {
        if (!isset($this->coroutines[$fiberId])) {
            return;
        }

        if ($previousNs === 0 || $previousNs <= hrtime(true)) {
            $this->dropDeadline($fiberId);

            return;
        }

        $this->rememberDeadline(
            fiberId: $fiberId,
            deadlineNs: $previousNs,
        );
    }

    /**
     * Records a coroutine's deadline and keeps the nearest-deadline cache a lower bound of
     * the index (see $nearestDeadlineNs).
     */
    protected function rememberDeadline(int $fiberId, int $deadlineNs): void
    {
        $this->deadlines[$fiberId] = $deadlineNs;

        if ($this->nearestDeadlineNs === 0 || $deadlineNs < $this->nearestDeadlineNs) {
            $this->nearestDeadlineNs = $deadlineNs;
        }
    }

    /**
     * Forgets one coroutine's deadline. The cache is left as it is unless the index empties:
     * a value that is no longer in the index is still a lower bound of what is, and the next
     * sweep replaces it with the exact one.
     */
    protected function dropDeadline(int $fiberId): void
    {
        unset($this->deadlines[$fiberId]);

        if ($this->deadlines === []) {
            $this->nearestDeadlineNs = 0;
        }
    }

    /**
     * One-line snapshot of why a graceful drain is not finishing: the tracked
     * coroutines with their fiber state, the group waiters, the switch queue and
     * the task keys coroutines are parked on. Both lists are capped — this goes
     * to a log line every few seconds, not to a dump.
     */
    protected function describeDrainStall(): string
    {
        $coroutineStates = [];
        $parkedTaskKeys  = [];

        foreach ($this->coroutines as $trackedCoroutine) {
            // The awaited task key already carries its flow ("flowKey:counter",
            // see Extension::push), so it is printed as is.
            if ($trackedCoroutine->awaitedTaskKey !== '') {
                $parkedTaskKeys[] = $trackedCoroutine->awaitedTaskKey;
            }

            $fiberState = $trackedCoroutine->fiber->isTerminated()
                ? 'terminated'
                : ($trackedCoroutine->fiber->isSuspended() ? 'suspended' : 'running');

            $coroutineStates[] = sprintf(
                '%s%s=%s',
                $trackedCoroutine->flowKey !== '' ? $trackedCoroutine->flowKey : 'member',
                $trackedCoroutine->callbackKey !== '' ? '/' . $trackedCoroutine->callbackKey : '',
                $fiberState,
            );

            if (count($coroutineStates) >= self::DRAIN_DIAGNOSTIC_MAX_COROUTINES) {
                break;
            }
        }

        return sprintf(
            'drain stalled: %d in-flight, %d go tasks, %d dropped, tracked=[%s], waiters=[%s], switched=%d, parked on [%s]',
            $this->spawnedCount,
            Extension::get()->count(),
            $this->droppedResultsCount,
            implode(', ', $coroutineStates),
            implode(', ', array_keys($this->groupWaiters)),
            count($this->switchedCoroutines),
            implode(', ', $parkedTaskKeys),
        );
    }

    /**
     * Drops a fiber id from the switched queue when its coroutine leaves the
     * scheduler. Required, not cosmetic: spl_object_id is reused once the fiber
     * is freed, so a stale queue entry could later match a brand-new coroutine
     * parked on a task result and resume it with null out of turn.
     */
    protected function purgeSwitchedCoroutine(int $fiberId): void
    {
        $queueIndex = array_search($fiberId, $this->switchedCoroutines, true);

        if ($queueIndex !== false) {
            unset($this->switchedCoroutines[$queueIndex]);
        }
    }

    /**
     * Drops the dispatches one coroutine queued from a fiber stack but that must no longer
     * be sent. Needed only where the coroutine keeps its registry entry across the unwind
     * (expire); every other exit — detach, forget — removes it, and the identity check in
     * drainPendingDispatches skips its queued work on its own.
     */
    protected function purgePendingDispatches(Coroutine $coroutine): void
    {
        if ($this->pendingDispatches === []) {
            return;
        }

        $kept = [];

        foreach ($this->pendingDispatches as $queued) {
            if ($queued[0] !== $coroutine) {
                $kept[] = $queued;
            }
        }

        $this->pendingDispatches = $kept;
    }

    /**
     * One scheduler step: take the next ready result and resume the coroutine it
     * belongs to. With coroutines parked by switch() the step never blocks: a
     * deliverable result keeps priority, an empty poll resumes the queue head
     * instead.
     */
    protected function tick(): void
    {
        if ($this->enforceDeadlines()) {
            // Something settled. Back to the caller so it can see it, rather than into a
            // wait for work that may no longer be needed.
            return;
        }

        $result = $this->takeReadyResult(
            timeoutMs: $this->boundedByDeadline(
                $this->switchedCoroutines === [] ? null : 0,
            ),
        );

        if ($result !== null) {
            $this->resumeByResult($result);

            return;
        }

        $this->resumeNextSwitched();
    }

    /**
     * Takes the next ready result: from the local queue first, otherwise by
     * draining a fresh batch from the extension in one cgo crossing
     * (Extension::waitAnyBatch). $timeoutMs bounds the wait for the batch's
     * first result (null = block indefinitely); null is returned only on a
     * timeout with an empty queue.
     */
    protected function takeReadyResult(?int $timeoutMs): ?TaskResultDto
    {
        // Fiber-stack work deferred to this (main) stack runs first: a blocking
        // wait below would otherwise wait for results of pushes that were never
        // sent. The emptiness test is deliberately duplicated here (the drain
        // loops re-check it): both queues are empty on every iteration of the
        // hot serve loop, and this saves the call.
        if ($this->pendingDispatches !== [] || $this->pendingStopFlows !== []) {
            $this->drainPendingDispatches();
        }

        if ($this->readyResults !== []) {
            return array_shift($this->readyResults);
        }

        if ($timeoutMs === null) {
            $this->readyResults = Extension::get()->waitAnyBatch(self::WAIT_BATCH_SIZE);
        } else {
            $results = Extension::get()->waitAnyTimeoutBatch(
                timeoutMs: $timeoutMs,
                maxResults: self::WAIT_BATCH_SIZE,
            );

            if ($results === null) {
                return null;
            }

            $this->readyResults = $results;
        }

        return array_shift($this->readyResults);
    }

    /**
     * Unwinds every coroutine whose deadline has passed, wherever it is parked.
     *
     * Called from the two loops that drive the scheduler, before they decide what to do
     * next. A coroutine that is *running* is not touched here — it cannot be, a fiber can
     * only be thrown into while suspended — that one is caught by the preemption hook,
     * which executes on its own stack (see preempt()).
     *
     * Nothing is scanned until the cached nearest deadline says something may have expired,
     * so the usual turn costs one comparison. The full pass then ends by recomputing that
     * cache from what is left, which is what keeps a stale lower bound from lasting.
     *
     * The expired ones are collected as objects, and before any of them is unwound:
     * unwinding runs the coroutine's own code, which may finish it, launch its group's next
     * queued callback and change this very index underneath the loop — and a fiber id freed
     * in the middle of the pass may already name a different coroutine by the time the pass
     * reaches it, which is why each one is re-checked by identity.
     *
     * Answers whether anything was unwound, because that changes what the caller should do
     * next: a coroutine that just settled may be the result its group was waiting for, and
     * blocking for the next one before the caller re-checks would sit on an answer it
     * already has.
     */
    protected function enforceDeadlines(): bool
    {
        $nowNs = hrtime(true);

        if ($this->nearestDeadlineNs === 0 || $this->nearestDeadlineNs > $nowNs) {
            return false;
        }

        $expired = [];

        foreach ($this->deadlines as $fiberId => $deadlineNs) {
            if ($deadlineNs > $nowNs) {
                continue;
            }

            unset($this->deadlines[$fiberId]);

            $coroutine = $this->coroutines[$fiberId] ?? null;

            if ($coroutine !== null) {
                $expired[] = $coroutine;
            }
        }

        $unwound = false;

        foreach ($expired as $coroutine) {
            if (($this->coroutines[$coroutine->id] ?? null) !== $coroutine) {
                continue;
            }

            // A coroutine that is not parked cannot be thrown into. Its entry goes back
            // into the index rather than being dropped with the pass — losing it would
            // leave the coroutine running unbounded from here on, with nothing left to
            // fire. The next sweep finds it parked and unwinds it then; preempt() gets to
            // it sooner if the coroutine is busy with PHP.
            if (!$coroutine->fiber->isSuspended()) {
                $this->rememberDeadline(
                    fiberId: $coroutine->id,
                    deadlineNs: $nowNs,
                );

                continue;
            }

            $this->expire($coroutine);

            $unwound = true;
        }

        $this->nearestDeadlineNs = $this->deadlines === [] ? 0 : min($this->deadlines);

        return $unwound;
    }

    /**
     * Unwinds one coroutine that ran out of time, and routes whatever comes of it to its
     * group exactly as a normal resume would.
     *
     * That last part is the whole point of going through resumeCoroutine() rather than
     * calling fiber->throw() directly: a callback that catches the timeout and returns a
     * value has settled, and its group must receive that value. Only a callback that lets
     * the exception escape fails.
     */
    protected function expire(Coroutine $coroutine): void
    {
        // Whatever it was waiting for is no longer its business. Clearing the keys is what
        // makes the result that arrives later a stale one, which resumeByResult drops
        // instead of resuming a coroutine that has already moved on.
        $coroutine->awaitedFlowKey = '';
        $coroutine->awaitedTaskKey = '';

        // A coroutine parked by switch() is queued for a resume that must not also happen.
        $this->purgeSwitchedCoroutine($coroutine->id);

        // Nor may a push it queued from a fiber stack still go out. The coroutine stays
        // registered through its own unwinding — that is the point, it has to run its
        // finally blocks — so the identity check in drainPendingDispatches would let the
        // stale push through, and it would overwrite the awaited keys of whatever the
        // coroutine asked for next inside its catch. Its result would then wake the
        // coroutine in place of the answer it is actually waiting for.
        $this->purgePendingDispatches($coroutine);

        $this->resumeCoroutine(
            coroutine: $coroutine,
            resumeValue: null,
            throwable: new CoroutineTimeoutException(message: 'Coroutine timed out'),
        );
    }

    /**
     * How long the scheduler may block waiting for results, given the deadlines it has to
     * honour: the caller's own bound, or the time left on the nearest deadline, whichever
     * comes first.
     *
     * Without this a scheduler with nothing to do blocks in the extension's wait until a
     * result arrives — and a coroutine waiting on a socket nobody answers is exactly the
     * case a deadline is for, so the wait has to end on its own.
     */
    protected function boundedByDeadline(?int $timeoutMs): ?int
    {
        if ($this->nearestDeadlineNs === 0) {
            return $timeoutMs;
        }

        $leftMs = (int) ceil(($this->nearestDeadlineNs - hrtime(true)) / 1_000_000);

        // Already past it, or so close that rounding says zero: come straight back and let
        // enforceDeadlines() do its work.
        if ($leftMs <= 0) {
            return 0;
        }

        return $timeoutMs === null ? $leftMs : min($timeoutMs, $leftMs);
    }

    /**
     * Resumes the oldest coroutine parked by switch(). No-op on an empty queue.
     * Unwound coroutines are purged from the queue eagerly (detach/forget); the
     * skip below is a defensive net for an id that slipped through anyway.
     */
    protected function resumeNextSwitched(): void
    {
        while ($this->switchedCoroutines !== []) {
            $fiberId = array_shift($this->switchedCoroutines);

            $coroutine = $this->coroutines[$fiberId] ?? null;

            if ($coroutine === null) {
                continue;
            }

            $this->resumeCoroutine($coroutine, null);

            return;
        }
    }

    /**
     * Routes a delivered result to the coroutine that issued its task, by the
     * frame-carried ownerFiberId (set at push time). The stored awaited keys
     * must match exactly: spl_object_id is reused once a fiber is freed, so a
     * stale result whose owner id now belongs to a different coroutine must be
     * dropped, not delivered out of turn. A result with no live matching owner
     * is dropped silently — it is a legitimate leftover, not a desync: results
     * arrive in batches, and handling an earlier result of the same batch may
     * have stopped this result's flow (WaitGroup::stop, shutdown, a server
     * drain). The Go side filters such results at delivery, but a batch crosses
     * the boundary before the stop happens.
     */
    protected function resumeByResult(TaskResultDto $result): void
    {
        $coroutine = $this->coroutines[$result->ownerFiberId] ?? null;

        if (
            $coroutine === null
            || $coroutine->awaitedTaskKey !== $result->key
            || $coroutine->awaitedFlowKey !== $result->flowKey
        ) {
            ++$this->droppedResultsCount;

            return;
        }

        $coroutine->awaitedFlowKey = '';
        $coroutine->awaitedTaskKey = '';

        $this->resumeCoroutine($coroutine, $result);
    }

    /**
     * Resumes a coroutine and routes the outcome — completion or failure — to
     * its owning group. Never lets the throwable escape up the scheduler stack:
     * a coroutine's failure belongs to its group (and its waiter), not to
     * whichever group's run() happens to be on the stack.
     */
    protected function resumeCoroutine(Coroutine $coroutine, mixed $resumeValue, ?Throwable $throwable = null): void
    {
        try {
            $suspendValue = $throwable === null
                ? $coroutine->fiber->resume($resumeValue)
                : $coroutine->fiber->throw($throwable);

            $suspendValue = $this->dispatchPendingTask(
                fiber: $coroutine->fiber,
                fiberId: $coroutine->id,
                suspendValue: $suspendValue,
            );
        } catch (Throwable $exception) {
            $this->failCoroutine($coroutine, $exception);

            return;
        }

        $this->settleDispatched($coroutine, $suspendValue);
    }

    /**
     * Routes a coroutine's post-dispatch state to its completion path: a pooled
     * fiber parking idle goes back to the pool, a terminated group member
     * reports to its group. A coroutine that suspended again on its next task
     * is left parked — the next delivered result resumes it. Shared by
     * resumeCoroutine and drainPendingDispatches (a drained fire-and-forget
     * push resumes its coroutine internally and may run it to completion).
     */
    protected function settleDispatched(Coroutine $coroutine, mixed $suspendValue): void
    {
        // A pooled fiber never terminates: its worker loop parking idle is the
        // completion signal.
        if ($coroutine->pooled) {
            if ($suspendValue === FiberPoolSignal::Idle) {
                $this->finishPooled($coroutine);
            }

            return;
        }

        if ($coroutine->fiber->isTerminated()) {
            $this->completeCoroutine($coroutine);
        }
    }

    /**
     * Performs the dispatches and stopFlow crossings queued from fiber stacks,
     * now that execution is on the main C stack (see $pendingDispatches). A
     * dispatch may itself queue more work (a fire-and-forget push resumes its
     * coroutine, which may spawn or stop a nested group), so both queues drain
     * to empty. Failure handling mirrors resumeCoroutine: whatever escapes the
     * coroutine goes to failCoroutine, never up the scheduler stack.
     */
    protected function drainPendingDispatches(): void
    {
        while ($this->pendingDispatches !== []) {
            [$coroutine, $pendingTask] = array_shift($this->pendingDispatches);

            // The coroutine may have been detached (group stop, shutdown) while
            // its push waited in the queue. Compared by identity, not by id or
            // by fiber: spl_object_id is reused once a fiber is freed, and a
            // pooled fiber outlives its coroutine by design — the same Fiber
            // object, under the same id, may already serve the next request.
            if (($this->coroutines[$coroutine->id] ?? null) !== $coroutine) {
                continue;
            }

            try {
                $suspendValue = $this->dispatchPendingTask(
                    fiber: $coroutine->fiber,
                    fiberId: $coroutine->id,
                    suspendValue: $pendingTask,
                );
            } catch (Throwable $exception) {
                $this->failCoroutine($coroutine, $exception);

                continue;
            }

            $this->settleDispatched($coroutine, $suspendValue);
        }

        while ($this->pendingStopFlows !== []) {
            Extension::get()->stopFlow(array_shift($this->pendingStopFlows));
        }
    }

    /**
     * Completion of a pooled (spawned) coroutine: the same cleanup as forget()
     * — the flow, the registries and the coroutine context are all keyed by the
     * fiber id and released there — plus the fiber goes back to the pool for
     * the next request.
     */
    protected function finishPooled(Coroutine $coroutine): void
    {
        $this->forget($coroutine);

        $this->fiberPool->release($coroutine->fiber);
    }

    protected function completeCoroutine(Coroutine $coroutine): void
    {
        $coroutine->group?->markReady($coroutine->callbackKey, $coroutine->fiber->getReturn());

        $this->forget($coroutine);

        if ($coroutine->group !== null && !$coroutine->group->isLive()) {
            $this->wakeGroupWaiters($coroutine->group);
        }
    }

    protected function failCoroutine(Coroutine $coroutine, Throwable $exception): void
    {
        $this->forget($coroutine);

        // Spawned (groupless) coroutine: there is no group to report to. The
        // spawn caller (e.g. the HTTP request wrapper) is expected to catch its
        // own errors; reaching here means it didn't, so we drop the failure
        // rather than crash the scheduler loop serving other coroutines.
        if ($coroutine->group === null) {
            return;
        }

        // A deliberate unwind is passed on as it is. Wrapping it would hide what happened
        // behind a name that says the callback failed, and the project keeps a stop
        // recognizable everywhere else it can surface (FeatureExecutor::suspend does the
        // same). It reaches here only when a callback let it escape — a group being
        // stopped unwinds its members itself and swallows what comes out — so in practice
        // this is a coroutine that ran out of its deadline and did not catch it.
        $coroutine->group->markFailure(
            $exception instanceof FlowStoppedException
                ? $exception
                : new CallbackExecutionException(
                    message: $exception->getMessage(),
                    previous: $exception,
                ),
        );

        // Wake whoever awaits this group so the failure surfaces at its iterate()
        // (or so the top-level run() observes it on the next loop check).
        $this->wakeGroupWaiters($coroutine->group);
    }

    protected function forget(Coroutine $coroutine): void
    {
        unset($this->coroutines[$coroutine->id], $this->lastSwitchNs[$coroutine->id]);

        $this->dropDeadline($coroutine->id);

        $this->purgeSwitchedCoroutine($coroutine->id);

        if ($coroutine->group !== null) {
            State::unRegisterFiber($coroutine->id);

            $coroutine->group->removeMember($coroutine->id);

            // This member freed a slot: let the group launch the next queued
            // coroutine (if any). Keeping launch in the scheduler preserves the
            // invariant that coroutines are only ever started/resumed from here.
            $coroutine->group->fillSlots();

            return;
        }

        // Spawned coroutine owns a per-coroutine flow; stop it (Go side + State),
        // which also unregisters the fiber. A coroutine that only fired detached
        // (fire-and-forget) pushes never created its flow on the Go side, so the
        // stopFlow crossing is skipped and only the PHP registries are cleaned.
        --$this->spawnedCount;

        State::deleteFlow(
            flowKey: $coroutine->flowKey,
            stopExtensionFlow: $coroutine->flowUsed,
        );
    }

    protected function wakeGroupWaiters(WaitGroup $group): void
    {
        $waiterId = $this->groupWaiters[$group->key()] ?? null;

        if ($waiterId === null) {
            return;
        }

        unset($this->groupWaiters[$group->key()]);

        $waiter = $this->coroutines[$waiterId] ?? null;

        if ($waiter === null) {
            return;
        }

        $this->resumeCoroutine($waiter, null);
    }
}
