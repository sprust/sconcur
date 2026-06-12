<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use Fiber;
use Generator;
use LogicException;
use RuntimeException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Features\FeatureExecutor;
use SConcur\Flow\CurrentFlow;
use Throwable;

class WaitGroup
{
    protected static int $counter = 0;

    /**
     * @var array<int, Fiber>
     */
    protected array $fibers = [];

    /**
     * @var array<int, string>
     */
    protected array $fiberCallbackKeys = [];

    /**
     * @var array<string, mixed>
     */
    protected array $syncResults = [];

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
        $fiber = new Fiber($callback);

        State::registerFiberFlow(
            fiberId: spl_object_id($fiber),
            flow: new CurrentFlow(
                isAsync: true,
                key: $this->flowKey
            )
        );

        try {
            $fiber->start();
        } catch (Throwable $exception) {
            // Without this, a reused spl_object_id could route a foreign
            // fiber's tasks into this flow.
            State::unRegisterFiber(spl_object_id($fiber));

            throw new RuntimeException(
                message: $exception->getMessage(),
                previous: $exception
            );
        }

        $callbackKey = uniqid(more_entropy: true);

        $fiberId = spl_object_id($fiber);

        if ($fiber->isTerminated()) {
            $this->syncResults[$callbackKey] = $fiber->getReturn();

            State::unRegisterFiber($fiberId);
        } else {
            $this->fibers[$fiberId]            = $fiber;
            $this->fiberCallbackKeys[$fiberId] = $callbackKey;
        }

        return $callbackKey;
    }

    public function waitAll(): int
    {
        $generator = $this->iterate();

        return iterator_count($generator);
    }

    /**
     * @return array<string, mixed>
     */
    public function waitResults(): array
    {
        $results = [];

        $generator = $this->iterate();

        foreach ($generator as $key => $result) {
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * @return Generator<string, mixed>
     */
    public function iterate(): Generator
    {
        try {
            while (true) {
                if ($this->syncResults !== []) {
                    foreach ($this->syncResults as $syncResultKey => $syncResult) {
                        unset($this->syncResults[$syncResultKey]);
                        yield $syncResultKey => $syncResult;
                    }

                    continue;
                }

                if ($this->fibers === []) {
                    break;
                }

                $taskResult = FeatureExecutor::wait(
                    flowKey: $this->flowKey
                );

                $taskKey = $taskResult->key;

                $fiberId = State::pullFiberByTask(
                    flowKey: $this->flowKey,
                    taskKey: $taskKey
                );

                $fiber = $this->fibers[$fiberId] ?? null;

                if ($fiber === null) {
                    throw new LogicException(
                        message: "Fiber [flow: $this->flowKey, task: $taskKey] not found"
                    );
                }

                if (!$fiber->isSuspended()) {
                    throw new LogicException(
                        message: "Fiber [flow: $this->flowKey, task: $taskKey] is not suspended"
                    );
                }

                if (!array_key_exists($fiberId, $this->fiberCallbackKeys)) {
                    throw new LogicException(
                        message: "Fiber callback key not found by fiber id [$fiberId]"
                    );
                }

                $fiber->resume($taskResult);

                if ($fiber->isTerminated()) {
                    $callbackResult = $fiber->getReturn();

                    State::unRegisterFiber($fiberId);

                    unset($this->fibers[$fiberId]);

                    $callbackKey = $this->fiberCallbackKeys[$fiberId];

                    unset($this->fiberCallbackKeys[$fiberId]);

                    yield $callbackKey => $callbackResult;
                }
            }
        } finally {
            $this->stop();
        }
    }

    public function stop(): void
    {
        $fibers = $this->fibers;

        $this->fibers            = [];
        $this->fiberCallbackKeys = [];
        $this->syncResults       = [];

        // Unwind still-suspended fibers so their finally-blocks and local destructors
        // run (rollback a transaction, release a lock, ...). Without this the paused
        // callbacks would be abandoned until end of request. Done before deleteFlow()
        // so finally-blocks doing synchronous cleanup still resolve the flow; cleanup
        // that itself suspends on a new async call is best-effort.
        foreach ($fibers as $fiber) {
            if (!$fiber->isSuspended()) {
                continue;
            }

            try {
                $fiber->throw(new FlowStoppedException(message: 'Flow stopped'));
            } catch (Throwable) {
                // The unwinding callback may surface an exception; it must not prevent
                // stopping the remaining fibers or the flow.
            }
        }

        State::deleteFlow($this->flowKey);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
