<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Features\HttpClient\DownloadFileMode;
use SConcur\Features\HttpClient\HttpClient;

require_once __DIR__ . '/_benchmarker.php';
require_once __DIR__ . '/_http_bench.php';

/**
 * HTTP-client download benchmark: N downloads of a 4 MiB body to files. The sink
 * path copies the body to disk inside the Go extension (io.Copy) and never buffers
 * it in PHP; the async run fans the downloads out through a WaitGroup, so its total
 * time stays ≈ one download while native/sync scale with N. Native is a streamed
 * copy from PHP's HTTP stream wrapper into a file.
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/benchmarks/http-client-download.php [total] [logProcess]
 */

const DOWNLOAD_BENCH_SIZE_BYTES = 4 * 1024 * 1024;

$benchmarker = new Benchmarker(
    name: 'http-client-download',
);

$host    = '127.0.0.1';
$port    = downloadBenchFreePort($host);
$baseUrl = "http://$host:$port/big/" . DOWNLOAD_BENCH_SIZE_BYTES;

// One demo server (no SO_REUSEPORT): the async fan-out is served by its own
// concurrency, not by sibling processes.
$procs = benchSpawnServers($host, $port, 1, false);

$storageDirectory = sys_get_temp_dir();

$psr17Factory = new Psr17Factory();
$client       = new HttpClient(
    responseFactory: $psr17Factory,
);

$nativeIndex = 0;
$syncIndex   = 0;
$asyncIndex  = 0;

$pathFor = static function (string $kind, int $index) use ($storageDirectory): string {
    return $storageDirectory . '/sconcur_download_bench_' . $kind . '_' . $index;
};

try {
    $benchmarker->run(
        nativeCallback: static function () use ($baseUrl, $pathFor, &$nativeIndex): void {
            $path = $pathFor('native', $nativeIndex++);

            $input  = fopen($baseUrl, 'r');
            $output = fopen($path, 'w');

            stream_copy_to_stream($input, $output);

            fclose($input);
            fclose($output);
        },
        syncCallback: static function () use ($client, $psr17Factory, $baseUrl, $pathFor, &$syncIndex): void {
            $client->download(
                request: $psr17Factory->createRequest('GET', $baseUrl),
                path: $pathFor('sync', $syncIndex++),
                mode: DownloadFileMode::Replace,
            );
        },
        asyncCallback: static function () use ($client, $psr17Factory, $baseUrl, $pathFor, &$asyncIndex): void {
            $client->download(
                request: $psr17Factory->createRequest('GET', $baseUrl),
                path: $pathFor('async', $asyncIndex++),
                mode: DownloadFileMode::Replace,
            );
        },
    );
} finally {
    benchStopServers($procs);

    foreach (glob($storageDirectory . '/sconcur_download_bench_*') ?: [] as $path) {
        unlink($path);
    }
}

/**
 * Allocates a free TCP port on $host by binding an ephemeral socket and reading
 * back the assigned port.
 */
function downloadBenchFreePort(string $host): int
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
