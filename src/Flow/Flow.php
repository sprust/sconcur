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
use SConcur\Features\MethodEnum;
use SConcur\SConcur;
use Throwable;

class Flow
{
    protected static ?Extension $extension = null;

    protected static int $flowsCounter = 0;

    protected readonly string $key;

    /**
     * @var array<string, Fiber>
     */
    protected array $fibersKeyByTaskUuid = [];

    public function __construct(
        protected readonly bool $isAsync
    ) {
        ++static::$flowsCounter;

        $this->key = (string) static::$flowsCounter;
    }

    public static function stop(): void
    {
        self::initExtension()->stop();
    }

    public function exec(Context $context, MethodEnum $method, string $payload): TaskResultDto
    {
        $runningTask = self::initExtension()->push(
            flowKey: $this->key,
            method: $method,
            payload: $payload
        );

        if ($this->isAsync) {
            if ($currentFiber = Sconcur::getCurrentFiber()) {
                $this->fibersKeyByTaskUuid[$runningTask->key] = $currentFiber;
            } else {
                throw new LogicException(
                    message: "Can't wait outside of fiber."
                );
            }

            $result = $this->suspend();
        } else {
            $result = $this->wait(context: $context);
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
        $result = self::initExtension()->wait(
            flowKey: $this->key,
            isAsync: $this->isAsync,
            context: $context
        );

        return $this->checkResult($result);
    }

    public function getFiberByTaskUuid(string $taskUuid): ?Fiber
    {
        return $this->fibersKeyByTaskUuid[$taskUuid] ?? null;
    }

    public function deleteFiberByTaskUuid(string $taskUuid): void
    {
        unset($this->fibersKeyByTaskUuid[$taskUuid]);
    }

    public function isAsync(): bool
    {
        return $this->isAsync;
    }

    public function stopTask(string $taskKey): void
    {
        self::initExtension()->cancel(
            flowKey: $this->key,
            taskKey: $taskKey
        );
    }

    protected static function initExtension(): Extension
    {
        return static::$extension ??= new Extension();
    }

    private function checkResult(TaskResultDto $result): TaskResultDto
    {
        if ($result->isError) {
            throw new TaskErrorException(
                $result->payload ?: 'Unknown error'
            );
        }

        return $result;
    }
}
