<?php

declare(strict_types=1);

namespace SConcur\Flow;

use Fiber;
use LogicException;
use RuntimeException;
use SConcur\Connection\Extension;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\FeatureEnum;
use Throwable;

class Flow
{
    protected static ?Extension $extension = null;

    protected static int $flowsCounter = 0;

    protected readonly string $key;

    /**
     * @var array<string, Fiber>
     */
    protected array $fibersKeyByTaskKeys = [];

    public function __construct(
        protected readonly bool $isAsync
    ) {
        ++static::$flowsCounter;

        $this->key = (string) static::$flowsCounter;
    }

    public function exec(Context $context, FeatureEnum $method, string $payload): TaskResultDto
    {
        $runningTask = Extension::get()->push(
            flowKey: $this->key,
            method: $method,
            payload: $payload
        );

        if ($this->isAsync) {
            if ($currentFiber = Fiber::getCurrent()) {
                $this->fibersKeyByTaskKeys[$runningTask->key] = $currentFiber;
            } else {
                throw new LogicException(
                    message: "Can't wait outside of fiber."
                );
            }

            $result = $this->suspend();
        } else {
            $result = $this->wait(context: $context);

            $this->checkResult(result: $result);
        }

        if ($result->key !== $runningTask->key) {
            throw new LogicException(
                message: 'Unexpected task key.'
            );
        }

        return $result;
    }

    public function suspend(): TaskResultDto
    {
        if (!$this->isAsync) {
            throw new LogicException(
                message: 'Can\'t suspend outside of fiber.'
            );
        }

        try {
            $result = Fiber::suspend();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                message: $exception->getMessage(),
                previous: $exception
            );
        }

        if ($result instanceof TaskResultDto) {
            $this->checkResult($result);
        } else {
            throw new LogicException(
                message: 'Unexpected result type.'
            );
        }

        return $result;
    }

    public function wait(Context $context): TaskResultDto
    {
        return Extension::get()->wait(
            context: $context,
            flowKey: $this->key,
            isAsync: $this->isAsync,
        );
    }

    public function getFiberByTaskKey(string $taskKey): ?Fiber
    {
        return $this->fibersKeyByTaskKeys[$taskKey] ?? null;
    }

    public function deleteFiberByTaskKey(string $taskKey): void
    {
        unset($this->fibersKeyByTaskKeys[$taskKey]);
    }

    public function isAsync(): bool
    {
        return $this->isAsync;
    }

    public function stop(): void
    {
        Extension::get()->stopFlow($this->key);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function checkResult(TaskResultDto $result): TaskResultDto
    {
        if ($result->isError) {
            throw new TaskErrorException(
                $result->payload ?: 'Unknown error'
            );
        }

        return $result;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
