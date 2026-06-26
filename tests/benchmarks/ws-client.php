<?php

declare(strict_types=1);

use SConcur\Features\WsClient\WsClient;

require_once __DIR__ . '/_benchmarker.php';
require_once __DIR__ . '/_ws_bench.php';

/**
 * WebSocket-client benchmark: N round-trips to an I/O-bound endpoint ("msleep:<ms>")
 * of the running `servers` ws pool. The async run fires them «веером» through a
 * WaitGroup — its total time stays ≈ one round-trip, while native (raw PHP WebSocket
 * framing) and sync (WsClient outside a WaitGroup) run sequentially and scale with N.
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/benchmarks/ws-client.php [total] [logProcess]
 */

$benchmarker = new Benchmarker(
    name: 'ws-client',
);

$host    = wsBenchHost();
$port    = wsBenchPort();
$sleepMs = 100;
$message = "msleep:$sleepMs";
$url     = "ws://$host:$port/";

wsBenchRequireServers($host, $port);

$client = new WsClient();

$benchmarker->run(
    nativeCallback: static function () use ($host, $port, $message): void {
        wsClientBenchNativeRoundTrip($host, $port, $message);
    },
    syncCallback: static function () use ($client, $url, $message): void {
        wsClientBenchRoundTrip($client, $url, $message);
    },
    asyncCallback: static function () use ($client, $url, $message): void {
        wsClientBenchRoundTrip($client, $url, $message);
    },
);

/**
 * One SConcur WsClient round-trip: connect, send one message, read one reply, close.
 * Used by both the sync and async runs (the async one runs it in a coroutine).
 */
function wsClientBenchRoundTrip(WsClient $client, string $url, string $message): void
{
    $connection = $client->connect($url);

    $connection->write($message);

    if ($connection->read() === null) {
        fwrite(STDERR, "ws-client: no reply message\n");
        exit(1);
    }

    $connection->close();
}

/**
 * One raw-PHP WebSocket round-trip (the native baseline): connect, upgrade, send one
 * masked frame, read exactly one reply data frame, close.
 */
function wsClientBenchNativeRoundTrip(string $host, int $port, string $message): void
{
    $socket = wsBenchConnect($host, $port);

    fwrite($socket, wsBenchFrame($message));

    $buffer = '';

    while (!wsBenchConsumeOneDataFrame($buffer)) {
        $chunk = fread($socket, 65536);

        if ($chunk === false || $chunk === '') {
            $info = stream_get_meta_data($socket);

            if ($info['timed_out'] || feof($socket)) {
                fwrite(STDERR, "native ws read failed\n");
                exit(1);
            }

            usleep(1_000);

            continue;
        }

        $buffer .= $chunk;
    }

    fclose($socket);
}
