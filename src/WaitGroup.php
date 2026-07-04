<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use Fiber;
use Generator;
use RuntimeException;
use SConcur\Exceptions\CallbackExecutionException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Flow\CurrentFlow;
use SConcur\Scheduler\Coroutine;
use SConcur\Scheduler\Scheduler;
use Throwable;

class WaitGroup
{
    protected static int $counter = 0;

    /**
     * Live coroutines of this group: fiber id => callback key.
     *
     * @var array<int, string>
     */
    protected array $members = [];

    /**
     * Settled coroutine results awaiting delivery through iterate():
     * callback key => return value.
     *
     * @var array<string, mixed>
     */
    protected array $ready = [];

    /**
     * Callbacks added while the group was at its concurrency limit, not launched
     * yet (no fiber, nothing sent to Go). Drained by fillSlots() as members finish.
     * Keyed by callback key, in add() order; each entry keeps the callback and the
     * context parent to inherit at launch time.
     *
     * @var array<string, array{callback: Closure, parentContextFiberId: int}>
     */
    protected array $pending = [];

    /**
     * First failure raised by a coroutine of this group; rethrown from iterate().
     */
    protected ?Throwable $failure = null;

    protected string $flowKey;

    /**
     * @param int $maxConcurrency max simultaneously live (launched, unfinished) members;
     *                            0 = unlimited. Extra add()s queue and launch as slots free.
     */
    protected function __construct(protected int $maxConcurrency = 256)
    {
        ++static::$counter;

        $this->flowKey = (string) static::$counter;
    }

    public static function create(int $maxConcurrency = 256): WaitGroup
    {
        return new WaitGroup(maxConcurrency: $maxConcurrency);
    }

    /**
     * @param Closure(): mixed $callback
     */
    public function add(Closure $callback): string
    {
        $callbackKey = uniqid(more_entropy: true);

        // Capture the context to inherit now (the coroutine adding this, or the
        // root outside any fiber), so a deferred launch still inherits the adder's
        // keys rather than whatever context the scheduler happens to run it under.
        $parentContextFiberId = State::currentContextFiberId();

        if ($this->hasFreeSlot()) {
            $this->launch(
                callbackKey: $callbackKey,
                callback: $callback,
                parentContextFiberId: $parentContextFiberId,
            );
        } else {
            // At capacity: queue it. Nothing is created or sent to Go until a slot
            // frees and the scheduler calls fillSlots().
            $this->pending[$callbackKey] = [
                'callback'             => $callback,
                'parentContextFiberId' => $parentContextFiberId,
            ];
        }

        return $callbackKey;
    }

    /**
     * Launches the queued coroutines into the slots freed by finished members.
     * Called by the scheduler (from forget()) after a member completes or fails, so
     * the scheduler stays the single place coroutines are started and resumed.
     */
    public function fillSlots(): void
    {
        // A failed group is about to unwind through iterate()'s finally (stop());
        // do not launch anything more into it.
        if ($this->failure !== null) {
            return;
        }

        while ($this->pending !== [] && $this->hasFreeSlot()) {
            $callbackKey = array_key_first($this->pending);
            $queued      = $this->pending[$callbackKey];

            unset($this->pending[$callbackKey]);

            try {
                $this->launch(
                    callbackKey: $callbackKey,
                    callback: $queued['callback'],
                    parentContextFiberId: $queued['parentContextFiberId'],
                );
            } catch (CallbackExecutionException $exception) {
                // A deferred callback threw before its first suspend: its failure
                // belongs to the group (surfaced at iterate()), not to the scheduler.
                $this->markFailure($exception);

                return;
            }
        }
    }

    public function waitAll(): int
    {
        return iterator_count($this->iterate());
    }

