<?php

declare(strict_types=1);

// Reuse the generic process helpers (benchRoot, benchAliveCount, benchStopServers).
require_once __DIR__ . '/_http_bench.php';

/**
 * Helpers for the socket-server benchmarks: spawn N demo socket servers on one
 * port, drive concurrent length-prefix-framed round-trips, measure, tear down.
 */

/**
 * Spawns $workers demo socket servers on $host:$port (SO_REUSEPORT when >1) and
 * waits until the port answers.
 *
 * @return array<int, resource>
 */
function socketBenchSpawnServers(string $host, int $port, int $workers, bool $reusePort): array
{
    $root      = benchRoot();
    $extension = $root . '/ext/build/sconcur.so';
    $script    = $root . '/tests/servers/socket/socket-server.php';

    $command = ['php', '-d', 'extension=' . $extension, $script, "--address=$host:$port"];

    if ($reusePort) {
        $command[] = '--reusePort=1';
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];

    $procs = [];

    for ($i = 0; $i < $workers; $i++) {
        $proc = proc_open($command, $descriptors, $pipes, $root);

        if (!is_resource($proc)) {
            fwrite(STDERR, "failed to spawn socket worker $i\n");
            exit(1);
        }

        fclose($pipes[0]);

        $procs[] = $proc;
    }

    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        $connection = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if (is_resource($connection)) {
            fclose($connection);

            return $procs;
        }

        usleep(50_000);
    }

    benchStopServers($procs);

    fwrite(STDERR, "socket servers not reachable on $host:$port\n");
    exit(1);
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
