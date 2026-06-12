<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use Iterator;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\UnexpectedResponseFormatException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\Transport\MessagePackTransport;

// TODO: check for iterator_to_array
// TODO: check for iterator_count

/**
 * @implements Iterator<int, array<int|string|float|bool|null, mixed>>
 */
class IteratorResult implements Iterator
{
    protected ?string $taskKey;

    /**
     * @var array<int, string>|null
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

        $decoded = MessagePackTransport::unpack($taskResult->payload);

        if (!array_is_list($decoded)) {
            throw new UnexpectedResponseFormatException(
                message: 'Aggregate batch payload is not a list.'
            );
        }

        foreach ($decoded as $item) {
            if (!is_string($item)) {
                throw new UnexpectedResponseFormatException(
                    message: 'Aggregate batch item payload is not a string.'
                );
            }
        }

        /** @var array<int, string> $decoded */
        $this->items     = $decoded;
        $this->itemIndex = 0;
    }

    protected function nextItem(): void
    {
        if ($this->items !== null && isset($this->items[$this->itemIndex])) {
            $this->currentKey   = $this->globalIndex;
            $this->currentValue = DocumentSerializer::unserialize($this->items[$this->itemIndex]);
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
