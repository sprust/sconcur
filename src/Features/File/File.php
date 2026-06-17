<?php

declare(strict_types=1);

namespace SConcur\Features\File;

use SConcur\Exceptions\File\FileException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\File\Payloads\ClosePayload;
use SConcur\Features\File\Payloads\ReadPayload;
use SConcur\Features\File\Payloads\SeekPayload;
use SConcur\Features\File\Payloads\StatPayload;
use SConcur\Features\File\Payloads\SyncPayload;
use SConcur\Features\File\Payloads\TruncatePayload;
use SConcur\Features\File\Payloads\WritePayload;
use SConcur\Features\File\Results\FileStat;
use SConcur\State;
use SConcur\Transport\MessagePackTransport;

/**
 * An open file handle pinned to a single descriptor on the Go side across a series
 * of tasks. Opened by FileSystem::open(); every read/write/seek/... carries the
 * handle id so the Go side routes it to the held descriptor.
 *
 * Each call runs in the Go extension while the calling coroutine suspends, so a
 * loop of reads/writes streams chunk by chunk without blocking other coroutines.
 * Outside a WaitGroup the same calls work synchronously.
 *
 * close() closes the descriptor and releases the held Open task; if the handle is
 * abandoned (no close, an exception, a flow stop) the Go side closes it when the
 * flow's context is cancelled. Mirrors Sql\Transaction.
 */
class File
{
    /** Read granularity used by getContents() when draining to the end. */
    protected const int DRAIN_CHUNK_SIZE = 65_536;

    /** The descriptor position, kept in sync from each read/write/seek result. */
    protected int $position = 0;

    /** True once a read reported EOF (like PHP feof). */
    protected bool $eofReached = false;

    protected bool $closed = false;

    public function __construct(
        protected string $handleId,
        protected FileMode $mode,
        protected int $timeoutMs,
    ) {
    }

    /**
     * Reads up to $length bytes from the current position. Returns '' at the end of
     * the file. Each call is one round-trip to the extension.
     */
    public function read(int $length): string
    {
        $this->ensureOpen();
        $this->ensureReadable();

        if ($length <= 0) {
            return '';
        }

        $taskResult = FeatureExecutor::exec(
            payload: new ReadPayload(
                handleId: $this->handleId,
                length: $length,
                timeoutMs: $this->timeoutMs,
            ),
        );

        $decoded = MessagePackTransport::unpack($taskResult->payload);

        $this->position   = (int) ($decoded['p'] ?? $this->position);
        $this->eofReached = (bool) ($decoded['e'] ?? false);

        return (string) ($decoded['b'] ?? '');
    }

    /**
     * Writes $data at the current position (always at the end in append mode) and
     * returns the number of bytes written.
     */
    public function write(string $data): int
    {
        $this->ensureOpen();
        $this->ensureWritable();

        $taskResult = FeatureExecutor::exec(
            payload: new WritePayload(
                handleId: $this->handleId,
                bytes: $data,
                timeoutMs: $this->timeoutMs,
            ),
        );

        $decoded = MessagePackTransport::unpack($taskResult->payload);

        $this->position   = (int) ($decoded['p'] ?? $this->position);
        $this->eofReached = false;

        return (int) ($decoded['n'] ?? 0);
    }

    /**
     * Repositions the handle and returns the new absolute position. $whence is one
     * of SEEK_SET, SEEK_CUR, SEEK_END.
     */
    public function seek(int $offset, int $whence = SEEK_SET): int
    {
        $this->ensureOpen();

        $taskResult = FeatureExecutor::exec(
            payload: new SeekPayload(
                handleId: $this->handleId,
                offset: $offset,
                whence: $whence,
                timeoutMs: $this->timeoutMs,
            ),
        );

        $decoded = MessagePackTransport::unpack($taskResult->payload);

        $this->position   = (int) ($decoded['p'] ?? 0);
        $this->eofReached = false;

        return $this->position;
    }

    /**
     * The current position, tracked locally from each read/write/seek (no
     * round-trip), like PHP ftell.
     */
    public function tell(): int
    {
        return $this->position;
    }

    /**
     * Seeks back to the start; returns the new position (0).
     */
    public function rewind(): int
    {
        return $this->seek(offset: 0, whence: SEEK_SET);
    }

    /**
     * True once a read has reached the end of the file (like PHP feof). Tracked
     * locally — no round-trip.
     */
    public function eof(): bool
    {
        return $this->eofReached;
    }

    /**
     * Resizes the file to $size bytes (ftruncate).
     */
    public function truncate(int $size): void
    {
        $this->ensureOpen();

        FeatureExecutor::exec(
            payload: new TruncatePayload(
                handleId: $this->handleId,
                size: $size,
                timeoutMs: $this->timeoutMs,
            ),
        );
    }

    /**
     * Flushes buffered writes to stable storage (fsync).
     */
    public function flush(): void
    {
        $this->ensureOpen();

        FeatureExecutor::exec(
            payload: new SyncPayload(
                handleId: $this->handleId,
                timeoutMs: $this->timeoutMs,
            ),
        );
    }

    /**
     * The file's size, modification time and mode bits.
     */
    public function stat(): FileStat
    {
        $this->ensureOpen();

        $taskResult = FeatureExecutor::exec(
            payload: new StatPayload(
                handleId: $this->handleId,
                timeoutMs: $this->timeoutMs,
            ),
        );

        return FileStat::fromPayload($taskResult->payload);
    }

    /**
     * Reads from the current position to the end of the file, streaming it chunk by
     * chunk. Each chunk is a separate task, so a big file never buffers whole in the
     * extension and other coroutines keep running between reads.
     */
    public function getContents(): string
    {
        $this->ensureOpen();
        $this->ensureReadable();

        $contents = '';

        while (!$this->eofReached) {
            $chunk = $this->read(self::DRAIN_CHUNK_SIZE);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    /**
     * Closes the descriptor and releases the held Open task so no task dangles.
     * Idempotent: a second call is a no-op.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        FeatureExecutor::exec(
            payload: new ClosePayload(
                handleId: $this->handleId,
                timeoutMs: $this->timeoutMs,
            ),
        );

        FeatureExecutor::next(taskKey: $this->handleId);
    }

    protected function ensureOpen(): void
    {
        if ($this->closed) {
            throw new FileException(
                message: 'The file handle is closed.',
            );
        }
    }

    protected function ensureReadable(): void
    {
        if (!$this->mode->isReadable()) {
            throw new FileException(
                message: "File opened in mode [{$this->mode->value}] is not readable.",
            );
        }
    }

    protected function ensureWritable(): void
    {
        if (!$this->mode->isWritable()) {
            throw new FileException(
                message: "File opened in mode [{$this->mode->value}] is not writable.",
            );
        }
    }

    /**
     * Abandoned without close() (e.g. an exception unwound the scope): release the
     * held Open flow on the synchronous path so no task dangles. The Go side closes
     * the descriptor from the cancelled context. No-op in async mode and after an
     * explicit close().
     */
    public function __destruct()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        State::releaseSyncTaskFlow($this->handleId);
    }
}
