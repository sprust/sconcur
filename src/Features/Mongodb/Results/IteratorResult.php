<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use Iterator;
use LogicException;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\Flow\Flow;
use SConcur\State;

/**
 * @implements Iterator<int, array<int|string|float|bool|null, mixed>>
 */
class IteratorResult implements Iterator
{
    protected ?Flow $currentFlow = null;
    protected ?string $taskKey   = null;

    /**
     * @var array<int, array<int|string|float|bool|null, mixed>>|null
     */
    protected ?array $items       = null;
    protected mixed $currentKey   = null;
    protected mixed $currentValue = null;

    protected bool $isLastBatch = false;
    protected bool $isFinished  = false;

    public function __construct(
        protected Context $context,
        protected string $payload,
        protected string $resultKey,
    ) {
    }

    public function current(): mixed
    {
        return $this->currentValue;
    }

    public function next(): void
    {
        if ($this->currentFlow === null) {
            throw new LogicException(
                message: 'Flow is not initialized. First call rewind().'
            );
        }

        if ($this->isFinished) {
            return;
        }

        if ($this->items === null) {
            if ($this->currentFlow->isAsync()) {
                $taskResult = $this->currentFlow->suspend();
            } else {
                $taskResult = $this->currentFlow->wait(
                    context: $this->context,
                );

                $this->currentFlow->checkResult(result: $taskResult);
            }

            if ($taskResult->key !== $this->taskKey) {
                throw new LogicException(
                    message: 'Unexpected task key'
                );
            }

            $this->setTaskResult($taskResult);
        }

        $this->nextItem();
    }

    public function key(): mixed
    {
        return $this->currentKey;
    }

    public function valid(): bool
    {
        return $this->isFinished === false;
    }

    public function rewind(): void
    {
        if ($this->currentFlow !== null) {
            throw new LogicException(
                message: 'Flow is already initialized. Use an another instance.'
            );
        }

        $this->currentFlow = State::getCurrentFlow();

        $taskResult = $this->currentFlow->exec(
            context: $this->context,
            method: MethodEnum::Mongodb,
            payload: $this->payload
        );

        $this->taskKey = $taskResult->key;

        $this->setTaskResult($taskResult);
    }

    protected function setTaskResult(TaskResultDto $taskResult): void
    {
        $this->isLastBatch = !$taskResult->hasNext;

        $this->items = DocumentSerializer::unserialize($taskResult->payload)[$this->resultKey];

        $this->nextItem();
    }

    protected function nextItem(): void
    {
        foreach ($this->items ?: [] as $key => $value) {
            unset($this->items[$key]);

            $this->currentKey   = $key;
            $this->currentValue = $value;

            return;
        }

        $this->items = null;

        if ($this->isLastBatch) {
            $this->isFinished = true;
        }
    }
}
