<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use Iterator;
use LogicException;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Features\MethofEnum;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\Flow\Flow;
use SConcur\State;

/**
 * @implements Iterator<int, array<int|string|float|bool|null, mixed>>
 */
class IteratorResult implements Iterator
{
    protected ?Flow $currentFlow;
    protected ?string $taskKey;

    /**
     * @var array<int, array<int|string|float|bool|null, mixed>>|null
     */
    protected ?array $items;
    protected mixed $currentKey;
    protected mixed $currentValue;

    protected bool $isLastBatch;
    protected bool $isFinished;

    public function __construct(
        protected Context $context,
        protected MethofEnum $method,
        protected string $payload,
        protected string $resultKey,
    ) {
        $this->resetProperties();
    }

    public function current(): mixed
    {
        return $this->currentValue;
    }

    public function next(): void
    {
        if ($this->isFinished) {
            return;
        }

        if ($this->items === null) {
            if ($this->isLastBatch) {
                $this->isFinished = true;

                return;
            }

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
        $this->resetProperties();

        $this->currentFlow = State::getCurrentFlow();

        $taskResult = $this->currentFlow->exec(
            context: $this->context,
            method: $this->method,
            payload: $this->payload
        );

        $this->taskKey = $taskResult->key;

        $this->setTaskResult($taskResult);

        $this->nextItem();
    }

    protected function setTaskResult(TaskResultDto $taskResult): void
    {
        $this->isLastBatch = !$taskResult->hasNext;

        $this->items = DocumentSerializer::unserialize($taskResult->payload)[$this->resultKey];
    }

    protected function nextItem(): void
    {
        foreach ($this->items ?: [] as $key => $value) {
            unset($this->items[$key]);

            $this->currentKey   = $key;
            $this->currentValue = $value;

            if (count($this->items) === 0) {
                $this->items = null;
            }

            return;
        }

        $this->items = null;

        if ($this->isLastBatch) {
            $this->isFinished = true;
        }
    }

    protected function resetProperties(): void
    {
        $this->currentFlow  = null;
        $this->taskKey      = null;
        $this->items        = null;
        $this->currentKey   = null;
        $this->currentValue = null;
        $this->isLastBatch  = false;
        $this->isFinished   = false;
    }
}
