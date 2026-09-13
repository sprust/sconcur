<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Results;

use Iterator;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Redis\Connection;
use SConcur\Features\Redis\RedisCommandEnum;
use SConcur\Features\Redis\Support\Arguments;
use SConcur\Features\Redis\Support\RedisFailure;
use SConcur\Features\Redis\Support\ReplyDecoder;
use SConcur\State;

/**
 * A cursor command walked batch by batch: the cursor lives in the extension and PHP pulls
 * the next batch when the iterator asks for one, so a keyspace larger than memory is
 * walked without ever holding it whole.
 *
 * SCAN and SSCAN yield elements keyed by position. HSCAN and ZSCAN answer with field and
 * value alternating, and are yielded as field => value.
 *
 * Mirrors Sql\Results\RowsResult. Abandoning it early is safe: the flow ends, and the
 * state registry hook closes the cursor and releases its connection.
 *
 * @implements Iterator<int|string, string>
 */
class ScanResult implements Iterator
{
    protected ?string $taskKey = null;

    /** @var list<string> */
    protected array $items = [];

    protected int $itemIndex = 0;

    protected int $position = 0;

    protected int|string|null $currentKey = null;

    protected ?string $currentValue = null;

    protected bool $isLastBatch = false;

    protected bool $isFinished = false;

    /**
     * Whether rewind() has opened the cursor. Iterating without it is undefined
     * in PHP's contract, but "undefined" must not mean "spins for ever".
     */
    protected bool $started = false;

    /**
     * @param list<string> $arguments the command's arguments, without the cursor
     * @param bool         $pairs     whether the command answers with field and value
     *                                alternating (HSCAN, ZSCAN)
     */
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $name,
        protected readonly array $arguments,
        protected readonly int $batchSize = 0,
        protected readonly bool $pairs = false,
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

        $result = $this->connection->execute(
            command: RedisCommandEnum::Scan,
            data: [
                'n'  => $this->name,
                'a'  => Arguments::encode($this->arguments),
                'bs' => $this->batchSize,
            ],
        );

        $this->taskKey = $result->key;

        $this->setResult($result);
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

        $needed = $this->pairs ? 2 : 1;

        while (count($this->items) - $this->itemIndex < $needed) {
            if ($this->isLastBatch) {
                // A trailing half pair would mean the server answered a hash scan with an
                // odd number of elements, which it does not do; treat it as the end.
                $this->isFinished = true;

                return;
            }

            $this->pullBatch();

            // pullBatch() gives up when there is nothing left to pull. Without
            // this the loop would call it again on the same state for ever —
            // a spin with no I/O in it, which no deadline and no coroutine
            // switch can interrupt.
            if ($this->isFinished) {
                return;
            }
        }

        if ($this->pairs) {
            $this->currentKey   = $this->items[$this->itemIndex];
            $this->currentValue = $this->items[$this->itemIndex + 1];
            $this->itemIndex += 2;

            return;
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

            throw RedisFailure::from($exception);
        }

        $this->setResult($result);
    }

    protected function setResult(TaskResultDto $result): void
    {
        /** @var list<string> $items */
        $items = ReplyDecoder::decodeList($result);

        // Whatever is left unconsumed stays at the front: a hash scan may end a batch on
        // the field and start the next one on its value.
        $this->items = array_merge(
            array_slice($this->items, $this->itemIndex),
            $items,
        );

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
        $this->currentKey   = null;
        $this->currentValue = null;
        $this->isLastBatch  = false;
        $this->isFinished   = false;
    }

    /**
     * Releases the synchronous flow owning the cursor when the iterator is abandoned
     * before exhaustion (an early break). No-op in async mode and after normal completion.
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
