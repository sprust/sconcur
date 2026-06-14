<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use SConcur\Features\HttpServer\Dto\Request;
use SConcur\Features\HttpServer\Dto\Response;
use SConcur\Features\HttpServer\Dto\ResponseStream;
use SConcur\Features\HttpServer\Dto\StreamedResponse;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\Sleeper\Sleeper;

/**
 * Demo / test HTTP server. Routes:
 *   GET  /                  -> 200 "ok"
 *   *    /method            -> 200, body = request method (GET/POST/...)
 *   *    /echo              -> 200, body = the request body (echo)
 *   *    /query             -> 200, body = the raw query string
 *   *    /echo-header       -> 200, body = the "X-Echo" request header (joined)
 *   *    /meta              -> 200, body = "<proto> <host>" (connection metadata)
 *   GET  /empty             -> 200 with an empty body
 *   GET  /cookies           -> 200 with two Set-Cookie headers (multi-value demo)
 *   GET  /stream            -> 200 chunked, body streamed in parts (streaming demo)
 *   GET  /msleep/{ms}       -> sleeps {ms}, then 200 "slept" (concurrency demo)
 *   GET  /throw             -> handler throws -> framework answers 500
 *   GET  /status/{code}     -> responds with the given status code
 *   (anything else)         -> 404 "not found"
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/servers/http/http-server.php [addr] [--option=value ...]
 *
 * Launch options (override the HttpServer defaults; all integers) are named
 * exactly like the HttpServer constructor parameters, passed as --name=value:
 *   --readHeaderTimeoutMs  --readTimeoutMs  --writeTimeoutMs  --idleTimeoutMs
 *   --shutdownTimeoutMs  --maxRequestBody  --maxConcurrency  --handlerTimeoutMs
 *   --reusePort (0/1)
 */

$address = $argv[1] ?? '0.0.0.0:8080';

$sleeper = new Sleeper();

// Accepted launch options — exactly the HttpServer constructor parameter names.
// Only the ones actually passed override the defaults (named-arg unpacking below).
$allowedIntOptions = [
    'readHeaderTimeoutMs',
    'readTimeoutMs',
    'writeTimeoutMs',
    'idleTimeoutMs',
    'shutdownTimeoutMs',
    'maxRequestBody',
    'maxConcurrency',
    'handlerTimeoutMs',
];

$overrides = [];

foreach (array_slice($argv, 2) as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }

    [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');

    if ($name === 'reusePort') {
        $overrides['reusePort'] = (bool) (int) $value;
    } elseif (in_array($name, $allowedIntOptions, true)) {
        $overrides[$name] = (int) $value;
    }
}

// Spread as named args; address first, overrides take precedence over defaults.
$server = new HttpServer(...['address' => $address, ...$overrides]);

$server->serve(static function (Request $request) use ($sleeper): Response|StreamedResponse {
    echo "$request->method $request->path\n";

    if ($request->path === '/method') {
        return new Response(body: $request->method);
    }

    if ($request->path === '/echo') {
        return new Response(body: $request->body);
    }

    if ($request->path === '/query') {
        return new Response(body: $request->query);
    }

    if ($request->path === '/echo-header') {
        return new Response(body: headerValue($request, 'X-Echo'));
    }

    if ($request->path === '/meta') {
        return new Response(body: "$request->proto $request->host");
    }

    if ($request->method !== 'GET') {
        return new Response(body: 'method not allowed', status: 405);
    }

    return match (true) {
        $request->path === '/'        => new Response(body: 'ok'),
        $request->path === '/empty'   => new Response(),
        $request->path === '/cookies' => new Response(
            body: 'cookies',
            headers: ['Set-Cookie' => ['a=1', 'b=2']],
        ),
        $request->path === '/stream' => streamRoute($sleeper),
        $request->path === '/throw'  => throw new RuntimeException('boom in handler'),
        str_starts_with($request->path, '/msleep/') => msleepRoute($sleeper, $request->path),
        str_starts_with($request->path, '/status/') => statusRoute($request->path),
        default => new Response(body: 'not found', status: 404),
    };
});

/**
 * Returns the value(s) of a request header (case-insensitive), joined by ",".
 */
function headerValue(Request $request, string $name): string
{
    foreach ($request->headers as $headerName => $values) {
        if (strcasecmp($headerName, $name) === 0) {
            return implode(',', $values);
        }
    }

    return '';
}

function msleepRoute(Sleeper $sleeper, string $path): Response
{
    $milliseconds = (int) substr($path, strlen('/msleep/'));

    $sleeper->msleep(milliseconds: $milliseconds);

    return new Response(body: 'slept');
}

function streamRoute(Sleeper $sleeper): StreamedResponse
{
    return new StreamedResponse(
        writer: static function (ResponseStream $out) use ($sleeper): void {
            foreach (['a', 'b', 'c'] as $part) {
                $out->write("chunk-$part\n");

                // Async work between chunks: other requests keep being served.
                $sleeper->msleep(milliseconds: 50);
            }
        },
        headers: ['Content-Type' => 'text/plain'],
    );
}

function statusRoute(string $path): Response
{
    $code = (int) substr($path, strlen('/status/'));

    if ($code < 100 || $code > 599) {
        return new Response(body: 'bad status', status: 400);
    }

    return new Response(body: 'status ' . $code, status: $code);
}
