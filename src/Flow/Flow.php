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
use Throwable;

class Flow
{
    /**
     * @var array<string, Fiber>
     */
    protected array $fibersKeyByTaskUuid = [];

    public function __construct(
        protected readonly Extension $extension,
        protected readonly bool $isAsync
    ) {
        $this->extension->stop();
    }

    public function exec(Context $context, MethodEnum $method, string $payload): TaskResultDto
    {
        $runningTask = $this->extension->push(
            method: $method,
            payload: $payload
        );

        if ($this->isAsync) {
            if ($currentFiber = Fiber::getCurrent()) {
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
        $result = $this->extension->wait($context);

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

    public function close(): void
    {
        $this->extension->stop();
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
