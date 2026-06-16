<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Features\HttpClient\HttpClient;

require_once __DIR__ . '/_benchmarker.php';
require_once __DIR__ . '/_http_bench.php';

$benchmarker = new Benchmarker(
    name: 'http-client-google',
);

$host    = '127.0.0.1';
$baseUrl = "https://google.com";

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

