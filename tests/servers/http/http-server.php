<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\Mongodb\Connection\Client as MongoClient;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Features\Mysql\Connection as MysqlConnection;
use SConcur\Features\Pgsql\Connection as PgsqlConnection;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\HttpServer\GeneratorStream;
use SConcur\WaitGroup;

/**
 * Demo / test HTTP server. The handler is PSR-7: it receives a ServerRequestInterface
 * and returns a ResponseInterface (built here with nyholm/psr7). Routes:
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
 *   GET  /all               -> fans out across the backend I/O features concurrently (load test)
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

// A single nyholm factory plays both PSR-17 roles the server needs (it builds the
// request handed to the handler and the fallback error responses).
$psr17Factory = new Psr17Factory();

// Build the server from argv: each --name=value maps to the matching HttpServer
// constructor parameter. Under WorkerMaster the injected --masterPid wires the
// orphan check (the worker self-terminates if its master dies); without it the
// check is off (standalone run).
$server = HttpServer::fromArgs(
    argv: $_SERVER['argv'],
    serverRequestFactory: $psr17Factory,
    responseFactory: $psr17Factory,
);

$server->serve(static function (ServerRequestInterface $request) use ($psr17Factory): ResponseInterface {
    $path   = $request->getUri()->getPath();
    $method = $request->getMethod();

    if ($path === '/method') {
        return text($psr17Factory, $method);
    }

    if ($path === '/echo') {
        return text($psr17Factory, $request->getBody()->getContents());
    }

    if ($path === '/upload') {
        // Stream the body in fixed 8 KiB pieces (never buffering it whole) and
        // return its sha256, so a test can verify every byte arrived in order.
        $hash = hash_init('sha256');

        $body = $request->getBody();

        while (($chunk = $body->read(8192)) !== '') {
            hash_update($hash, $chunk);
        }

        return text($psr17Factory, hash_final($hash));
    }

    if ($path === '/query') {
        return text($psr17Factory, $request->getUri()->getQuery());
    }

    if ($path === '/echo-header') {
        // Join with "," (not getHeaderLine()'s ", ") so a test can assert the exact bytes.
        return text($psr17Factory, implode(',', $request->getHeader('X-Echo')));
    }

    if ($path === '/meta') {
        return text($psr17Factory, 'HTTP/' . $request->getProtocolVersion() . ' ' . $request->getHeaderLine('Host'));
    }

    if ($method !== 'GET') {
        return text($psr17Factory, 'method not allowed', 405);
    }

    return match (true) {
        $path === '/'        => text($psr17Factory, 'ok'),
        $path === '/pid'     => text($psr17Factory, (string) getmypid()),
        $path === '/empty'   => text($psr17Factory),
        $path === '/cookies' => text(
            $psr17Factory,
            'cookies',
            200,
            ['Set-Cookie' => ['a=1', 'b=2']],
        ),
        $path === '/all'         => allFeaturesRoute($psr17Factory),
        $path === '/stream'      => streamRoute($psr17Factory),
        $path === '/slow-stream' => slowStreamRoute($psr17Factory),
        $path === '/truncated'   => truncatedRoute($psr17Factory),
        str_starts_with($path, '/big/')       => bigRoute($psr17Factory, $path),
        str_starts_with($path, '/redirect/')  => redirectRoute($psr17Factory, $path),
        $path === '/throw'       => throw new RuntimeException('boom in handler'),
        str_starts_with($path, '/msleep/') => msleepRoute($psr17Factory, $path),
        str_starts_with($path, '/native-msleep/') => nativeMsleepRoute($psr17Factory, $path),
        str_starts_with($path, '/cpu/')    => cpuRoute($psr17Factory, $path),
        str_starts_with($path, '/status/') => statusRoute($psr17Factory, $path),
        default => text($psr17Factory, 'not found', 404),
    };
});

/**
 * Builds a plain response: status, optional headers, optional body. A header value
 * may be a string or a list of strings (e.g. several Set-Cookie entries).
 *
 * @param array<string, string|array<int, string>> $headers
 */
function text(Psr17Factory $factory, string $body = '', int $status = 200, array $headers = []): ResponseInterface
{
    $response = $factory->createResponse($status);

    foreach ($headers as $name => $value) {
        $response = $response->withHeader($name, $value);
    }

    if ($body !== '') {
        $response = $response->withBody($factory->createStream($body));
    }

    return $response;
}

