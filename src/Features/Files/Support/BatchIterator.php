<?php

declare(strict_types=1);

namespace SConcur\Features\Files\Support;

use Iterator;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Files\FilesCommandEnum;
use SConcur\Features\Files\Payloads\FilesPayload;
use SConcur\State;

/**
 * The shape the feature's three streams share: the extension holds the position, PHP
 * pulls the next batch when the iterator runs out of one.
 *
 * Abandoning a stream early is safe. The flow ends, and the state registry hook closes
 * what the stream held — the same guarantee Redis's ScanResult and SQL's RowsResult rest
 * on. On the synchronous path the flow is this object's to release, which is what
 * releaseTask() and the destructor are for.
 *
 * @template TValue
 *
 * @implements Iterator<int, TValue>
 */
abstract class BatchIterator implements Iterator
{
    protected ?string $taskKey = null;

    /** @var list<mixed> */
    protected array $items = [];

    protected int $itemIndex = 0;

    protected int $position = 0;

    /**
     * The key of the value current() holds. Kept beside it rather than read off
     * $position, which by then has already moved on to the next one.
     */
    protected int $currentKey = 0;

    protected mixed $currentValue = null;

    protected bool $isLastBatch = false;

    protected bool $isFinished = false;

    /**
     * Whether rewind() has opened the stream. Iterating without it is undefined in PHP's
     * contract, but "undefined" must not mean "spins for ever".
     */
    protected bool $started = false;

    /**
     * Turns one batch into the values it carries.
     *
     * @return list<mixed>
     */
    abstract protected function decode(TaskResultDto $result): array;

    public function __construct(
        protected readonly FilesCommandEnum $command,
        protected readonly int $timeoutMs,
        /** @var array<string, mixed> */
        protected readonly array $data,
    ) {
    }

    public function current(): mixed
    {
        return $this->currentValue;
    }

    public function key(): mixed
    {
        return $this->currentKey;
    }

    public function valid(): bool
    {
        return $this->started && !$this->isFinished;
    }

    public function rewind(): void
    {
        $this->releaseTask();
        $this->reset();

        $this->started = true;

        try {
            $result = FeatureExecutor::exec(
                payload: new FilesPayload(
                    command: $this->command,
                    timeoutMs: $this->timeoutMs,
                    data: $this->data,
                ),
            );
        } catch (TaskErrorException | TaskExecutionException $exception) {
            $this->isFinished = true;

            throw FilesFailure::from($exception);
        }

        $this->taskKey = $result->key;

        $this->setResult(result: $result);
        $this->advance();
    }

    public function next(): void
    {
        $this->advance();
    }

    protected function advance(): void
    {
        if ($this->isFinished || !$this->started) {
            return;
        }

        while ($this->itemIndex >= count($this->items)) {
            if ($this->isLastBatch) {
                $this->isFinished = true;

                return;
            }

            $this->pullBatch();

            // pullBatch() gives up when there is nothing left to pull. Without this the
            // loop would call it again on the same state for ever — a spin with no I/O
            // in it, which no deadline and no coroutine switch can interrupt.
            if ($this->isFinished) {
                return;
            }
        }

        $this->currentKey   = $this->position;
        $this->currentValue = $this->items[$this->itemIndex];

        ++$this->position;
        ++$this->itemIndex;
    }

    protected function pullBatch(): void
    {
        if ($this->taskKey === null) {
            $this->isFinished = true;

            return;
        }

        try {
            $result = FeatureExecutor::next(taskKey: $this->taskKey);
        } catch (TaskErrorException | TaskExecutionException $exception) {
            $this->isFinished = true;

            throw FilesFailure::from($exception);
        }

        $this->setResult(result: $result);
    }

    protected function setResult(TaskResultDto $result): void
    {
        $this->items       = $this->decode(result: $result);
        $this->itemIndex   = 0;
        $this->isLastBatch = !$result->hasNext;
    }

    protected function reset(): void
    {
        $this->taskKey      = null;
        $this->started      = false;
        $this->items        = [];
        $this->itemIndex    = 0;
        $this->position     = 0;
        $this->currentKey   = 0;
        $this->currentValue = null;
        $this->isLastBatch  = false;
        $this->isFinished   = false;
    }

    /**
     * Releases the synchronous flow owning the stream when the iterator is abandoned
     * before exhaustion (an early break). No-op in async mode and after normal
     * completion.
     */
    protected function releaseTask(): void
    {
        if ($this->taskKey !== null) {
            State::releaseSyncTaskFlow($this->taskKey);
        }
    }

    public function __destruct()
    {
        $this->releaseTask();
    }
}
