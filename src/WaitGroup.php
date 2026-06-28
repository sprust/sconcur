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
     * First failure raised by a coroutine of this group; rethrown from iterate().
     */
    protected ?Throwable $failure = null;

    protected string $flowKey;

    protected function __construct()
    {
        ++static::$counter;

        $this->flowKey = (string) static::$counter;
    }

    public static function create(): WaitGroup
    {
        return new WaitGroup();
    }

    /**
     * @param Closure(): mixed $callback
     */
    public function add(Closure $callback): string
    {
        $fiber       = new Fiber($callback);
        $fiberId     = spl_object_id($fiber);
        $callbackKey = uniqid(more_entropy: true);

        State::registerFiberFlow(
            fiberId: $fiberId,
            flow: new CurrentFlow(
                isAsync: true,
                key: $this->flowKey,
            ),
        );

        // The child inherits the context of the coroutine adding it (the current
        // fiber), or the root when added outside any fiber. Recorded before
        // start() so the child's first run already sees the inherited keys.
        State::registerCoroutineContext(
            fiberId: $fiberId,
            parentFiberId: State::currentContextFiberId(),
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

        return $callbackKey;
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
