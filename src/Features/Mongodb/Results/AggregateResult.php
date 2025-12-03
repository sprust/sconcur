<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use Iterator;
use RuntimeException;
use SConcur\Contracts\FlowInterface;
use SConcur\Entities\Context;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\SConcur;

class AggregateResult implements Iterator
{
    protected const string RESULT_KEY = '_result';

    protected ?FlowInterface $currentFlow = null;
    protected string $taskKey;

    protected ?array $items = null;
    protected mixed $currentKey = null;
    protected mixed $currentValue = null;

    protected bool $isLastBatch = false;
    protected bool $isFinished = false;

    public function __construct(
        protected Context $context,
        protected ConnectionParameters $connection,
        protected string $payload,
    ) {
        $this->next();
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
            // first iteration
            if ($this->currentFlow === null) {
                $this->currentFlow = SConcur::getCurrentFlow();

                $taskResult = $this->currentFlow->pushTask(
                    context: $this->context,
                    method: MethodEnum::Mongodb,
                    payload: $this->payload
                );

                $this->taskKey = $taskResult->key;
            } else {
                $taskResult = SConcur::waitResult(
                    context: $this->context,
                    taskKey: $this->taskKey
                );
            }

            $this->isLastBatch = !$taskResult->hasNext;

            $this->items = DocumentSerializer::unserialize(
                $taskResult->payload
            )[static::RESULT_KEY];
        }

        if (count($this->items) === 0) {
            if (!$this->isLastBatch) {
                throw new RuntimeException(
                    'Unexpected end of result'
                );
            }

            $this->rewind();
            $this->isFinished = true;

            return;
        }

        foreach ($this->items as $key => $value) {
            unset($this->items[$key]);

            $this->currentKey   = $key;
            $this->currentValue = $value;

            break;
        }

        if (count($this->items) === 0) {
            $this->items = null;

            if ($this->isLastBatch) {
                $this->isFinished = true;
            }
        }
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
    }
}
