<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Features\HttpClient\HttpClient;

/**
 * The only benchmark that leaves the machine: it hits a real host on the public
 * internet (google.com by default, override with BENCH_URL), so DNS, TLS and the
 * remote server dominate the numbers. Use it as a smoke test of the real network
 * path, not as a comparable measurement — the in-container `client.php` against
 * the demo server is the reproducible one.
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/benchmarks/http/client-external.php [total]
 */

require_once __DIR__ . '/../lib/benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'http-client-external',
);

$baseUrl = getenv('BENCH_URL') ?: 'https://google.com';

$psr17Factory = new Psr17Factory();
$client       = new HttpClient(
    responseFactory: $psr17Factory,
);

$benchmarker->run(
    nativeCallback: static function () use ($baseUrl): void {
        $context = stream_context_create(['http' => ['timeout' => 120]]);

        file_get_contents($baseUrl, false, $context);
    },
    syncCallback: static function () use ($client, $psr17Factory, $baseUrl): void {
        $client->sendRequest($psr17Factory->createRequest('GET', $baseUrl));
    },
    asyncCallback: static function () use ($client, $psr17Factory, $baseUrl): void {
        $response = $client->sendRequest($psr17Factory->createRequest('GET', $baseUrl));

        // Drain the (tiny) body so the response is fully consumed.
        (string) $response->getBody();
    },
);

