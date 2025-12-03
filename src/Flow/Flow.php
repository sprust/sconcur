<?php

declare(strict_types=1);

namespace SConcur\Flow;

use Fiber;
use LogicException;
use RuntimeException;
use SConcur\Connection\Extension;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\ResponseIsNotJsonException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\UnexpectedResponseFormatException;
use SConcur\Features\MethodEnum;
use SConcur\Helpers\UuidGenerator;
use SConcur\SConcur;
use Throwable;

class Flow
{
    protected string $flowUuid;

    /**
     * @var array<string, Fiber>
     */
    protected array $fibersKeyByTaskUuid = [];

    public function __construct(
        protected Extension $extension,
        protected bool $isAsync
    ) {
        $this->flowUuid = UuidGenerator::make();
        $this->extension->stop();
    }

    public function getUuid(): string
    {
        return $this->flowUuid;
    }

    /**
     * @throws UnexpectedResponseFormatException
     * @throws TaskErrorException
     * @throws ResponseIsNotJsonException
     */
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

    /**
     * @throws UnexpectedResponseFormatException
     * @throws ResponseIsNotJsonException
     * @throws TaskErrorException
     */
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

    public function close(): void
    {
        $this->extension->stop();
    }

    /**
     * @throws TaskErrorException
     */
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