/**
 * Builds a streamed response: the body is a GeneratorStream of unknown size, so the
 * server drains it chunk by chunk (chunked transfer) instead of one atomic write.
 *
 * @param array<string, string|array<int, string>> $headers
 */
function streamResponse(Psr17Factory $factory, Generator $chunks, array $headers = []): ResponseInterface
{
    $response = $factory->createResponse(200);

    foreach ($headers as $name => $value) {
        $response = $response->withHeader($name, $value);
    }

    return $response->withBody(new GeneratorStream($chunks));
}

function msleepRoute(Psr17Factory $factory, string $path): ResponseInterface
{
    $milliseconds = (int) substr($path, strlen('/msleep/'));

    Sleeper::usleep(microseconds: $milliseconds * 1000);

    return text($factory, 'slept');
}

// Native, BLOCKING sleep — unlike the async usleep above it does NOT yield to the
// scheduler, so it freezes the whole single-threaded server. Used to verify that the
// Go-side handlerTimeoutMs still answers the client with a 504 even when the PHP
// handler is blocked natively (the timer fires independently of PHP).
function nativeMsleepRoute(Psr17Factory $factory, string $path): ResponseInterface
{
    $milliseconds = (int) substr($path, strlen('/native-msleep/'));

    usleep($milliseconds * 1000);

    return text($factory, 'native-slept');
}

/**
 * Load-test route: fans out across the backend I/O features concurrently in one
 * request (a nested WaitGroup) — Sleeper, MongoDB, MySQL, PostgreSQL. Used to watch
 * memory/CPU under load. The HTTP-client feature is intentionally NOT here: hitting
 * this server's own "/" would make every /all silently serve a second request and
 * skew the rps number — it is covered by its own benchmarks instead. Each feature is
 * isolated so a transient backend hiccup degrades just that feature (visible
 * per-feature error rate) instead of failing the whole request. Returns a JSON map.
 */
function allFeaturesRoute(Psr17Factory $factory): ResponseInterface
{
    [$mongo, $mysql, $pgsql] = allFeaturesContext();

    $status = [];

    $waitGroup = WaitGroup::create();

    $waitGroup->add(static function () use (&$status): void {
        $status['sleeper'] = allFeatureStatus(static function (): void {
            Sleeper::usleep(microseconds: 1000);
        });
    });

    $waitGroup->add(static function () use (&$status, $mongo): void {
        $status['mongodb'] = allFeatureStatus(static function () use ($mongo): void {
            $mongo->insertOne(['t' => 'load']);
            $mongo->findOne(filter: ['t' => 'load']);
        });
    });

    $waitGroup->add(static function () use (&$status, $mysql): void {
        $status['mysql'] = allFeatureStatus(static function () use ($mysql): void {
            $mysql->fetchAll('SELECT 1');
        });
    });

    $waitGroup->add(static function () use (&$status, $pgsql): void {
        $status['pgsql'] = allFeatureStatus(static function () use ($pgsql): void {
            $pgsql->fetchAll('SELECT 1');
        });
    });

    $waitGroup->waitResults();

    return text(
        $factory,
        (string) json_encode($status),
        200,
        ['Content-Type' => 'application/json'],
    );
}

/**
 * Lazily builds and caches the per-worker DB connections used by /all on its first
 * hit (so the other demo routes never pay for them and never require the backends).
 * The Go side pools the real connections by URI/DSN, so reusing these objects across
 * requests is cheap.
 *
 * @return array{0: Collection, 1: MysqlConnection, 2: PgsqlConnection}
 */
