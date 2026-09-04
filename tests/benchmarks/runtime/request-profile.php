<?php

declare(strict_types=1);

/**
 * Where the per-request PSR-7 construction goes, stage by stage.
 *
 * Every request builds a full ServerRequest — URI parsed, headers set one by
 * one, query decoded — while a typical handler reads the path and the method.
 * Whether deferring any of that is worth it depends on how those microseconds
 * actually split, which is what this measures.
 *
 * The stages are measured by rebuilding decodeRequest's steps here rather than
 * by instrumenting it: the private method is called whole for the total, and the
 * partial shapes below account for it. A stage's cost is the difference between
 * the shape that includes it and the shape that does not, which is why they are
 * cumulative and measured in the same loop.
 *
 * Run: php -d extension=./ext/build/sconcur.so tests/benchmarks/runtime/request-profile.php
 * Under the sampling profiler: make profile-php c="tests/benchmarks/runtime/request-profile.php"
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\HttpServer\Dto\RequestBody;
use SConcur\Features\HttpServer\Dto\RequestBodyStream;
use SConcur\Transport\MessagePackTransport;

error_reporting(E_ALL);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../../../vendor/autoload.php';

const ITERATIONS = 20_000;
const WARMUP     = 2_000;

/**
 * Stages are measured round by round rather than one stage to completion, and
 * the median of the rounds is reported: a stage that runs alone owns whatever
 * the host was doing during its window, and on a busy machine that alone put
 * cumulative stages below the total that contains them.
 */
const ROUNDS = 5;

/**
 * A request in the shape the core actually emits (payloads.rs, RequestEvent::encode):
 * the ten short keys, headers as name => list of values.
 *
 * The header set is what a browser or wrk sends, because the withHeader loop is
 * the stage most likely to dominate and its cost is per header.
 */
function requestPayload(string $query = ''): string
{
    return msgpack_pack([
        'rid' => 'r-0000000000000001',
        'mt'  => 'GET',
        'pt'  => '/users/42/orders',
        'qr'  => $query,
        'bk'  => '',
        'ra'  => '172.18.0.1:54321',
        'ho'  => '172.18.0.5:8080',
        'pr'  => 'HTTP/1.1',
        'bd'  => '',
        'hd'  => [
            'Host'            => ['172.18.0.5:8080'],
            'User-Agent'      => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36'],
            'Accept'          => ['text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'],
            'Accept-Language' => ['en-US,en;q=0.9'],
            'Accept-Encoding' => ['gzip, deflate'],
            'Connection'      => ['keep-alive'],
            'Cache-Control'   => ['max-age=0'],
        ],
    ]);
}

/**
 * @param Closure(): mixed $subject
 *
 * @return float microseconds per call
 */
function timePerCall(Closure $subject): float
{
    $started = hrtime(true);

    for ($i = 0; $i < ITERATIONS; $i++) {
        $subject();
    }

    return (hrtime(true) - $started) / 1_000 / ITERATIONS;
}

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    sort($values);

    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;
}

// Bound rather than reflected per call: ReflectionMethod::invoke costs about as
// much as a stage, which would land inside the number it is measuring.
$decodeRequest = Closure::bind(
    static fn (Psr17Factory $factory, string $payload): array => HttpServer::decodeRequest($factory, $payload),
    null,
    HttpServer::class,
);

$factory = new Psr17Factory();

$plain   = requestPayload();
$queried = requestPayload('page=3&per_page=50&sort=-created_at&filter%5Bstatus%5D=paid');

/** @var array<string, mixed> $data */
$data    = MessagePackTransport::unpack($plain);
$headers = (array) $data['hd'];
$uri     = 'http://' . $data['ho'] . $data['pt'];

$stages = [
    // The whole thing, exactly as a request pays for it.
    'decodeRequest (whole)' => static fn (): array => $decodeRequest($factory, $plain),

    // The same with a query string, which adds parse_str and one more wither.
    'decodeRequest (+query)' => static fn (): array => $decodeRequest($factory, $queried),

    // The stages, cumulative: each shape is the previous one plus a step.
    'unpack only' => static fn (): array => (array) MessagePackTransport::unpack($plain),

    'unpack + createServerRequest' => static function () use ($factory, $plain, $uri) {
        MessagePackTransport::unpack($plain);

        return $factory->createServerRequest('GET', $uri, []);
    },

    'unpack + create + headers' => static function () use ($factory, $plain, $uri, $headers) {
        MessagePackTransport::unpack($plain);

        $request = $factory->createServerRequest('GET', $uri, []);

        foreach ($headers as $name => $values) {
            $request = $request->withHeader((string) $name, array_values((array) $values));
        }

        return $request;
    },

    'unpack + create + headers + body' => static function () use ($factory, $plain, $uri, $headers) {
        MessagePackTransport::unpack($plain);

        $request = $factory->createServerRequest('GET', $uri, []);

        foreach ($headers as $name => $values) {
            $request = $request->withHeader((string) $name, array_values((array) $values));
        }

        return $request->withBody(new RequestBodyStream(new RequestBody(firstChunk: '', bodyKey: '')));
    },

    // The alternative the profile suggests: the headers handed to the constructor
    // in one go instead of seven withHeader calls, each of which clones the
    // immutable request. Not reachable through PSR-17 — createServerRequest takes
    // no headers — so this prices what a lazy or implementation-aware request
    // could save, not something the current code could just switch to.
    'unpack + construct with headers' => static function () use ($plain, $uri, $headers) {
        MessagePackTransport::unpack($plain);

        return new ServerRequest('GET', $uri, array_map(
            static fn (mixed $values): array => array_values((array) $values),
            $headers,
        ));
    },

    // The URI on its own: the factory parses the string into a Uri object.
    'createServerRequest only' => static fn () => $factory->createServerRequest('GET', $uri, []),

    // What the handler actually receives now: the same decode, then the two reads
    // a router makes. The difference from the row above is what the deferral
    // saves when nothing asks for a header.
    'decodeRequest + method/path' => static function () use ($decodeRequest, $factory, $plain): string {
        [, $request] = $decodeRequest($factory, $plain);

        return $request->getMethod() . $request->getUri()->getPath();
    },

    // The same, for a handler that does read a header: the deferral has to be
    // paid back here, and this row is what it costs to pay it.
    'decodeRequest + one header' => static function () use ($decodeRequest, $factory, $plain): string {
        [, $request] = $decodeRequest($factory, $plain);

        return $request->getHeaderLine('user-agent');
    },

    // What a typical handler reads out of all of it.
    'getMethod + getPath' => static function () use ($factory, $uri) {
        static $request = null;

        $request ??= $factory->createServerRequest('GET', $uri, []);

        return $request->getMethod() . $request->getUri()->getPath();
    },
];

foreach ($stages as $subject) {
    for ($i = 0; $i < WARMUP; $i++) {
        $subject();
    }
}

/** @var array<string, list<float>> $rounds */
$rounds = [];

for ($round = 0; $round < ROUNDS; $round++) {
    foreach ($stages as $name => $subject) {
        $rounds[$name][] = timePerCall($subject);
    }
}

printf(
    "%d iterations per stage, %d warm-up, %d interleaved rounds (median)\n\n",
    ITERATIONS,
    WARMUP,
    ROUNDS,
);
printf("%-34s %10s %10s %10s\n", 'stage', 'us/call', 'min', 'max');
printf("%-34s %10s %10s %10s\n", str_repeat('-', 34), str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 10));

foreach ($rounds as $name => $values) {
    printf("%-34s %10.3f %10.3f %10.3f\n", $name, median($values), min($values), max($values));
}
