<?php

declare(strict_types=1);

/**
 * Helpers for the WebSocket-server benchmarks: resolve the running `servers` container
 * ws pool (3 reusePort workers), check it is reachable, perform the upgrade handshake,
 * and drive concurrent WebSocket round-trips.
 *
 * The pool is the master-supervised server from docker-compose's `servers` container.
 * Benchmarks run inside the `php` container, so the pool is reachable by its compose
 * service hostname (`servers`) over the internal docker network, bypassing the
 * published-port NAT.
 *
 * Client frames are masked, server frames are not — the bench handles both directions
 * of the WebSocket framing itself (opcode, mask, 7/16/64-bit length).
 */

const WS_BENCH_ACCEPT_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

const WS_BENCH_OPCODE_TEXT   = 0x1;
const WS_BENCH_OPCODE_BINARY = 0x2;
const WS_BENCH_OPCODE_CLOSE  = 0x8;

/**
 * Host of the ws server pool (compose service hostname by default; override with
 * BENCH_WS_HOST, e.g. 127.0.0.1 to hit the published port from the host).
 */
function wsBenchHost(): string
{
    return getenv('BENCH_WS_HOST') ?: 'servers';
}

/**
 * Port of the ws server pool (the in-container listen port by default; override with
 * BENCH_WS_PORT, e.g. 29200 for the published host port).
 */
function wsBenchPort(): int
{
    return (int) (getenv('BENCH_WS_PORT') ?: 9200);
}

/**
 * Aborts the benchmark with a clear hint if the ws server pool is unreachable.
 */
function wsBenchRequireServers(string $host, int $port): void
{
    $connection = @fsockopen($host, $port, $errno, $errstr, 2.0);

    if (!is_resource($connection)) {
        fwrite(STDERR, "ws server pool not reachable at $host:$port ($errstr).\n");
        fwrite(STDERR, "Start the `servers` container with `make up` (or `make servers-restart` to rebuild it).\n");

        exit(1);
    }

    fclose($connection);
}

/**
 * Opens a TCP connection and performs the WebSocket upgrade handshake (blocking).
 * Returns the upgraded socket (left in blocking mode; the caller switches it to
 * non-blocking for the message phase). Aborts the benchmark on any failure.
 *
 * @return resource
 */
function wsBenchConnect(string $host, int $port): mixed
{
    $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);

    if (!is_resource($socket)) {
        fwrite(STDERR, "connect failed: $errstr\n");
        exit(1);
    }

    stream_set_timeout($socket, 10);

    $key = base64_encode(random_bytes(16));

    $request = "GET / HTTP/1.1\r\n"
        . "Host: $host:$port\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: $key\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . "\r\n";

    fwrite($socket, $request);

    $response = '';

    while (!str_contains($response, "\r\n\r\n")) {
        $chunk = fread($socket, 1);

        if ($chunk === false || $chunk === '') {
            $info = stream_get_meta_data($socket);

            if ($info['timed_out'] || feof($socket)) {
                fwrite(STDERR, "ws handshake read failed\n");
                exit(1);
            }

            continue;
        }

        $response .= $chunk;
    }

    if (!str_contains($response, ' 101 ')) {
        fwrite(STDERR, "ws handshake did not return 101:\n$response\n");
        exit(1);
    }

    return $socket;
}

/**
 * Encodes one masked client frame (FIN set, single frame) for the given opcode.
 */
function wsBenchFrame(string $payload, int $opcode = WS_BENCH_OPCODE_TEXT): string
{
    $frame  = chr(0x80 | $opcode);
    $length = strlen($payload);

    if ($length < 126) {
        $frame .= chr(0x80 | $length);
    } elseif ($length < 65536) {
        $frame .= chr(0x80 | 126) . pack('n', $length);
    } else {
        $frame .= chr(0x80 | 127) . pack('J', $length);
    }

    $maskKey = random_bytes(4);

    $frame .= $maskKey . wsBenchApplyMask($payload, $maskKey);

    return $frame;
}

