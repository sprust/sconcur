<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient\Dto;

use RuntimeException;
use SConcur\Exceptions\SocketClient\SocketClientConnectionClosedException;
use SConcur\Features\Socket\Dto\AbstractConnection;
use SConcur\Features\SocketClient\Payloads\ClosePayload;
use SConcur\Features\SocketClient\Payloads\SendPayload;
use SConcur\Transport\PayloadInterface;
use Throwable;

/**
 * A live outbound TCP connection returned by SocketClient::connect(). The caller
 * drives it: read() pulls the next inbound frame, write() pushes a frame to the peer,
 * close() ends the connection. Both directions are length-prefix framed.
 *
 * The inbound frames stream under the connect result key; writes/closes are routed by
 * connection id to the Go write loop. See AbstractConnection for the shared mechanics.
 */
class Connection extends AbstractConnection
{
    protected function framePayload(string $data): PayloadInterface
    {
        return new SendPayload(
            connectionId: $this->id,
            data: $data,
        );
    }

    protected function closePayload(): PayloadInterface
    {
        return new ClosePayload(
            connectionId: $this->id,
        );
    }

    protected function connectionClosedException(string $message, ?Throwable $previous = null): RuntimeException
    {
        return new SocketClientConnectionClosedException(
            message: $message,
            previous: $previous,
        );
    }
}
