<?php

declare(strict_types=1);

namespace SConcur\Exceptions\SocketServer;

use RuntimeException;

/**
 * A write to a socket-server connection failed because the connection is gone (the
 * peer disconnected, or it was closed). A runtime failure: the handler can catch it
 * to stop pushing, or let it unwind to end the connection coroutine.
 */
class SocketServerConnectionClosedException extends RuntimeException
{
}
