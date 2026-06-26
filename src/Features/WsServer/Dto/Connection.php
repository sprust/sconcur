<?php

declare(strict_types=1);

namespace SConcur\Features\WsServer\Dto;

use RuntimeException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Exceptions\WsServer\WsServerConnectionClosedException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Socket\Dto\AbstractConnection;
use SConcur\Features\WsServer\Payloads\RespondPayload;
use SConcur\Transport\PayloadInterface;
use Throwable;

/**
 * A live WebSocket connection handed to the server handler. The handler drives it
 * itself: read() pulls the next inbound message, write() pushes a message to the
 * client at any time (server push, text by default or binary), close() ends the
 * connection.
 *
 * The inbound messages stream under "<id>:in"; each carries a one-byte type marker
 * (text/binary) that read() strips and records for lastMessageWasBinary(). Writes and
 * closes are routed by connection id to the Go write loop. See AbstractConnection for
 * the shared mechanics.
 */
class Connection extends AbstractConnection
{
    /** The inbound type marker the Go side prefixes when a message is binary. */
    private const string BINARY_MARKER = "\x01";

    protected bool $lastMessageBinary = false;

    public function __construct(
        string $id,
        string $remoteAddr,
        string $localAddr,
        public readonly string $path,
        public readonly string $subprotocol,
    ) {
        parent::__construct(
            id: $id,
            remoteAddr: $remoteAddr,
            localAddr: $localAddr,
            inboundKey: $id . ':in',
        );
    }

    /**
     * Reads the next inbound message (text or binary), or null once the peer has closed
     * its side or the connection ended. The message type is recorded for
     * lastMessageWasBinary(). Binary-safe: a binary message may carry any bytes.
     */
    public function read(): ?string
    {
        $payload = parent::read();

        if ($payload === null) {
            return null;
        }

        // The first byte is the message-type marker (0 text, 1 binary); the rest is the
        // message payload. The Go side always prefixes the marker, so a non-null payload
        // is at least one byte; the empty-string guard keeps it defensive either way.
        $this->lastMessageBinary = ($payload !== '' && $payload[0] === self::BINARY_MARKER);

        return substr($payload, 1);
    }

    /**
     * Whether the message returned by the last read() was binary (otherwise text).
     * Only meaningful right after a read() that returned a message; after read() has
     * returned null the value still reflects the previous message.
     */
    public function lastMessageWasBinary(): bool
    {
        return $this->lastMessageBinary;
    }

    /**
     * Pushes one message to the client and waits for it to be flushed (write
     * backpressure). Sends text by default; pass $binary = true for a binary message.
     * Throws WsServerConnectionClosedException if the connection is gone.
     */
    public function write(string $data, bool $binary = false): void
    {
        if ($this->closed) {
            throw $this->connectionClosedException(message: 'Connection is closed.');
        }

        try {
            FeatureExecutor::exec(
                payload: RespondPayload::frame(
                    connectionId: $this->id,
                    data: $data,
                    binary: $binary,
                ),
            );
        } catch (TaskErrorException | TaskExecutionException $exception) {
            $this->closed = true;

            throw $this->connectionClosedException(
                message: 'Connection write failed: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * The text-frame payload used by the shared base path; the public write() builds
     * the frame directly so it can carry the binary message type.
     */
    protected function framePayload(string $data): PayloadInterface
    {
        return RespondPayload::frame(
            connectionId: $this->id,
            data: $data,
        );
    }

    protected function closePayload(): PayloadInterface
    {
        return RespondPayload::close($this->id);
    }

    protected function connectionClosedException(string $message, ?Throwable $previous = null): RuntimeException
    {
        return new WsServerConnectionClosedException(
            message: $message,
            previous: $previous,
        );
    }
}
