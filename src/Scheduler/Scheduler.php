<?php

declare(strict_types=1);

namespace SConcur\Scheduler;

use Closure;
use Fiber;
use SConcur\Connection\Extension;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\CallbackExecutionException;
use SConcur\Exceptions\FiberStateException;
use SConcur\Exceptions\FlowStoppedException;
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

    public static function get(): Scheduler
    {
        return static::$instance ??= new Scheduler();
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
        $flowKey = uniqid('sp_', more_entropy: true);

        State::registerFiberFlow(
            fiberId: $fiberId,
            flow: new CurrentFlow(
                isAsync: true,
                key: $flowKey,
            )
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
            $fiber->start();
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
     * @param Closure(string): void $onRequest  receives the raw request payload
     * @param Closure(): bool       $shouldStop true once a shutdown was requested
     */
    public function serve(
        string $serverFlowKey,
        string $serverTaskKey,
        Closure $onRequest,
        Closure $shouldStop,
    ): void {
        $draining = false;

        while (true) {
            if (!$draining && $shouldStop()) {
                // Stop accepting new requests; keep draining in-flight handlers.
                $draining = true;
            }

            if ($draining && $this->spawnedCount === 0) {
                break;
            }

            $result = Extension::get()->waitAny();

            if ($result->flowKey === $serverFlowKey && $result->key === $serverTaskKey) {
                // The server stream ended (flow stopped): leave the serve loop.
                if (!$result->hasNext) {
                    break;
                }

                // While draining, refuse new requests; their connections are
                // aborted when the server flow is stopped below.
                if ($draining) {
                    continue;
                }

                // Re-arm for the next request before handling this one, so the
                // listener keeps accepting while the handler runs.
                Extension::get()->next($serverFlowKey, $serverTaskKey);

                $payload = $result->payload;

                $this->spawn(static function () use ($onRequest, $payload): void {
                    $onRequest($payload);
                });

                continue;
            }

            $this->resumeByResult($result);
        }

        // Stop the listener and abort any connections not yet answered.
        Extension::get()->stopFlow($serverFlowKey);
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
                message: "No coroutine for result [flow: {$result->flowKey}, task: {$result->key}]."
            );
        }

        $coroutine = $this->coroutines[$fiberId] ?? null;

        if ($coroutine === null) {
            throw new FiberStateException(
                message: "Coroutine [id: {$fiberId}] not found for delivered result."
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
            $coroutine->fiber->resume($resumeValue);
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
            )
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
