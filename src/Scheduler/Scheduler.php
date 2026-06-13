<?php

declare(strict_types=1);

namespace SConcur\Scheduler;

use Fiber;
use SConcur\Connection\Extension;
use SConcur\Exceptions\CallbackExecutionException;
use SConcur\Exceptions\FiberStateException;
use SConcur\Exceptions\FlowStoppedException;
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

    public static function get(): Scheduler
    {
        return static::$instance ??= new Scheduler();
    }

    public function register(Coroutine $coroutine): void
    {
        $this->coroutines[$coroutine->id] = $coroutine;
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
     * One scheduler step: take the first ready result of any flow and resume the
     * coroutine it belongs to.
     */
    protected function tick(): void
    {
        $result = Extension::get()->waitAny();

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
        $coroutine->group->markReady($coroutine->callbackKey, $coroutine->fiber->getReturn());

        $this->forget($coroutine);

        if (!$coroutine->group->isLive()) {
            $this->wakeGroupWaiters($coroutine->group);
        }
    }

    protected function failCoroutine(Coroutine $coroutine, Throwable $exception): void
    {
        $this->forget($coroutine);

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

        State::unRegisterFiber($coroutine->id);

        $coroutine->group->removeMember($coroutine->id);
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
