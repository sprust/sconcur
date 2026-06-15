<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Features\HttpClient\HttpClient;

require_once __DIR__ . '/_benchmarker.php';
require_once __DIR__ . '/_http_bench.php';

/**
 * HTTP-client benchmark: N requests to an I/O-bound endpoint (/msleep) of a real
 * SConcur demo server. The async run fires them «веером» through a WaitGroup —
 * its total time stays ≈ one request, while native/sync run sequentially and
 * scale with N.
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/benchmarks/http-client.php [total] [logProcess]
 */

$benchmarker = new Benchmarker(
    name: 'http-client',
);

$host    = '127.0.0.1';
$port    = benchFreePort($host);
$sleepMs = 100;
$baseUrl = "http://$host:$port/msleep/$sleepMs";

// One demo server (no SO_REUSEPORT): the async fan-out is served by its own
// concurrency, not by sibling processes.
$procs = benchSpawnServers($host, $port, 1, false);

$factory = new Psr17Factory();
$client  = new HttpClient($factory);

try {
    $benchmarker->run(
        nativeCallback: static function () use ($baseUrl): void {
            $context = stream_context_create(['http' => ['timeout' => 120]]);

            file_get_contents($baseUrl, false, $context);
        },
        syncCallback: static function () use ($client, $factory, $baseUrl): void {
            $client->sendRequest($factory->createRequest('GET', $baseUrl));
        },
        asyncCallback: static function () use ($client, $factory, $baseUrl): void {
            $response = $client->sendRequest($factory->createRequest('GET', $baseUrl));

            // Drain the (tiny) body so the response is fully consumed.
            (string) $response->getBody();
        },
    );
} finally {
    benchStopServers($procs);
}

/**
 * Allocates a free TCP port on $host by binding an ephemeral socket and reading
 * back the assigned port.
 */
function benchFreePort(string $host): int
{
    $socket = stream_socket_server("tcp://$host:0", $errno, $errstr);

    if ($socket === false) {
        fwrite(STDERR, "could not allocate a port: $errstr\n");
        exit(1);
    }

    $name = (string) stream_socket_get_name($socket, false);

    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}
