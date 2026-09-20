<?php

declare(strict_types=1);

namespace SConcur\Features\Files\Dto;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\Files\FileStreamClosedException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Files\FilesCommandEnum;
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
 * extension closes the file — removing it if the write never finished, unless the writer
 * was appending. close() is what turns the bytes into a finished file and answers with
 * the total written.
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
     * The bytes written so far, as the extension has counted them.
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

        $this->writtenBytes = $this->count(
            result: $this->execute(
                command: FilesCommandEnum::WriteChunk,
                data: [
                    'i' => $this->id,
                    'c' => $chunk,
                ],
            ),
        );

        return $this->writtenBytes;
    }

    /**
     * Flushes and closes the file, answering with the total written. Calling it twice is
     * a FileStreamClosedException rather than a second close of someone else's file.
     */
    public function close(): int
    {
        $this->assertOpen();

        try {
            $this->writtenBytes = $this->count(
                result: $this->execute(
                    command: FilesCommandEnum::WriteClose,
                    data: [
                        'i' => $this->id,
                    ],
                ),
            );
        } finally {
            // After the close, never before: releasing the flow is what tells the
            // extension the session was abandoned, and doing it first would have it
            // remove the very file that was being finished.
            $this->closed = true;

            State::releaseSyncTaskFlow($this->taskKey);
        }

        return $this->writtenBytes;
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
     * without a close. The extension's own cleanup follows from that: the file is closed
     * and, unless it was being appended to, the unfinished one is removed.
     */
    public function __destruct()
    {
        if ($this->closed) {
            return;
        }

        State::releaseSyncTaskFlow($this->taskKey);
    }
}
