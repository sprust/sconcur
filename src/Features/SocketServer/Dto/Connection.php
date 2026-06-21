<?php

declare(strict_types=1);

namespace SConcur\Features\SocketServer\Dto;

use RuntimeException;
use SConcur\Exceptions\SocketServer\SocketServerConnectionClosedException;
use SConcur\Features\Socket\Dto\AbstractConnection;
use SConcur\Features\SocketServer\Payloads\RespondPayload;
use SConcur\Transport\PayloadInterface;
use Throwable;

/**
 * A live TCP connection handed to the server handler. The handler drives it itself:
 * read() pulls the next inbound frame, write() pushes a frame to the client at any
 * time (server push), close() ends the connection.
 *
 * The inbound frames stream under "<id>:in"; writes/closes are routed by connection
 * id to the Go write loop. See AbstractConnection for the shared mechanics.
 */
class Connection extends AbstractConnection
{
    public function __construct(string $id, string $remoteAddr, string $localAddr)
    {
        parent::__construct(
            id: $id,
            remoteAddr: $remoteAddr,
            localAddr: $localAddr,
            inboundKey: $id . ':in',
        );
    }

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
        return new SocketServerConnectionClosedException(
            message: $message,
            previous: $previous,
        );
    }
}
