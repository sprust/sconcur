<?php

declare(strict_types=1);

namespace SConcur\Exceptions\SocketClient;

use RuntimeException;

/**
 * A write to a socket-client connection failed because the connection is gone (the
 * peer disconnected, or it was closed). A runtime failure: the caller can catch it to
 * stop sending, or let it unwind.
 */
class SocketClientConnectionClosedException extends RuntimeException
{
}
