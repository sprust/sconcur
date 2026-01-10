<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use Fiber;
use Generator;
use LogicException;
use RuntimeException;
use SConcur\Exceptions\FiberStopException;
use SConcur\Flow\Flow;
use Throwable;

class WaitGroup
{
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

    protected function __construct(
        protected readonly Flow $flow,
    ) {
    }

    public static function create(): WaitGroup
    {
        return new WaitGroup(
            flow: new Flow(isAsync: true),
        );
    }

    /**
     * @param Closure(): mixed $callback
     */
    public function add(Closure $callback): string
    {
        $fiber = new Fiber($callback);

        State::registerFiberFlow(
            fiber: $fiber,
            flow: $this->flow
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

        if ($fiber->isTerminated()) {
            $this->syncResults[$callbackKey] = $fiber->getReturn();

            State::unRegisterFiber($fiber);
        } else {
            $fiberId = spl_object_id($fiber);

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
        // TODO: union syncResults and fibers handling

        while (count($this->syncResults) > 0) {
            $syncResultKeys = array_keys($this->syncResults);

            foreach ($syncResultKeys as $syncResultKey) {
                $syncResult = $this->syncResults[$syncResultKey];

                unset($this->syncResults[$syncResultKey]);

                yield $syncResultKey => $syncResult;
            }
        }

        try {
            while (count($this->fibers) > 0) {
                $taskResult = $this->flow->wait();

                $taskKey = $taskResult->key;

                $fiber = $this->flow->getFiberByTaskKey($taskKey);

                if (!$fiber) {
                    throw new LogicException(
                        message: "Fiber not found by task key [$taskKey]"
                    );
                }

                if (!$fiber->isSuspended()) {
                    throw new LogicException(
                        message: "Fiber with task key [$taskKey] is not suspended"
                    );
                }

                $fiberId = spl_object_id($fiber);

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

                $this->flow->deleteFiberByTaskKey($taskKey);

                if ($fiber->isTerminated()) {
                    $callbackResult = $fiber->getReturn();

                    State::unRegisterFiber($fiber);

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
        $stopException = FiberStopException::create();

        foreach ($this->fibers as $fiber) {
            State::unRegisterFiber($fiber);

            if ($fiber->isTerminated()) {
                continue;
            }

            // TODO: think about better way to implement this
            // memory leak fix
            try {
                $fiber->resume($stopException);
            } catch (Throwable $exception) {
                if (!$exception instanceof FiberStopException) {
                    throw $exception;
                }
            }
        }

        $this->fibers            = [];
        $this->fiberCallbackKeys = [];
        $this->syncResults       = [];

        $this->flow->stop();
    }

    public function __destruct()
    {
        $this->stop();
    }
}
