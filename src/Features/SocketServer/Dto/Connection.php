<?php

declare(strict_types=1);

namespace SConcur\Features\SocketServer\Dto;

use SConcur\Exceptions\SocketServer\ConnectionClosedException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\SocketServer\Payloads\RespondPayload;
use Throwable;

/**
 * A live TCP connection handed to the server handler. The handler drives it itself:
 * read() pulls the next inbound frame, write() pushes a frame to the client at any
 * time (server push), close() ends the connection. Both directions are length-prefix
 * framed; reads and writes are binary-safe.
 *
 * Lives in the connection's coroutine, so read()/write() cooperatively suspend while
 * other connections keep being served.
 */
class Connection
{
    protected bool $inboundEnded = false;

    protected bool $closed = false;

    public function __construct(
        public readonly string $id,
        public readonly string $remoteAddr,
        public readonly string $localAddr,
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
            $result = FeatureExecutor::next(taskKey: $this->id . ':in');
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
     * Pushes one frame to the client and waits for it to be flushed (write
     * backpressure). Throws ConnectionClosedException if the connection is gone.
     */
    public function write(string $data): void
    {
        if ($this->closed) {
            throw new ConnectionClosedException(message: 'Connection is closed.');
        }

        try {
            FeatureExecutor::exec(
                payload: RespondPayload::frame(
                    connectionId: $this->id,
                    data: $data,
                ),
            );
        } catch (TaskErrorException | TaskExecutionException $exception) {
            $this->closed = true;

            throw new ConnectionClosedException(
                message: 'Connection write failed: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Closes the connection. Idempotent and best-effort: if the peer is already gone
     * the close is a no-op.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            FeatureExecutor::exec(payload: RespondPayload::close($this->id));
        } catch (Throwable) {
            // The connection is already gone — nothing to close.
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
