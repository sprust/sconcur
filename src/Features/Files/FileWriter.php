<?php

declare(strict_types=1);

namespace SConcur\Features\Files;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\Files\FileStreamClosedException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Files\Payloads\FilesPayload;
use SConcur\Features\Files\Support\FilesFailure;
use SConcur\State;
use SConcur\Transport\MessagePackTransport;

/**
 * An open file PHP fills chunk by chunk instead of handing over whole.
 *
 * write() does not answer until the extension has written the chunk, so a coroutine
 * producing faster than the disk accepts waits on its own next call rather than piling
 * megabytes into memory.
 *
 * Not closing is safe but lossy: when the coroutine ends, the flow ends with it and the
 * extension closes the file. Whether the file goes with it follows the rule every write
 * in this feature follows — only a writer that created the file removes it, so only in
 * FileWriteMode::Create. close() is what turns the bytes into a finished file and answers
 * with the total written.
 */
class FileWriter
{
    protected bool $closed = false;

    protected int $writtenBytes = 0;

    /**
     * @param string $id      the name all three commands of one session share, drawn on
     *                        this side like HttpClient draws its upload request id
     * @param string $taskKey the key of the push that opened the writer; on the
     *                        synchronous path it owns the flow the session hangs on
     */
    public function __construct(
        protected readonly string $id,
        protected readonly string $path,
        protected readonly string $taskKey,
        protected readonly int $timeoutMs,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The bytes this writer has been told were written — the total the last
     * acknowledged write() or close() answered.
     *
     * A write that failed or was cut off is not in it, and cannot be: the extension
     * does not count a chunk it did not finish, so after a failure the file may hold
     * more bytes than this reports. That is the same fact the poisoned-writer rule
     * states from the other side.
     */
    public function writtenBytes(): int
    {
        return $this->writtenBytes;
    }

    /**
     * Hands one chunk over and waits until it is written. Answers with the running total.
     */
    public function write(string $chunk): int
    {
        $this->assertOpen();

        try {
            $this->writtenBytes = $this->count(
                result: $this->execute(
                    command: FilesCommandEnum::WriteChunk,
                    data: [
                        'i' => $this->id,
                        'c' => $chunk,
                    ],
                ),
            );
        } catch (FileStreamClosedException $exception) {
            // The session cannot take another byte — it was left inconsistent by an
            // interrupted chunk, or it is gone. Spending the handle here is what stops a
            // caller's retry loop from making a boundary crossing per attempt for ever,
            // and gives the flow back instead of holding it until the object is collected.
            $this->release();

            throw $exception;
        }

        return $this->writtenBytes;
    }

    /**
     * Flushes and closes the file, answering with the total written.
     *
     * A close that ran out of time or hit a transient error can be tried again: the
     * extension keeps the session and its open file for exactly that, and the file is
     * not removed — every chunk had been handed over and only the flush was in doubt.
     *
     * Anything else is terminal. A writer whose chunk was cut off holds bytes no total
     * accounts for, and one whose session is already gone has nothing left to close; in
     * both cases the handle is spent, its flow released, and a further call raises
     * FileStreamClosedException rather than reaching for somebody else's session.
     */
    public function close(): int
    {
        $this->assertOpen();

        try {
            $result = $this->execute(
                command: FilesCommandEnum::WriteClose,
                data: [
                    'i' => $this->id,
                ],
            );
        } catch (FileStreamClosedException $exception) {
            // Terminal, and the extension has already let the session go — so holding
            // the flow would hold it for a session that no longer exists.
            $this->release();

            throw $exception;
        }

        // The extension has closed the session by the time it answers, so the handle is
        // spent whatever the decoding below makes of the answer. Releasing after that —
        // which this used to do — left a failed decode with an open handle and a flow
        // held for a session that was already gone.
        //
        // After the close answered, never before: releasing the flow is what tells the
        // extension the session was abandoned, and doing it first would have it clean up
        // the very session that was being finished.
        $this->release();

        $this->writtenBytes = $this->count(result: $result);

        return $this->writtenBytes;
    }

    /**
     * Marks the handle spent and gives the synchronous flow back. Idempotent, because
     * the destructor runs it too.
     */
    protected function release(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        State::releaseSyncTaskFlow($this->taskKey);
    }

    protected function assertOpen(): void
    {
        if ($this->closed) {
            throw new FileStreamClosedException(
                message: "The writer for $this->path is closed.",
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function execute(FilesCommandEnum $command, array $data): TaskResultDto
    {
        try {
            return FeatureExecutor::exec(
                payload: new FilesPayload(
                    command: $command,
                    timeoutMs: $this->timeoutMs,
                    data: $data,
                ),
            );
        } catch (TaskErrorException | TaskExecutionException $exception) {
            throw FilesFailure::from($exception);
        }
    }

    protected function count(TaskResultDto $result): int
    {
        $decoded = MessagePackTransport::unpack($result->payload);

        return (int) ($decoded['n'] ?? 0);
    }

    /**
     * Releases the synchronous flow the session hangs on when the writer is dropped
     * without a close — including one dropped after a retryable close failed, which is
     * the caller deciding not to retry.
     *
     * The extension's own cleanup follows from that: the file is closed, and removed
     * only if this writer created it (FileWriteMode::Create) and never got as far as a
     * close.
     */
    public function __destruct()
    {
        $this->release();
    }
}
