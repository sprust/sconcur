<?php

declare(strict_types=1);

namespace SConcur\Features\Socket\Dto;

use RuntimeException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\State;
use SConcur\Transport\PayloadInterface;
use Throwable;

/**
 * Shared base for both socket connection DTOs: the server's accepted Connection and
 * the client's dialed Connection. Both directions are length-prefix framed and
 * binary-safe; the only difference is which payloads carry a frame/close to Go and
 * which exception a dead connection raises, supplied by the subclass hooks.
 *
 * read() pulls the next inbound frame (cooperatively suspending the coroutine);
 * write() pushes a frame with backpressure; close() ends the connection. Lives in the
 * connection's coroutine, so reads/writes suspend while other connections keep going.
 */
abstract class AbstractConnection
{
    protected bool $inboundEnded = false;

    protected bool $closed = false;

    /** The payload that pushes one length-prefixed frame to the peer. */
    abstract protected function framePayload(string $data): PayloadInterface;

    /** The payload that closes the connection. */
    abstract protected function closePayload(): PayloadInterface;

    /** The feature-specific exception raised when a write hits a dead connection. */
    abstract protected function connectionClosedException(string $message, ?Throwable $previous = null): RuntimeException;

    /**
     * @param string $inboundKey the streaming-state key the inbound frames are pulled
     *                           from via next(): "<id>:in" for the server, the connect
     *                           result key for the client
     */
    public function __construct(
        public readonly string $id,
        public readonly string $remoteAddr,
        public readonly string $localAddr,
        protected readonly string $inboundKey,
    ) {
    }

    /**
     * Reads the next inbound frame, or null once the peer has closed its side (EOF)
     * or the connection ended. Blocks (cooperatively) until a frame arrives.
     */
    public function read(): ?string
    {
        if ($this->inboundEnded) {
            return null;
        }

        try {
            $result = FeatureExecutor::next(taskKey: $this->inboundKey);
        } catch (TaskErrorException | TaskExecutionException) {
            // The connection was reset/abandoned on the Go side: treat as end of input.
            $this->inboundEnded = true;

            return null;
        }

        if (!$result->hasNext) {
            $this->inboundEnded = true;

            return null;
        }

        return $result->payload;
    }

    /**
     * Pushes one frame to the peer and waits for it to be flushed (write
     * backpressure). Throws the feature's connection-closed exception if the
     * connection is gone.
     */
    public function write(string $data): void
    {
        if ($this->closed) {
            throw $this->connectionClosedException(message: 'Connection is closed.');
        }

        try {
            FeatureExecutor::exec(payload: $this->framePayload($data));
        } catch (TaskErrorException | TaskExecutionException $exception) {
            $this->closed = true;

            throw $this->connectionClosedException(
                message: 'Connection write failed: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Closes the connection. Idempotent and best-effort: if the peer is already gone
     * the close is a no-op. Also releases the connection's flow on the synchronous
     * path (a no-op in async, where the coroutine's flow is reaped on unwind).
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            FeatureExecutor::exec(payload: $this->closePayload());
        } catch (Throwable) {
            // The connection is already gone — nothing to close.
        }

        // Release the one-off sync flow that owns this connection, so an early close
        // (before the inbound stream is read to EOF) leaves no dangling flow. Keyed by
        // the inbound stream; a no-op when it was never a registered sync flow (server,
        // or the async path).
        State::releaseSyncTaskFlow($this->inboundKey);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
