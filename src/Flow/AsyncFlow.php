<?php

declare(strict_types=1);

namespace SConcur\Flow;

use Fiber;
use SConcur\Contracts\FlowInterface;
use SConcur\Contracts\ServerConnectorInterface;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\MethodEnum;
use SConcur\Helpers\UuidGenerator;
use SConcur\SConcur;

class AsyncFlow implements FlowInterface
{
    protected string $flowUuid;

    /**
     * @var array<string, Fiber>
     */
    protected array $fibersKeyByTaskUuid = [];

    public function __construct(
        protected ServerConnectorInterface $serverConnector,
    ) {
        $this->flowUuid = UuidGenerator::make();
    }

    public function getUuid(): string
    {
        return $this->flowUuid;
    }

    public function pushTask(Context $context, MethodEnum $method, string $payload): TaskResultDto
    {
        $this->connectIfNotConnected($context);

        $runningTask = $this->serverConnector->write(
            context: $context,
            method: $method,
            payload: $payload
        );

        if ($currentFiber = Fiber::getCurrent()) {
            $this->fibersKeyByTaskUuid[$runningTask->key] = $currentFiber;
        }

        return SConcur::waitResult(
            context: $context,
            taskKey: $runningTask->key
        );
    }

    public function getFiberByTaskUuid(string $taskUuid): ?Fiber
    {
        return $this->fibersKeyByTaskUuid[$taskUuid] ?? null;
    }

    public function deleteFiberByTaskUuid(string $taskUuid): void
    {
        unset($this->fibersKeyByTaskUuid[$taskUuid]);
    }

    public function waitResult(Context $context): TaskResultDto
    {
        $result = $this->serverConnector->read($context);

        if ($result->isError) {
            throw new TaskErrorException(
                $result->payload ?: 'Unknown error'
            );
        }

        return $result;
    }

    public function close(): void
    {
        $this->serverConnector->disconnect();
    }

    private function connectIfNotConnected(Context $context): void
    {
        if (!$this->serverConnector->isConnected()) {
            $this->serverConnector->connect(
                context: $context,
            );
        }
    }

    public function __destruct()
    {
        $this->serverConnector->disconnect();
    }
}
