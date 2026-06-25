<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Features\HttpClient\DownloadFileMode;
use SConcur\Features\HttpClient\HttpClient;

require_once __DIR__ . '/_benchmarker.php';
require_once __DIR__ . '/_http_bench.php';

/**
 * HTTP-client download benchmark: N downloads of a 4 MiB body to files from the
 * running `servers` HTTP pool. The sink path copies the body to disk inside the Go
 * extension (io.Copy) and never buffers it in PHP; the async run fans the downloads
 * out through a WaitGroup, so its total time stays ≈ one download while native/sync
 * scale with N. Native is a streamed copy from PHP's HTTP stream wrapper into a file.
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/benchmarks/http-client-download.php [total] [logProcess]
 */

const DOWNLOAD_BENCH_SIZE_BYTES = 4 * 1024 * 1024;

$benchmarker = new Benchmarker(
    name: 'http-client-download',
);

$host    = benchHttpHost();
$port    = benchHttpPort();
$baseUrl = "http://$host:$port/big/" . DOWNLOAD_BENCH_SIZE_BYTES;

benchRequireHttpServers($host, $port);

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
    foreach (glob($storageDirectory . '/sconcur_download_bench_*') ?: [] as $path) {
        unlink($path);
    }
}
