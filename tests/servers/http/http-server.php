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
 *   GET  /pid               -> 200, body = this process pid (used by the worker-master tests)
 *   *    /method            -> 200, body = request method (GET/POST/...)
 *   *    /echo              -> 200, body = the request body (echo, full read)
 *   *    /upload            -> 200, body = sha256 of the request body (streamed read)
 *   *    /query             -> 200, body = the raw query string
 *   *    /echo-header       -> 200, body = the "X-Echo" request header (joined)
 *   *    /meta              -> 200, body = "<proto> <host>" (connection metadata)
 *   GET  /empty             -> 200 with an empty body
 *   GET  /cookies           -> 200 with two Set-Cookie headers (multi-value demo)
 *   GET  /stream            -> 200 chunked, body streamed in parts (streaming demo)
 *   GET  /big/{n}           -> 200, body = {n} bytes of a deterministic pattern
 *   *    /redirect/{n}      -> 302 to /redirect/{n-1} until n=0, then 200 "done"
 *   GET  /msleep/{ms}       -> sleeps {ms} (async), then 200 "slept" (concurrency demo)
 *   GET  /native-msleep/{ms} -> blocks the thread {ms} natively (handler-timeout test)
 *   GET  /cpu/{n}           -> runs a CPU-bound sha256 loop of {n} rounds (bench)
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
 *   --maxRequests  --reusePort (0/1)
 */

// Build the server from argv: each --name=value maps to the matching HttpServer
// constructor parameter. Under WorkerMaster the injected --masterPid wires the
// orphan check (the worker self-terminates if its master dies); without it the
// check is off (standalone run).
$server = HttpServer::fromArgs($_SERVER['argv']);

$sleeper = new Sleeper();

$server->serve(static function (Request $request) use ($sleeper): Response|StreamedResponse {
    if ($request->path === '/method') {
        return new Response(body: $request->method);
    }

    if ($request->path === '/echo') {
        return new Response(body: $request->body->contents());
    }

    if ($request->path === '/upload') {
        // Stream the body in fixed 8 KiB pieces (never buffering it whole) and
        // return its sha256, so a test can verify every byte arrived in order.
        $hash = hash_init('sha256');

        while (($chunk = $request->body->read(8192)) !== null) {
            hash_update($hash, $chunk);
        }

        return new Response(body: hash_final($hash));
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
        $request->path === '/pid'     => new Response(body: (string) getmypid()),
        $request->path === '/empty'   => new Response(),
        $request->path === '/cookies' => new Response(
            body: 'cookies',
            headers: ['Set-Cookie' => ['a=1', 'b=2']],
        ),
        $request->path === '/stream'      => streamRoute($sleeper),
        $request->path === '/slow-stream' => slowStreamRoute($sleeper),
        $request->path === '/truncated'   => truncatedRoute(),
        str_starts_with($request->path, '/big/')      => bigRoute($request->path),
        str_starts_with($request->path, '/redirect/')  => redirectRoute($request->path),
        $request->path === '/throw'       => throw new RuntimeException('boom in handler'),
        str_starts_with($request->path, '/msleep/') => msleepRoute($sleeper, $request->path),
        str_starts_with($request->path, '/native-msleep/') => nativeMsleepRoute($request->path),
        str_starts_with($request->path, '/cpu/')    => cpuRoute($request->path),
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

// Native, BLOCKING sleep — unlike the async msleep above it does NOT yield to the
// scheduler, so it freezes the whole single-threaded server. Used to verify that the
// Go-side handlerTimeoutMs still answers the client with a 504 even when the PHP
// handler is blocked natively (the timer fires independently of PHP).
function nativeMsleepRoute(string $path): Response
{
    $milliseconds = (int) substr($path, strlen('/native-msleep/'));

    usleep($milliseconds * 1000);

    return new Response(body: 'native-slept');
}

function truncatedRoute(): Response
{
    // Declares a Content-Length far larger than the body actually sent, so net/http
    // closes the connection short and the client gets an unexpected EOF mid-body.
    // The server stays alive (no exit). Used by the download connection-drop test.
    $body = str_repeat('x', 16_384);

    return new Response(
        body: $body,
        headers: ['Content-Length' => [(string) (strlen($body) * 4)]],
    );
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

function slowStreamRoute(Sleeper $sleeper): StreamedResponse
{
    // Four chunks 100ms apart (~400ms total): a small handlerTimeoutMs cuts it
    // mid-stream. Used by the handler-timeout test.
    return new StreamedResponse(
        writer: static function (ResponseStream $out) use ($sleeper): void {
            foreach (['p0', 'p1', 'p2', 'p3'] as $part) {
                $out->write("$part\n");

                $sleeper->msleep(milliseconds: 100);
            }
        },
        headers: ['Content-Type' => 'text/plain'],
    );
}

// CPU-bound route: a sha256 loop that does NOT yield to the scheduler — used by
// the CPU benchmark to show SO_REUSEPORT spreading compute across processes/cores.
function cpuRoute(string $path): Response
{
    $iterations = (int) substr($path, strlen('/cpu/'));

    $value = '';

    for ($i = 0; $i < $iterations; $i++) {
        $value = hash('sha256', $value . $i);
    }

    return new Response(body: $value);
}

/**
 * Returns a body of exactly {n} bytes built from a fixed, repeating pattern, so a
 * client can verify a large (multi-chunk) response arrives complete and in order.
 * The same pattern is reproducible on the test side.
 */
function bigRoute(string $path): Response
{
    $size = (int) substr($path, strlen('/big/'));

    if ($size < 0) {
        $size = 0;
    }

    return new Response(body: bigBody($size));
}

function bigBody(int $size): string
{
    $pattern = '0123456789abcdef';

    return substr(str_repeat($pattern, intdiv($size, strlen($pattern)) + 1), 0, $size);
}

/**
 * Redirects to /redirect/{n-1} with a 302 until n reaches 0, then answers 200
 * "done". Lets a client test redirect following, a redirect cap and no-follow.
 * The Location is relative on purpose — clients must resolve it against the URL.
 */
function redirectRoute(string $path): Response
{
    $remaining = (int) substr($path, strlen('/redirect/'));

    if ($remaining <= 0) {
        return new Response(body: 'done');
    }

    return new Response(
        body: 'redirecting',
        status: 302,
        headers: ['Location' => ['/redirect/' . ($remaining - 1)]],
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
