<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use Fiber;
use Generator;
use LogicException;
use RuntimeException;
use SConcur\Entities\Context;
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
        protected readonly Context $context,
        protected readonly Flow $flow,
    ) {
    }

    public static function create(Context $context): WaitGroup
    {
        return new WaitGroup(
            context: $context,
            flow: new Flow(isAsync: true),
        );
    }

    /**
     * @param Closure(Context): mixed $callback
     */
    public function add(Closure $callback): string
    {
        $fiber = new Fiber($callback);

        $parameters = [
            $this->context,
        ];

        SConcur::registerFiberFlow(
            fiber: $fiber,
            flow: $this->flow
        );

        try {
            $fiber->start(...$parameters);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                message: $exception->getMessage(),
                previous: $exception
            );
        }

        $callbackKey = uniqid(more_entropy: true);

        if ($fiber->isTerminated()) {
            $this->syncResults[$callbackKey] = $fiber->getReturn();

            SConcur::unRegisterFiber($fiber);
        } else {
            $fiberId = spl_object_id($fiber);

            $this->fibers[$fiberId]            = $fiber;
            $this->fiberCallbackKeys[$fiberId] = $callbackKey;
        }

        return $callbackKey;
    }

    public function waitAll(): int
    {
        $generator = $this->wait();

        return iterator_count($generator);
    }

    /**
     * @return array<string, mixed>
     */
    public function waitResults(): array
    {
        $results = [];

        $generator = $this->wait();

        foreach ($generator as $key => $result) {
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * @return Generator<string, mixed>
     */
    public function wait(): Generator
    {
        $syncResultKeys = array_keys($this->syncResults);

        foreach ($syncResultKeys as $syncResultKey) {
            $syncResult = $this->syncResults[$syncResultKey];

            unset($this->syncResults[$syncResultKey]);

            yield $syncResultKey => $syncResult;
        }

        while (count($this->fibers) > 0) {
            $taskResult = $this->flow->wait($this->context);

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

            try {
                $fiber->resume($taskResult);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    message: $exception->getMessage(),
                    previous: $exception
                );
            }

            if ($fiber->isTerminated()) {
                $callbackResult = $fiber->getReturn();

                SConcur::unRegisterFiber($fiber);

                unset($this->fibers[$fiberId]);

                $callbackKey = $this->fiberCallbackKeys[$fiberId];

                unset($this->fiberCallbackKeys[$fiberId]);

                $this->flow->deleteFiberByTaskKey($taskKey);

                yield $callbackKey => $callbackResult;
            }
        }
    }

    public function __destruct()
    {
        $keys = array_keys($this->fibers);

        foreach ($keys as $key) {
            $fiber = $this->fibers[$key];

            SConcur::unRegisterFiber($fiber);

            unset($this->fibers[$key]);
        }

        $this->flow->stop();
    }
}
