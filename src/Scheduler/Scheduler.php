<?php

declare(strict_types=1);

namespace SConcur\Scheduler;

use Closure;
use Fiber;
use SConcur\Connection\Extension;
use SConcur\Dto\PendingNextDto;
use SConcur\Dto\PendingPushDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\CallbackExecutionException;
use SConcur\Exceptions\FiberStateException;
use SConcur\Exceptions\FlowStoppedException;
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

    protected static ?Scheduler $instance = null;

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
     * Monotonic counter feeding spawned-coroutine flow keys. A flow key only has
     * to be unique among live flows in this process, so a never-reused counter is
     * enough — and far cheaper than uniqid() on the per-request hot path.
     */
    protected int $spawnCounter = 0;

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
                    $coroutine->fiber->throw(new FlowStoppedException(message: 'Flow stopped'));
                } catch (Throwable) {
                    // The unwinding handler may surface an exception (or fiber
                    // switching may be forbidden in a fatal-error shutdown); it
                    // must not stop the remaining coroutines.
                }
            }

            if ($this->spawnedCount > 0) {
                --$this->spawnedCount;
            }

            State::deleteFlow($coroutine->flowKey);
        }

        $this->groupWaiters = [];
    }

    public function register(Coroutine $coroutine): void
    {
        $this->coroutines[$coroutine->id] = $coroutine;
    }

    /**
     * Spawns a standalone coroutine outside any WaitGroup (fire-and-forget). Used
     * by the server loop to handle each incoming request in its own coroutine.
     * The coroutine gets its own flow, so its async calls run concurrently with
     * everything else and the flow is stopped when it finishes; its return value
     * is not collected. The callback is expected to handle its own errors.
     */
    public function spawn(Closure $callback): void
    {
        $fiber   = new Fiber($callback);
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
        // start() so the handler's first run already sees the inherited keys.
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
        );

        $this->register($coroutine);

        ++$this->spawnedCount;

        try {
            // Run up to the first suspend (its first async call), like WaitGroup::add.
            $suspendValue = $fiber->start();

            $this->dispatchPendingTask(
                fiber: $fiber,
                fiberId: $fiberId,
                suspendValue: $suspendValue,
            );
        } catch (Throwable) {
            // Groupless: nowhere to report. Clean up and keep the loop alive.
            $this->forget($coroutine);

            return;
        }

        // Fully synchronous handler: nothing to wait for, clean up immediately.
        if ($fiber->isTerminated()) {
            $this->forget($coroutine);
        }
    }

    /**
     * Removes a coroutine from the registry and returns it (used by
     * WaitGroup::stop to unwind still-suspended fibers).
     */
    public function detach(int $fiberId): ?Coroutine
    {
        $coroutine = $this->coroutines[$fiberId] ?? null;

        unset($this->coroutines[$fiberId]);

        return $coroutine;
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
        }
    }

    /**
     * Serves a streaming flow whose batches are incoming requests (the HTTP
     * server). Each request is dispatched to a freshly spawned coroutine
     * (spawn-on-request); results of every other flow resume their coroutines.
     * The single waitAny loop multiplexes incoming requests and the async work
     * their handlers do.
     *
     * Graceful shutdown: once $shouldStop() returns true the loop stops accepting
     * new requests and keeps running only to drain the in-flight handlers; when
     * the last one finishes it stops the server flow and returns. The check
     * happens after each delivered result, so on an idle server shutdown takes
     * effect on the next event (a bounded waitAny will make it immediate later).
     *
     * Bounded lifetime: when $maxRequests > 0 the loop starts the same graceful
     * drain once it has dispatched that many requests — a built-in mitigation for
     * handler memory leaks, letting a supervisor respawn a fresh process. The
     * limiting request is dispatched and drained like any in-flight one, and the
     * listener is closed before draining, so no accepted request is bounced.
     *
     * @param int                   $maxRequests    stop after dispatching this many requests (0 = unlimited)
     * @param Closure(string): void $onRequest      receives the raw request payload
     * @param Closure(): bool       $shouldStop     true once a shutdown was requested
     * @param Closure(): void       $onDrainStart   called once when draining begins, before
     *                                              in-flight handlers finish (e.g. to stop the
     *                                              listener from accepting so siblings take over)
     * @param Closure(string): void $onShutdownStep receives a human-readable graceful-shutdown
     *                                              step (drain begin, fully drained, stopped) for
     *                                              the caller to log
     */
    public function serve(
        string $serverFlowKey,
        string $serverTaskKey,
        int $maxRequests,
        Closure $onRequest,
        Closure $shouldStop,
        Closure $onDrainStart,
        Closure $onShutdownStep,
    ): void {
        $draining = false;

        $dispatchedCount = 0;

        // Whatever ends the loop — clean shutdown, a bind error, or an unexpected
        // throwable out of waitAny()/next() — the listener flow must be stopped so
        // it does not leak for the process lifetime.
        try {
            while (true) {
                if (!$draining && ($shouldStop() || ($maxRequests > 0 && $dispatchedCount >= $maxRequests))) {
                    // Stop accepting new requests; keep draining in-flight handlers.
                    $draining = true;

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

                // Poll rather than block forever: on an idle server this is the
                // only way the loop notices a shutdown signal (it flips a flag the
                // blocking cgo waitAny would not return for). A timeout just loops
                // back to re-check shouldStop()/drain above.
                $result = Extension::get()->waitAnyTimeout(self::SERVE_POLL_INTERVAL_MS);

                if ($result === null) {
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

                    // Re-arm for the next request before handling this one, so the
                    // listener keeps accepting while the handler runs — unless this
                    // request hit the maxRequests limit, in which case we do not pull
                    // one more (we drain on the next tick instead of bouncing it 503).
                    if ($maxRequests === 0 || $dispatchedCount < $maxRequests) {
                        Extension::get()->next(
                            flowKey: $serverFlowKey,
                            taskKey: $serverTaskKey,
                        );
                    }

                    $this->spawn(static function () use ($onRequest, $payload): void {
                        $onRequest($payload);
                    });

                    continue;
                }

                $this->resumeByResult($result);
            }
        } finally {
            // Stop the listener and abort any connections not yet answered.
            Extension::get()->stopFlow($serverFlowKey);

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
     */
    public function dispatchPendingTask(Fiber $fiber, int $fiberId, mixed $suspendValue): void
    {
        while ($suspendValue instanceof PendingPushDto || $suspendValue instanceof PendingNextDto) {
            try {
                if ($suspendValue instanceof PendingPushDto) {
                    $runningTask = Extension::get()->push(
                        flowKey: $suspendValue->flowKey,
                        payload: $suspendValue->payload,
                    );
                } else {
                    $runningTask = Extension::get()->next(
                        flowKey: $suspendValue->flowKey,
                        taskKey: $suspendValue->taskKey,
                    );
                }
            } catch (Throwable $exception) {
                $suspendValue = $fiber->throw($exception);

                continue;
            }

            State::addFiberTask(
                flowKey: $suspendValue->flowKey,
                taskKey: $runningTask->key,
                fiberId: $fiberId,
            );

            return;
        }
    }

    /**
     * One scheduler step: take the first ready result of any flow and resume the
     * coroutine it belongs to.
     */
    protected function tick(): void
    {
        $this->resumeByResult(Extension::get()->waitAny());
    }

    /**
     * Routes a delivered result to the coroutine that issued its task.
     */
    protected function resumeByResult(TaskResultDto $result): void
    {
        $fiberId = State::pullFiberByTask(
            flowKey: $result->flowKey,
            taskKey: $result->key,
        );

        if ($fiberId === null) {
            throw new FiberStateException(
                message: "No coroutine for result [flow: {$result->flowKey}, task: {$result->key}].",
            );
        }

        $coroutine = $this->coroutines[$fiberId] ?? null;

        if ($coroutine === null) {
            throw new FiberStateException(
                message: "Coroutine [id: {$fiberId}] not found for delivered result.",
            );
        }

        $this->resumeCoroutine($coroutine, $result);
    }

    /**
     * Resumes a coroutine and routes the outcome — completion or failure — to
     * its owning group. Never lets the throwable escape up the scheduler stack:
     * a coroutine's failure belongs to its group (and its waiter), not to
     * whichever group's run() happens to be on the stack.
     */
    protected function resumeCoroutine(Coroutine $coroutine, mixed $resumeValue): void
    {
        try {
            $suspendValue = $coroutine->fiber->resume($resumeValue);

            $this->dispatchPendingTask(
                fiber: $coroutine->fiber,
                fiberId: $coroutine->id,
                suspendValue: $suspendValue,
            );
        } catch (Throwable $exception) {
            $this->failCoroutine($coroutine, $exception);

            return;
        }

        if ($coroutine->fiber->isTerminated()) {
            $this->completeCoroutine($coroutine);
        }
        // Otherwise the coroutine suspended again on its next task — the next
        // tick will deliver that result.
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

        $coroutine->group->markFailure(
            new CallbackExecutionException(
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
        unset($this->coroutines[$coroutine->id]);

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
        // which also unregisters the fiber.
        --$this->spawnedCount;

        State::deleteFlow($coroutine->flowKey);
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
