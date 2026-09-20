<?php

declare(strict_types=1);

namespace SConcur\Features\Files;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\Files\FileStreamClosedException;
use SConcur\Exceptions\UnexpectedResponseFormatException;
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
     *
     * A chunk that did not complete leaves the session unusable — see close() — and the
     * first call to notice that spends the handle: it gives the flow back and raises
     * FileStreamClosedException, so a caller retrying in a loop stops crossing the
     * boundary against a session that can never take another byte.
     *
     * The failure of the chunk itself arrives as whatever it was, usually
     * FileTimeoutException or FileOperationException; it is the call after it that is
     * refused. A write error may also surface at close() rather than here, because the
     * extension's buffer is flushed there.
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
            // caller's retry loop from making a boundary crossing per attempt for ever.
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
     * The retry runs under the deadline openWriter() was given, which is the one that
     * just failed; there is no way to widen it for the second attempt.
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
     *
     * The flow part only does something outside a coroutine: a coroutine's flow belongs
     * to its WaitGroup and ends with it, so there is nothing here to give back. Marking
     * the handle spent matters on both paths.
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

        if (!array_key_exists('n', $decoded)) {
            // Refused rather than read as zero, which would reset a correct running
            // total — see Files::field() for the rest of the reasoning.
            throw new UnexpectedResponseFormatException(
                message: "The writer result carries no 'n' field.",
            );
        }

        return (int) $decoded['n'];
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