/**
 * XORs the payload with the 4-byte masking key (its own inverse, used both ways).
 */
function wsBenchApplyMask(string $data, string $maskKey): string
{
    $masked = '';

    for ($index = 0, $length = strlen($data); $index < $length; $index++) {
        $masked .= $data[$index] ^ $maskKey[$index % 4];
    }

    return $masked;
}

/**
 * Extracts the first complete WebSocket frame from $buffer, consuming it in place.
 * Returns its opcode, or null if the buffer does not yet hold a whole frame.
 */
function wsBenchExtractFrame(string &$buffer): ?int
{
    if (strlen($buffer) < 2) {
        return null;
    }

    $firstByte  = ord($buffer[0]);
    $secondByte = ord($buffer[1]);

    $opcode = $firstByte & 0x0F;
    $masked = ($secondByte & 0x80) !== 0;
    $length = $secondByte & 0x7F;

    $offset = 2;

    if ($length === 126) {
        if (strlen($buffer) < $offset + 2) {
            return null;
        }

        $length = (int) (unpack('n', substr($buffer, $offset, 2))[1]);
        $offset += 2;
    } elseif ($length === 127) {
        if (strlen($buffer) < $offset + 8) {
            return null;
        }

        $length = (int) (unpack('J', substr($buffer, $offset, 8))[1]);
        $offset += 8;
    }

    if ($masked) {
        $offset += 4;
    }

    if (strlen($buffer) < $offset + $length) {
        return null;
    }

    $buffer = substr($buffer, $offset + $length);

    return $opcode;
}

/**
 * Opens $connections concurrent connections (each already upgraded), sends one
 * $message frame on each, and reads exactly one reply data frame from each
 * (non-blocking, multiplexed via stream_select). Returns [elapsed seconds,
 * replies-received]. The handshakes happen before the timed section, like the socket
 * benchmark's connects.
 *
 * @return array{float, int}
 */
function wsBenchConcurrentOneShot(string $host, int $port, int $connections, string $message): array
{
    $frame = wsBenchFrame($message);

    $sockets = [];
    $buffers = [];

    for ($i = 0; $i < $connections; $i++) {
        $socket = wsBenchConnect($host, $port);

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
        $read   = $sockets;
        $write  = null;
        $except = null;

        if ($read === []) {
            break;
        }

        if (@stream_select($read, $write, $except, 60) === 0) {
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

            if (wsBenchConsumeOneDataFrame($buffers[$index])) {
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
 * round-trips back to back, all multiplexed concurrently. Returns [elapsed seconds,
 * total round-trips completed].
 *
 * @return array{float, int}
 */
function wsBenchThroughput(string $host, int $port, int $connections, int $perConn, string $message): array
{
    $frame = wsBenchFrame($message);

    $sockets   = [];
    $buffers   = [];
    $remaining = [];

    for ($i = 0; $i < $connections; $i++) {
        $socket = wsBenchConnect($host, $port);

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
        $read   = $sockets;
        $write  = null;
        $except = null;

        if ($read === []) {
            break;
        }

        if (@stream_select($read, $write, $except, 60) === 0) {
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

            while (wsBenchConsumeOneDataFrame($buffers[$index])) {
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
 * Consumes whole frames from the front of $buffer until a data frame (text/binary) is
 * found and returns true, or returns false if no whole frame remains. Control frames
 * (ping/pong) are skipped; a close frame is treated as a data frame for counting so a
 * driver stops waiting on that connection.
 */
function wsBenchConsumeOneDataFrame(string &$buffer): bool
{
    while (true) {
        $opcode = wsBenchExtractFrame($buffer);

        if ($opcode === null) {
            return false;
        }

        if ($opcode === WS_BENCH_OPCODE_TEXT
            || $opcode === WS_BENCH_OPCODE_BINARY
            || $opcode === WS_BENCH_OPCODE_CLOSE) {
            return true;
        }
    }
}