function allFeaturesContext(): array
{
    /** @var array{0: Collection, 1: MysqlConnection, 2: PgsqlConnection}|null $context */
    static $context = null;

    if ($context !== null) {
        return $context;
    }

    Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

    $context = [
        new MongoClient(
            uri: sprintf(
                'mongodb://%s:%s@%s:%s',
                $_ENV['MONGO_ADMIN_USERNAME'],
                $_ENV['MONGO_ADMIN_PASSWORD'],
                $_ENV['MONGO_HOST'],
                $_ENV['MONGO_PORT'],
            ),
        )
            ->selectDatabase('u-test')
            ->selectCollection('load_all'),
        new MysqlConnection(
            dsn: sprintf(
                '%s:%s@tcp(%s:%s)/%s?parseTime=true',
                $_ENV['MYSQL_USER'],
                $_ENV['MYSQL_PASSWORD'],
                $_ENV['MYSQL_HOST'],
                $_ENV['MYSQL_PORT'],
                $_ENV['MYSQL_DATABASE'],
            ),
        ),
        new PgsqlConnection(
            dsn: sprintf(
                'postgres://%s:%s@%s:%s/%s?sslmode=disable',
                $_ENV['POSTGRES_USER'],
                $_ENV['POSTGRES_PASSWORD'],
                $_ENV['POSTGRES_HOST'],
                $_ENV['POSTGRES_PORT'],
                $_ENV['POSTGRES_DB'],
            ),
        ),
    ];

    return $context;
}

/**
 * Runs one feature call and returns 'ok' or 'err: <message>', so a transient backend
 * failure degrades that one feature instead of 500-ing the whole /all request — the
 * load test keeps running and the per-feature error rate stays visible.
 */
function allFeatureStatus(callable $call): string
{
    try {
        $call();

        return 'ok';
    } catch (Throwable $exception) {
        return 'err: ' . $exception->getMessage();
    }
}

function truncatedRoute(Psr17Factory $factory): ResponseInterface
{
    // Declares a Content-Length far larger than the body actually sent, so net/http
    // closes the connection short and the client gets an unexpected EOF mid-body.
    // The server stays alive (no exit). Used by the download connection-drop test.
    $body = str_repeat('x', 16_384);

    return text(
        $factory,
        $body,
        200,
        ['Content-Length' => [(string) (strlen($body) * 4)]],
    );
}

function streamRoute(Psr17Factory $factory): ResponseInterface
{
    $chunks = (static function (): Generator {
        foreach (['a', 'b', 'c'] as $part) {
            yield "chunk-$part\n";

            // Async work between chunks: other requests keep being served.
            Sleeper::usleep(microseconds: 50_000);
        }
    })();

    return streamResponse(
        $factory,
        $chunks,
        ['Content-Type' => 'text/plain'],
    );
}

function slowStreamRoute(Psr17Factory $factory): ResponseInterface
{
    // Four chunks 100ms apart (~400ms total): a small handlerTimeoutMs cuts it
    // mid-stream. Used by the handler-timeout test.
    $chunks = (static function (): Generator {
        foreach (['p0', 'p1', 'p2', 'p3'] as $part) {
            yield "$part\n";

            Sleeper::usleep(microseconds: 100_000);
        }
    })();

    return streamResponse(
        $factory,
        $chunks,
        ['Content-Type' => 'text/plain'],
    );
}

// CPU-bound route: a sha256 loop that does NOT yield to the scheduler — used by
// the CPU benchmark to show SO_REUSEPORT spreading compute across processes/cores.
function cpuRoute(Psr17Factory $factory, string $path): ResponseInterface
{
    $iterations = (int) substr($path, strlen('/cpu/'));

    $value = '';

    for ($i = 0; $i < $iterations; $i++) {
        $value = hash('sha256', $value . $i);
    }

    return text($factory, $value);
}

/**
 * Returns a body of exactly {n} bytes built from a fixed, repeating pattern, so a
 * client can verify a large (multi-chunk) response arrives complete and in order.
 * The same pattern is reproducible on the test side.
 */
function bigRoute(Psr17Factory $factory, string $path): ResponseInterface
{
    $size = (int) substr($path, strlen('/big/'));

    if ($size < 0) {
        $size = 0;
    }

    return text($factory, bigBody($size));
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
function redirectRoute(Psr17Factory $factory, string $path): ResponseInterface
{
    $remaining = (int) substr($path, strlen('/redirect/'));

    if ($remaining <= 0) {
        return text($factory, 'done');
    }

    return text(
        $factory,
        'redirecting',
        302,
        ['Location' => ['/redirect/' . ($remaining - 1)]],
    );
}

function statusRoute(Psr17Factory $factory, string $path): ResponseInterface
{
    $code = (int) substr($path, strlen('/status/'));

    if ($code < 100 || $code > 599) {
        return text($factory, 'bad status', 400);
    }

    return text($factory, 'status ' . $code, $code);
}
