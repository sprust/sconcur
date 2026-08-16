<?php

declare(strict_types=1);

/**
 * Helpers for the socket-server benchmarks: resolve the running `servers` container
 * socket pool (3 reusePort workers), check it is reachable, and drive concurrent
 * length-prefix-framed round-trips.
 *
 * The pool is the master-supervised server from docker-compose's `servers`
 * container. Benchmarks run inside the `php` container, so the pool is reachable
 * by its compose service hostname (`servers`) over the internal docker network,
 * bypassing the published-port NAT.
 */

/**
 * Host of the socket server pool (compose service hostname by default; override with
 * BENCH_SOCKET_HOST, e.g. 127.0.0.1 to hit the published port from the host).
 */
function socketBenchHost(): string
{
    return getenv('BENCH_SOCKET_HOST') ?: 'servers';
}

/**
 * Port of the socket server pool (the in-container listen port by default; override
 * with BENCH_SOCKET_PORT, e.g. 29100 for the published host port).
 */
function socketBenchPort(): int
{
    return (int) (getenv('BENCH_SOCKET_PORT') ?: 9100);
}

/**
 * Aborts the benchmark with a clear hint if the socket server pool is unreachable.
 */
function socketBenchRequireServers(string $host, int $port): void
{
    $connection = @fsockopen($host, $port, $errno, $errstr, 2.0);

    if (!is_resource($connection)) {
        fwrite(STDERR, "socket server pool not reachable at $host:$port ($errstr).\n");
        fwrite(STDERR, "Start the `servers` container with `make up` (or `make servers-restart` to rebuild it).\n");

        exit(1);
    }

    fclose($connection);
}

/**
 * Frames one message: a 4-byte big-endian length prefix + payload.
 */
function socketBenchFrame(string $payload): string
{
    return pack('N', strlen($payload)) . $payload;
}

/**
 * Opens $connections concurrent connections, sends one $message frame on each, and
 * reads exactly one reply frame from each (non-blocking, multiplexed via
 * stream_select). Returns [elapsed seconds, replies-received].
 *
 * @return array{float, int}
 */
function socketBenchConcurrentOneShot(string $host, int $port, int $connections, string $message): array
{
    $frame = socketBenchFrame($message);

    $sockets = [];
    $buffers = [];

    for ($i = 0; $i < $connections; $i++) {
        $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);

        if (!is_resource($socket)) {
            fwrite(STDERR, "connect failed: $errstr\n");
            exit(1);
        }

        stream_set_blocking($socket, false);

        $sockets[$i] = $socket;
        $buffers[$i] = '';
    }

    $start = microtime(true);

    foreach ($sockets as $socket) {
        fwrite($socket, $frame);
    }

    $ok      = 0;
    $pending = $connections;

    while ($pending > 0) {
        $read  = $sockets;
        $write = null;
        $except = null;

        if ($read === []) {
            break;
        }

        if (@stream_select($read, $write, $except, 30) === 0) {
            break;
        }

        foreach ($read as $socket) {
            $index = (int) array_search($socket, $sockets, true);
            $chunk = fread($socket, 65536);

            if ($chunk === '' || $chunk === false) {
                fclose($socket);
                unset($sockets[$index]);
                $pending--;

                continue;
            }

            $buffers[$index] .= $chunk;

            if (socketBenchHasFrame($buffers[$index])) {
                $ok++;
                fclose($socket);
                unset($sockets[$index]);
                $pending--;
            }
        }
    }

    $elapsed = microtime(true) - $start;

    foreach ($sockets as $socket) {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    return [$elapsed, $ok];
}

/**
 * Throughput driver: $connections connections, each performing $perConn ping/echo
 * round-trips back to back, all multiplexed concurrently. Returns
 * [elapsed seconds, total round-trips completed].
 *
 * @return array{float, int}
 */
function socketBenchThroughput(string $host, int $port, int $connections, int $perConn, string $message): array
{
    $frame = socketBenchFrame($message);

    $sockets   = [];
    $buffers   = [];
    $remaining = [];

    for ($i = 0; $i < $connections; $i++) {
        $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);

        if (!is_resource($socket)) {
            fwrite(STDERR, "connect failed: $errstr\n");
            exit(1);
        }

        stream_set_blocking($socket, false);

        $sockets[$i]   = $socket;
        $buffers[$i]   = '';
        $remaining[$i] = $perConn;
    }

    $start = microtime(true);

    foreach ($sockets as $socket) {
        fwrite($socket, $frame);
    }

    $ok     = 0;
    $active = $connections;

    while ($active > 0) {
        $read  = $sockets;
        $write = null;
        $except = null;

        if ($read === []) {
            break;
        }

        if (@stream_select($read, $write, $except, 30) === 0) {
            break;
        }

        foreach ($read as $socket) {
            $index = (int) array_search($socket, $sockets, true);
            $chunk = fread($socket, 65536);

            if ($chunk === '' || $chunk === false) {
                fclose($socket);
                unset($sockets[$index]);
                $active--;

                continue;
            }

            $buffers[$index] .= $chunk;

            while (socketBenchHasFrame($buffers[$index])) {
                $length          = (int) (unpack('N', substr($buffers[$index], 0, 4))[1]);
                $buffers[$index] = substr($buffers[$index], 4 + $length);

                $ok++;
                $remaining[$index]--;

                if ($remaining[$index] > 0) {
                    fwrite($socket, $frame);

                    continue;
                }

                fclose($socket);
                unset($sockets[$index]);
                $active--;

                break;
            }
        }
    }

    $elapsed = microtime(true) - $start;

    foreach ($sockets as $socket) {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    return [$elapsed, $ok];
}

/**
 * Whether $buffer holds at least one whole length-prefixed frame.
 */
function socketBenchHasFrame(string $buffer): bool
{
    if (strlen($buffer) < 4) {
        return false;
    }

    $length = (int) (unpack('N', substr($buffer, 0, 4))[1]);

    return strlen($buffer) >= 4 + $length;
}
