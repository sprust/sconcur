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
 *   *    /meta              -> 200, body = "<proto> <host>" (connection metadata)
 *   GET  /cookies           -> 200 with two Set-Cookie headers (multi-value demo)
 *   GET  /stream            -> 200 chunked, body streamed in parts (streaming demo)
 *   GET  /sleep             -> sleeps 500ms, then 200 "slept" (concurrency demo)
 *   GET  /throw             -> handler throws -> framework answers 500
 *   GET  /status/{code}     -> responds with the given status code
 *   (anything else)         -> 404 "not found"
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/servers/http/http-server.php [addr]
 */

$address = $argv[1] ?? '0.0.0.0:8080';

$sleeper = new Sleeper();

$server = new HttpServer(address: $address);

$server->serve(static function (Request $request) use ($sleeper): Response|StreamedResponse {
    echo "$request->method $request->path\n";

    if ($request->path === '/method') {
        return new Response(body: $request->method);
    }

    if ($request->path === '/echo') {
        return new Response(body: $request->body);
    }

    if ($request->path === '/meta') {
        return new Response(body: "$request->proto $request->host");
    }

    if ($request->method !== 'GET') {
        return new Response(body: 'method not allowed', status: 405);
    }

    return match (true) {
        $request->path === '/'        => new Response(body: 'ok'),
        $request->path === '/cookies' => new Response(
            body: 'cookies',
            headers: ['Set-Cookie' => ['a=1', 'b=2']],
        ),
        $request->path === '/stream' => streamRoute($sleeper),
        $request->path === '/sleep'  => sleepRoute($sleeper),
        $request->path === '/throw' => throw new RuntimeException('boom in handler'),
        str_starts_with($request->path, '/status/') => statusRoute($request->path),
        default => new Response(body: 'not found', status: 404),
    };
});

function sleepRoute(Sleeper $sleeper): Response
{
    $sleeper->msleep(milliseconds: 500);

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
