<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use Fiber;
use Generator;
use LogicException;
use RuntimeException;
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
                if (count($this->syncResults) > 0) {
                    $syncResultKeys = array_keys($this->syncResults);

                    foreach ($syncResultKeys as $syncResultKey) {
                        $syncResult = $this->syncResults[$syncResultKey];

                        unset($this->syncResults[$syncResultKey]);

                        yield $syncResultKey => $syncResult;
                    }

                    continue;
                }

                if (count($this->fibers) === 0) {
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

                if (!array_key_exists($fiberId, $this->fibers)) {
                    throw new LogicException(
                        message: "Fiber not found by fiber id [$fiberId]"
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
        $this->fibers            = [];
        $this->fiberCallbackKeys = [];
        $this->syncResults       = [];

        State::deleteFlow($this->flowKey);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
