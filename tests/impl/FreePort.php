<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use RuntimeException;

/**
 * A loopback TCP port nothing listens on, for a server a test starts: the kernel picks one
 * for a socket bound to port 0, and that socket is closed before the port is answered.
 * Something else may take the port in between; the tests accept that.
 */
class FreePort
{
    public static function find(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException("Could not allocate a port: $errorMessage");
        }

        $name = (string) stream_socket_get_name($socket, false);

        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
