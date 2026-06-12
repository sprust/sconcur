<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use Iterator;
use SConcur\Dto\TaskResultDto;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;

/**
 * @implements Iterator<int, array<int|string, mixed>>
 */
class IteratorResult implements Iterator
{
    protected ?string $taskKey;

    /**
     * @var array<int, mixed>|null
     */
    protected ?array $items;
    protected int $itemIndex;
    protected int $globalIndex;
    protected mixed $currentKey;
    protected mixed $currentValue;

    protected bool $isLastBatch;
    protected bool $isFinished;

    public function __construct(
        protected MethodEnum $method,
        protected string $payload,
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

            $taskResult = FeatureExecutor::next(
                taskKey: $this->taskKey,
            );

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

        $taskResult = FeatureExecutor::exec(
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
        $this->items       = DocumentSerializer::unserializeBatch($taskResult->payload);
        $this->itemIndex   = 0;
    }

    protected function nextItem(): void
    {
        if ($this->items !== null && isset($this->items[$this->itemIndex])) {
            $this->currentKey   = $this->globalIndex;
            $this->currentValue = $this->items[$this->itemIndex];
            ++$this->globalIndex;
            ++$this->itemIndex;

            if (!isset($this->items[$this->itemIndex])) {
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
        $this->taskKey      = null;
        $this->items        = null;
        $this->itemIndex    = 0;
        $this->globalIndex  = 0;
        $this->currentKey   = null;
        $this->currentValue = null;
        $this->isLastBatch  = false;
        $this->isFinished   = false;
    }
}
