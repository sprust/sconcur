<?php

declare(strict_types=1);

use SConcur\Features\SocketClient\SocketClient;

require_once __DIR__ . '/_benchmarker.php';
require_once __DIR__ . '/_socket_bench.php';

/**
 * Socket-client benchmark: N length-prefix-framed round-trips to an I/O-bound
 * endpoint ("msleep:<ms>") of the running `servers` socket pool. The async run fires
 * them «веером» through a WaitGroup — its total time stays ≈ one round-trip, while
 * native (raw PHP sockets) and sync (SocketClient outside a WaitGroup) run
 * sequentially and scale with N.
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/benchmarks/socket-client.php [total] [logProcess]
 */

$benchmarker = new Benchmarker(
    name: 'socket-client',
);

$host    = socketBenchHost();
$port    = socketBenchPort();
$sleepMs = 100;
$message = "msleep:$sleepMs";
$address = "$host:$port";

socketBenchRequireServers($host, $port);

$client = new SocketClient();

$benchmarker->run(
    nativeCallback: static function () use ($host, $port, $message): void {
        socketClientBenchNativeRoundTrip($host, $port, $message);
    },
    syncCallback: static function () use ($client, $address, $message): void {
        socketClientBenchRoundTrip($client, $address, $message);
    },
    asyncCallback: static function () use ($client, $address, $message): void {
        socketClientBenchRoundTrip($client, $address, $message);
    },
);

/**
 * One SConcur SocketClient round-trip: connect, send one frame, read one reply,
 * close. Used by both the sync and async runs (the async one runs it in a coroutine).
 */
function socketClientBenchRoundTrip(SocketClient $client, string $address, string $message): void
{
    $connection = $client->connect($address);

    $connection->write($message);

    if ($connection->read() === null) {
        fwrite(STDERR, "socket-client: no reply frame\n");
        exit(1);
    }

    $connection->close();
}

/**
 * One raw-PHP-socket round-trip (the native baseline): connect, send one
 * length-prefixed frame, read exactly one reply frame, close.
 */
function socketClientBenchNativeRoundTrip(string $host, int $port, string $message): void
{
    $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);

    if (!is_resource($socket)) {
        fwrite(STDERR, "native connect failed: $errstr\n");
        exit(1);
    }

    stream_set_timeout($socket, 120);

    fwrite($socket, socketBenchFrame($message));

    $header = socketClientBenchReadExactly($socket, 4);
    $length = (int) (unpack('N', $header)[1]);

    if ($length > 0) {
        socketClientBenchReadExactly($socket, $length);
    }

    fclose($socket);
}

/**
 * Reads exactly $length bytes from a blocking socket, or aborts the benchmark on EOF
 * or timeout.
 *
 * @param resource $socket
 */
function socketClientBenchReadExactly(mixed $socket, int $length): string
{
    $buffer = '';

    while (strlen($buffer) < $length) {
        $chunk = fread($socket, $length - strlen($buffer));

        if ($chunk === false || $chunk === '') {
            $info = stream_get_meta_data($socket);

            if ($info['timed_out'] || feof($socket)) {
                fwrite(STDERR, "native read failed\n");
                exit(1);
            }

            usleep(1_000);

            continue;
        }

        $buffer .= $chunk;
    }

    return $buffer;
}
