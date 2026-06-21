<?php

declare(strict_types=1);

namespace SConcur\Exceptions\SocketClient;

use RuntimeException;

/**
 * Dialing a socket-client connection failed (connection refused, DNS failure or a
 * connect timeout). A runtime failure thrown from SocketClient::connect(); the Go
 * side tags the cause with a "net:" marker, preserved in the message.
 */
class SocketClientConnectException extends RuntimeException
{
}