    /**
     * @return array<string, mixed>
     */
    public function waitResults(): array
    {
        $results = [];

        foreach ($this->iterate() as $key => $result) {
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * Yields each coroutine's result as it settles. Delegates all waiting to the
     * Scheduler: at the top level it drives the scheduler loop; nested inside a
     * coroutine it cooperatively suspends so the outer flow keeps progressing.
     *
     * @return Generator<string, mixed>
     */
    public function iterate(): Generator
    {
        try {
            while (true) {
                if ($this->failure !== null) {
                    $failure       = $this->failure;
                    $this->failure = null;

                    /**
                     * Not to write Throwable in the annotation
                     *
                     * @var RuntimeException $failure
                     */

                    throw $failure;
                }

                if ($this->ready !== []) {
                    $callbackKey = array_key_first($this->ready);
                    $value       = $this->ready[$callbackKey];

                    unset($this->ready[$callbackKey]);

                    yield $callbackKey => $value;

                    continue;
                }

                if ($this->members === []) {
                    break;
                }

                if (Fiber::getCurrent() === null) {
                    Scheduler::get()->run($this);
                } else {
                    Scheduler::get()->awaitGroup($this);
                }
            }
        } finally {
            $this->stop();
        }
    }

    public function stop(): void
    {
        $members = $this->members;

        $this->members = [];
        $this->ready   = [];
        $this->pending = [];
        $this->failure = null;

        Scheduler::get()->clearGroupWaiter($this->flowKey);

        // Unwind still-suspended coroutines so their finally-blocks and local
        // destructors run (rollback a transaction, release a lock, ...). Done
        // before deleteFlow() so finally-blocks doing synchronous cleanup still
        // resolve the flow; cleanup that itself suspends on a new async call is
        // best-effort.
        foreach ($members as $fiberId => $callbackKey) {
            $coroutine = Scheduler::get()->detach($fiberId);

            if ($coroutine === null || !$coroutine->fiber->isSuspended()) {
                continue;
            }

            try {
                $coroutine->fiber->throw(new FlowStoppedException(message: 'Flow stopped'));
            } catch (Throwable) {
                // The unwinding callback may surface an exception; it must not
                // prevent stopping the remaining coroutines or the flow.
            }
        }

        State::deleteFlow($this->flowKey);
    }

    /**
     * Group key as seen by the Scheduler (== flow key).
     */
    public function key(): string
    {
        return $this->flowKey;
    }

    public function isLive(): bool
    {
        return $this->members !== [];
    }

    public function hasReadyOrFailure(): bool
    {
        return $this->ready !== [] || $this->failure !== null;
    }

    public function markReady(string $callbackKey, mixed $value): void
    {
        $this->ready[$callbackKey] = $value;
    }

    public function markFailure(Throwable $exception): void
    {
        $this->failure ??= $exception;
    }

    public function removeMember(int $fiberId): void
    {
        unset($this->members[$fiberId]);
    }

    /**
     * Whether another member may be launched right now. maxConcurrency 0 = unlimited.
     */
    protected function hasFreeSlot(): bool
    {
        return $this->maxConcurrency === 0 || count($this->members) < $this->maxConcurrency;
    }

    /**
     * Creates the fiber, registers it in State/Scheduler and runs it up to its
     * first suspend. Shared by the immediate add() path and the deferred fillSlots()
     * path. Throws CallbackExecutionException if the callback throws before
     * suspending — add() lets it propagate; fillSlots() turns it into a failure.
     *
     * @param Closure(): mixed $callback
     */
    protected function launch(string $callbackKey, Closure $callback, int $parentContextFiberId): void
    {
        $fiber   = new Fiber($callback);
        $fiberId = spl_object_id($fiber);

        State::registerFiberFlow(
            fiberId: $fiberId,
            flow: new CurrentFlow(
                isAsync: true,
                key: $this->flowKey,
            ),
        );

        // Recorded before start() so the child's first run already sees the
        // inherited keys.
        State::registerCoroutineContext(
            fiberId: $fiberId,
            parentFiberId: $parentContextFiberId,
        );

        $this->members[$fiberId] = $callbackKey;

        Scheduler::get()->register(
            new Coroutine(
                id: $fiberId,
                fiber: $fiber,
                group: $this,
                callbackKey: $callbackKey,
            ),
        );

        try {
            // First run up to the first suspend. May happen nested inside another
            // coroutine; that is fine — it ends at a suspend that returns here.
            $fiber->start();
        } catch (Throwable $exception) {
            $this->discard($fiberId);

            throw new CallbackExecutionException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }

        // Synchronous callback (no async call): its result is ready immediately.
        if ($fiber->isTerminated()) {
            $this->ready[$callbackKey] = $fiber->getReturn();

            $this->discard($fiberId);
        }
    }

    private function discard(int $fiberId): void
    {
        Scheduler::get()->detach($fiberId);
        State::unRegisterFiber($fiberId);

        unset($this->members[$fiberId]);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
