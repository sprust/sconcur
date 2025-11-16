<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use Iterator;
use RuntimeException;
use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\ContextCheckerException;
use SConcur\Exceptions\ContinueException;
use SConcur\Exceptions\FeatureResultNotFoundException;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\SConcur;

class AggregateResult implements Iterator
{
    protected const RESULT_KEY = '_result';

    protected ?TaskResultDto $currentTaskResult;

    protected ?array $items;

    protected mixed $currentKey;
    protected mixed $currentValue;

    protected bool $isFinished;

    public function __construct(
        protected Context $context,
        protected RunningTaskDto $runningTask
    ) {
        $this->rewind();
    }

    public function current(): mixed
    {
        return $this->currentValue;
    }

    /**
     * @throws FeatureResultNotFoundException
     * @throws ContinueException
     * @throws ContextCheckerException
     */
    public function next(): void
    {
        $this->context->check();

        if ($this->isFinished) {
            return;
        }

        if ($this->items === null) {
            $this->currentTaskResult = SConcur::detectResult(
                taskKey: $this->runningTask->key
            );

            if ($this->currentTaskResult->isError) {
                throw new RuntimeException(
                    $this->currentTaskResult->payload ?: 'Unknown error',
                );
            }

            $this->items = DocumentSerializer::unserialize(
                $this->currentTaskResult->payload
            )->toPHP()[static::RESULT_KEY];
        }

        if (count($this->items) === 0) {
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
            if ($this->currentTaskResult->isLast) {
                $this->rewind();
                $this->isFinished = true;
            } else {
                $this->items = null;
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
        $this->currentTaskResult = null;

        $this->items      = null;
        $this->isFinished = false;

        $this->currentKey   = null;
        $this->currentValue = null;
    }
}
