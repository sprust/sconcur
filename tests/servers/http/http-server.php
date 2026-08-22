<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use MongoDB\Client as NativeMongoClient;
use MongoDB\Collection as NativeMongoCollection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Connection\Extension;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\HttpServer\Payloads\RespondPayload;
use SConcur\Features\HttpServer\Payloads\ServePayload;
use SConcur\Features\Mongodb\Connection\Client as MongoClient;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Features\Mysql\Connection as MysqlConnection;
use SConcur\Features\Pgsql\Connection as PgsqlConnection;
use SConcur\Features\Amqp\Connection as AmqpConnection;
use SConcur\Features\Amqp\ConnectionOptions as AmqpConnectionOptions;
use SConcur\Features\Amqp\Queue as AmqpQueue;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Transport\MessagePackTransport;
use SConcur\Tests\Impl\HttpServer\GeneratorStream;
use SConcur\WaitGroup;

// The name of the queue this server publishes into and the demo consumer pool reads
// (config/sconcur.rabbitmq.config.json). Declared by the publisher, because a consumer
// declares nothing — topology belongs to whoever owns it.
const RABBITMQ_DEMO_QUEUE = 'sconcur_demo_queue';

// The most jobs one request may queue. A path segment is user input, and a typo with an
// extra zero should be refused rather than spend a minute publishing.
const RABBITMQ_MAX_JOBS = 100000;

/**
 * Demo / test HTTP server. The handler is PSR-7: it receives a ServerRequestInterface
 * and returns a ResponseInterface (built here with nyholm/psr7). Routes:
 *   GET  /                  -> 200 "ok"
 *   GET  /pid               -> 200, body = this process pid (used by the worker-master tests)
 *   *    /method            -> 200, body = request method (GET/POST/...)
 *   *    /echo              -> 200, body = the request body (echo, full read)
 *   *    /upload            -> 200, body = sha256 of the request body (streamed read)
 *   POST /files/upload?name= -> 201, streams the body to disk, JSON {saved,bytes,sha256}
 *   GET  /files/download?name= -> streams a previously uploaded file back (attachment), 404 if missing
 *   GET  /image?name=        -> serves an image from tests/storage/images inline (default sample.png)
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
 *   GET  /cpu-switch/{n}    -> the same loop, but yielding via Scheduler::switch() (fairness demo)
 *   GET  /db?n={q}          -> {q} sequential point SELECTs on MySQL (default 1), JSON row —
 *                              the point-query ladder bench vs RoadRunner (docs/benchmarks.md)
 *   GET  /db-rw             -> INSERT one row + COUNT(*) + point SELECT of a random id within
 *                              that count, JSON {count, record} — the read-write ladder bench
 *   GET  /all               -> fans out across the backend I/O features concurrently (load test)
 *   GET  /all-nowg          -> the same SConcur features, sequentially, NO WaitGroup — measures
 *                              cross-request concurrency alone (each call still yields)
 *   GET  /all-native        -> the same operations on NATIVE drivers, sequentially (exactly the
 *                              RoadRunner reference worker's /all) — isolates the server layer
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
 *
 * Bench-only: --ladder=l1|l2|l2f runs the attribution-ladder loop instead of the
 * normal server (see runLadderServer below).
 */

// Attribution-ladder bench modes (.ai/plans/cpu-per-request-attribution.md,
// phase 4): --ladder=l1 answers every request 200 "ok" inline from the loop (no
// Fiber), --ladder=l2 answers the same from a fresh Fiber per request. Both talk
// to the extension directly — no Scheduler, no PSR-7, no HttpServer::serve().
// The option is stripped from argv before fromArgs(), which rejects unknown args.
$ladderMode = '';

$_SERVER['argv'] = array_values(array_filter(
    $_SERVER['argv'],
    static function (string $argument) use (&$ladderMode): bool {
        if (str_starts_with($argument, '--ladder=')) {
            $ladderMode = substr($argument, strlen('--ladder='));

            return false;
        }

        return true;
    },
));

if (!in_array($ladderMode, ['', 'l1', 'l2', 'l2f', 'l2h'], true)) {
    fwrite(STDERR, sprintf('unknown --ladder mode: %s (supported: l1, l2, l2f, l2h)%s', $ladderMode, PHP_EOL));

    exit(1);
}

if ($ladderMode !== '') {
    runLadderServer(mode: $ladderMode, argv: $_SERVER['argv']);

    exit(0);
}

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
    // A handler failure otherwise surfaces as a bare 500 with no trace; the
    // demo/test server logs it so load-test error rates are diagnosable.
    onError: static function (Throwable $exception, ServerRequestInterface $request): ?ResponseInterface {
        fwrite(STDERR, sprintf(
            'handler error: %s %s: %s: %s%s',
            $request->getMethod(),
            $request->getUri()->getPath(),
            $exception::class,
            $exception->getMessage(),
            PHP_EOL,
        ));

        return null;
    },
);

// Where uploads land (ephemeral, shared across reuse-port workers via the temp dir;
// not committed) and where the committed sample images live.
$uploadDir = sys_get_temp_dir() . '/sconcur-uploads';
$imageDir  = dirname(__DIR__, 2) . '/storage/images';

@mkdir($uploadDir, 0777, true);

$server->serve(static function (ServerRequestInterface $request) use ($psr17Factory, $uploadDir, $imageDir): ResponseInterface {
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

    if ($path === '/files/upload' && $method === 'POST') {
        return filesUploadRoute($psr17Factory, $request, $uploadDir);
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
        $path === '/db'               => dbPointSelectRoute($psr17Factory, $request),
        $path === '/db-rw'            => dbReadWriteRoute($psr17Factory),
        $path === '/all'              => allFeaturesRoute($psr17Factory),
        $path === '/all-nowg'         => allFeaturesNoWaitGroupRoute($psr17Factory),
        $path === '/all-native'       => allFeaturesNativeRoute($psr17Factory),
        $path === '/files/download'   => filesDownloadRoute($psr17Factory, $uploadDir, $request),
        $path === '/image'            => imageRoute($psr17Factory, $imageDir, $request),
        $path === '/stream'      => streamRoute($psr17Factory),
        $path === '/slow-stream' => slowStreamRoute($psr17Factory),
        $path === '/truncated'   => truncatedRoute($psr17Factory),
        str_starts_with($path, '/big/')       => bigRoute($psr17Factory, $path),
        str_starts_with($path, '/redirect/')  => redirectRoute($psr17Factory, $path),
        $path === '/throw'       => throw new RuntimeException('boom in handler'),
        str_starts_with($path, '/rabbitmq/') => rabbitmqRoute($psr17Factory, $path),
        str_starts_with($path, '/msleep/') => msleepRoute($psr17Factory, $path),
        str_starts_with($path, '/native-msleep/') => nativeMsleepRoute($psr17Factory, $path),
        str_starts_with($path, '/cpu-switch/') => cpuSwitchRoute($psr17Factory, $path),
        str_starts_with($path, '/cpu/')    => cpuRoute($psr17Factory, $path),
        str_starts_with($path, '/status/') => statusRoute($psr17Factory, $path),
        default => text($psr17Factory, 'not found', 404),
    };
});

/**
 * Per-process lazy singleton for the bench contexts. A plain `static $x ??= build()`
 * is not enough here: requests run as concurrent coroutines and preemption can
 * interrupt the builder mid-way, so dozens of them would enter it at once and each
 * open its own connections (a burst of ~90 PostgreSQL connects at the start of a
 * load run hit `max_connections` and turned ~0.2% of /all-native responses into
 * 500s). The first coroutine marks the key as building, the rest park on
 * `Scheduler::switch()` until the value is there.
 */
function serverOnce(string $key, Closure $factory): mixed
{
    /** @var array<string, mixed> $values */
    static $values = [];
    /** @var array<string, true> $building */
    static $building = [];

    while (isset($building[$key])) {
        Scheduler::get()->switch();
    }

    if (array_key_exists($key, $values)) {
        return $values[$key];
    }

    $building[$key] = true;

    try {
        $values[$key] = $factory();
    } finally {
        unset($building[$key]);
    }

    return $values[$key];
}

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

/**
 * Streams the request body straight to a file on disk in fixed pieces (never
 * buffering it whole), then returns JSON with the saved size and sha256 — a file
 * upload into a long-lived server.
 */
function filesUploadRoute(Psr17Factory $factory, ServerRequestInterface $request, string $uploadDir): ResponseInterface
{
    $name   = basename((string) ($request->getQueryParams()['name'] ?? ''));
    $target = $uploadDir . '/' . ($name !== '' ? $name : 'upload.bin');

    $handle = fopen($target, 'wb');

    if ($handle === false) {
        return text($factory, 'cannot open upload target', 500);
    }

    $body = $request->getBody();

    $bytes = 0;
    $hash  = hash_init('sha256');

    while (($chunk = $body->read(8192)) !== '') {
        fwrite($handle, $chunk);
        hash_update($hash, $chunk);

        $bytes += strlen($chunk);
    }

    fclose($handle);

    return text(
        $factory,
        (string) json_encode([
            'saved'  => basename($target),
            'bytes'  => $bytes,
            'sha256' => hash_final($hash),
        ]),
        201,
        ['Content-Type' => 'application/json'],
    );
}

/**
 * Streams a previously uploaded file from disk back to the client as an attachment.
 * The body is built from the file via the PSR-17 stream factory (size known → one
 * write); 404 if the file does not exist.
 */
function filesDownloadRoute(Psr17Factory $factory, string $uploadDir, ServerRequestInterface $request): ResponseInterface
{
    $name   = basename((string) ($request->getQueryParams()['name'] ?? ''));
    $source = $uploadDir . '/' . $name;

    if ($name === '' || !is_file($source)) {
        return text($factory, 'not found', 404);
    }

    return fileResponse(
        $factory,
        $source,
        'application/octet-stream',
        'attachment; filename="' . $name . '"',
    );
}

/**
 * Serves an image from tests/storage/images inline (Content-Type guessed from the
 * extension), so a browser displays it directly. Defaults to sample.png.
 */
function imageRoute(Psr17Factory $factory, string $imageDir, ServerRequestInterface $request): ResponseInterface
{
    $name = basename((string) ($request->getQueryParams()['name'] ?? '')) ?: 'sample.png';

    $source = $imageDir . '/' . $name;

    if (!is_file($source)) {
        return text($factory, 'image not found', 404);
    }

    return fileResponse(
        $factory,
        $source,
        imageMimeType($source),
        'inline',
    );
}

/**
 * Builds a response that serves a file from disk: the body is read from the file via
 * the PSR-17 stream factory, and Content-Length is set from the known size so the
 * client gets the length up front (no needless chunked encoding for large files).
 */
function fileResponse(
    Psr17Factory $factory,
    string $source,
    string $contentType,
    string $contentDisposition,
): ResponseInterface {
    $stream = $factory->createStreamFromFile($source, 'rb');
    $size   = $stream->getSize();

    $response = $factory->createResponse(200)
        ->withHeader('Content-Type', $contentType)
        ->withHeader('Content-Disposition', $contentDisposition)
        ->withBody($stream);

    if ($size !== null) {
        $response = $response->withHeader('Content-Length', (string) $size);
    }

    return $response;
}

/**
 * Maps a file extension to an image MIME type (a tiny allow-list, default
 * application/octet-stream) — keeps the demo free of a fileinfo dependency.
 */
function imageMimeType(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'png'         => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'svg'         => 'image/svg+xml',
        default       => 'application/octet-stream',
    };
}

function msleepRoute(Psr17Factory $factory, string $path): ResponseInterface
{
    $milliseconds = (int) substr($path, strlen('/msleep/'));

    Sleeper::usleep(microseconds: $milliseconds * 1000);

    return text($factory, 'slept');
}

// GET /rabbitmq/{count}/sleep/{ms} — queues {count} jobs whose handler sleeps {ms}, to
// give the consumer pool something to chew on. The body the consumer understands is
// "sleep:<ms>" (tests/consumers/amqp/amqp-consumer.php).
//
// Publishing is sequential on one channel on purpose: basic.publish expects no reply, so
// there is nothing to overlap, and a channel serializes its commands anyway.
function rabbitmqRoute(Psr17Factory $factory, string $path): ResponseInterface
{
    // /rabbitmq/{count}/sleep/{ms}
    $segments = explode('/', trim($path, '/'));

    if (count($segments) !== 4 || $segments[2] !== 'sleep') {
        return text($factory, 'usage: /rabbitmq/{count}/sleep/{ms}', 404);
    }

    $count        = (int) $segments[1];
    $milliseconds = (int) $segments[3];

    if ($count < 1 || $count > RABBITMQ_MAX_JOBS) {
        return text($factory, 'count must be between 1 and ' . RABBITMQ_MAX_JOBS, 400);
    }

    if ($milliseconds < 0) {
        return text($factory, 'ms must not be negative', 400);
    }

    $queue = rabbitmqPublisher();

    for ($index = 0; $index < $count; ++$index) {
        $queue->publish('sleep:' . $milliseconds);
    }

    return text($factory, sprintf('queued %d job(s) sleeping %dms', $count, $milliseconds));
}

// The publisher of this worker: one pooled connection, one channel, the queue declared
// once. Built through serverOnce because requests run as concurrent coroutines — see the
// note there on why a plain static is not enough.
function rabbitmqPublisher(): AmqpQueue
{
    /** @var AmqpQueue */
    return serverOnce('rabbitmq-publisher', static function (): AmqpQueue {
        Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

        $connection = new AmqpConnection(new AmqpConnectionOptions(
            host: (string) $_ENV['RABBITMQ_HOST'],
            port: (int) $_ENV['RABBITMQ_PORT'],
            login: (string) $_ENV['RABBITMQ_USER'],
            password: (string) $_ENV['RABBITMQ_PASSWORD'],
            vhost: (string) $_ENV['RABBITMQ_VHOST'],
        ));

        $queue = $connection->channel()->queue(RABBITMQ_DEMO_QUEUE);

        $queue->declare(durable: true);

        // Publishing straight into the queue: the default exchange routes by queue name,
        // and Queue::publish() is what spares the caller from knowing that.
        return $queue;
    });
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
 * request (a nested WaitGroup) — MongoDB, MySQL, PostgreSQL. Used to watch
 * memory/CPU under load. The HTTP-client feature is intentionally NOT here: hitting
 * this server's own "/" would make every /all silently serve a second request and
 * skew the rps number — it is covered by its own benchmarks instead. Each feature is
 * isolated so a transient backend hiccup stays visible per feature in the JSON map,
 * but any failed feature turns the response into a 500 — load tools (wrk) then count
 * the request as an error instead of silently passing it as a 200.
 */
// Point-query bench route (the worker-count ladder vs RoadRunner in
// docs/benchmarks.md): ?n= sequential point SELECTs per request (default 1)
// through the SConcur MySQL feature, no WaitGroup — cross-request overlap only.
// The pool is sized for the connection budget divided across a 16-worker
// reuse-port pool under MySQL's default max_connections=151 (16 × 9 = 144).
function dbPointSelectRoute(Psr17Factory $factory, ServerRequestInterface $request): ResponseInterface
{
    $queryCount = max(1, (int) ($request->getQueryParams()['n'] ?? 1));

    $mysql = dbPointSelectContext();
    $rows  = [];

    for ($queryIndex = 0; $queryIndex < $queryCount; $queryIndex++) {
        $rows = $mysql->fetchAll(
            sql: 'SELECT id, t FROM bench_seed WHERE id = ?',
            bindings: [random_int(1, 1000)],
        );
    }

    $responseBody = json_encode($rows);

    if ($responseBody === false) {
        return text($factory, 'json encode failed', 500);
    }

    return text($factory, $responseBody);
}

// Lazily builds the /db connection on the first hit and makes sure the seeded
// table exists (1 000 fixed-shape rows), so the handle works out of the box.
function dbPointSelectContext(): MysqlConnection
{
    /** @var MysqlConnection */
    return serverOnce('db-point-select', static function (): MysqlConnection {
        $mysql = new MysqlConnection(
            dsn: dbMysqlDsn(),
            maxOpenConns: dbPoolSize(),
        );

        $mysql->exec(sql: 'CREATE TABLE IF NOT EXISTS bench_seed (id BIGINT PRIMARY KEY, t VARCHAR(64) NOT NULL)');

        $seededRows = $mysql->fetchAll('SELECT COUNT(*) AS c FROM bench_seed');

        if ((int) ($seededRows[0]['c'] ?? 0) < 1000) {
            for ($id = 1; $id <= 1000; $id++) {
                $mysql->exec(
                    sql: 'INSERT IGNORE INTO bench_seed (id, t) VALUES (?, ?)',
                    bindings: [
                        $id,
                        'row-' . $id . '-' . str_repeat('x', 20),
                    ],
                );
            }
        }

        return $mysql;
    });
}

// The per-process pool of the /db* bench routes. The DB connection budget is
// divided across the reuse-port pool, so the useful size depends on how many
// server processes the run starts: the default 9 fits a 16-worker pool under
// MySQL's default max_connections=151 (16 x 9 = 144), and the ladder runs pass
// their own value (tests/benchmarks/http/load-stats.sh, DB_POOL_SIZE).
function dbPoolSize(): int
{
    return max(1, (int) (getenv('SCONCUR_DB_POOL_SIZE') ?: 9));
}

// The MySQL DSN shared by the /db* bench routes (Go driver format).
function dbMysqlDsn(): string
{
    Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

    return sprintf(
        '%s:%s@tcp(%s:%s)/%s?parseTime=true',
        $_ENV['MYSQL_USER'],
        $_ENV['MYSQL_PASSWORD'],
        $_ENV['MYSQL_HOST'],
        $_ENV['MYSQL_PORT'],
        $_ENV['MYSQL_DATABASE'],
    );
}

// Read-write bench route (the worker-count ladder vs RoadRunner in
// docs/benchmarks.md): one INSERT, then COUNT(*), then a point SELECT of a
// random id within that count, JSON {count, record} — a minimal write+read mix
// against a 10 000-row seeded table with five typed columns.
function dbReadWriteRoute(Psr17Factory $factory): ResponseInterface
{
    $mysql = dbReadWriteContext();

    $mysql->exec(
        sql: 'INSERT INTO bench_rw (title, quantity, price, active, created_date) VALUES (?, ?, ?, ?, ?)',
        bindings: [
            uniqid('row-'),
            random_int(1, 1_000_000),
            random_int(1, 1_000_000) / 100,
            random_int(0, 1),
            date('Y-m-d'),
        ],
    );

    $countRows = $mysql->fetchAll('SELECT COUNT(*) AS c FROM bench_rw');
    $rowCount  = (int) ($countRows[0]['c'] ?? 0);

    $recordRows = $mysql->fetchAll(
        sql: 'SELECT id, title, quantity, price, active, created_date FROM bench_rw WHERE id = ?',
        bindings: [random_int(1, max(1, $rowCount))],
    );

    $responseBody = json_encode([
        'count'  => $rowCount,
        'record' => $recordRows[0] ?? null,
    ]);

    if ($responseBody === false) {
        return text($factory, 'json encode failed', 500);
    }

    return text($factory, $responseBody);
}

// Lazily builds the /db-rw connection on the first hit and seeds the table:
// 10 000 fixed-shape rows across five typed columns (string, int, float, bool,
// date), inserted in multi-row batches so a cold disk-backed MySQL seeds in
// seconds, not minutes. Ids are explicit (1..10 000) and INSERT IGNORE keeps
// concurrent seeding by reuse-port workers idempotent; rows inserted by the
// route continue the sequence (AUTO_INCREMENT), so ids stay contiguous and a
// random id in [1, COUNT(*)] always hits a row.
function dbReadWriteContext(): MysqlConnection
{
    /** @var MysqlConnection */
    return serverOnce('db-read-write', static function (): MysqlConnection {
        $mysql = new MysqlConnection(
            dsn: dbMysqlDsn(),
            maxOpenConns: dbPoolSize(),
        );

        $mysql->exec(sql: <<<'SQL'
            CREATE TABLE IF NOT EXISTS bench_rw (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(64) NOT NULL,
                quantity INT NOT NULL,
                price DOUBLE NOT NULL,
                active TINYINT(1) NOT NULL,
                created_date DATE NOT NULL
            )
            SQL);

        $seededRows = $mysql->fetchAll('SELECT COUNT(*) AS c FROM bench_rw');

        if ((int) ($seededRows[0]['c'] ?? 0) < 10_000) {
            $baseDate = new DateTimeImmutable('2026-01-01');

            for ($batchStart = 1; $batchStart <= 10_000; $batchStart += 1_000) {
                $placeholders = [];
                $bindings     = [];

                for ($id = $batchStart; $id < $batchStart + 1_000; $id++) {
                    $placeholders[] = '(?, ?, ?, ?, ?, ?)';

                    $bindings[] = $id;
                    $bindings[] = 'row-' . $id;
                    $bindings[] = $id;
                    $bindings[] = $id / 100;
                    $bindings[] = $id % 2;
                    $bindings[] = $baseDate->modify('+' . ($id % 365) . ' days')->format('Y-m-d');
                }

                $mysql->exec(
                    sql: 'INSERT IGNORE INTO bench_rw (id, title, quantity, price, active, created_date) VALUES '
                        . implode(', ', $placeholders),
                    bindings: $bindings,
                );
            }
        }

        return $mysql;
    });
}

function allFeaturesRoute(Psr17Factory $factory): ResponseInterface
{
    [$mongo, $mysql, $pgsql] = allFeaturesContext();

    $status = [];

    $waitGroup = WaitGroup::create();

    $waitGroup->add(static function () use (&$status, $mongo): void {
        $status['mongodb'] = allFeatureStatus(static function () use ($mongo): void {
            $mongo->insertOne(['t' => 'load']);
            $mongo->findOne(filter: ['t' => 'load']);
        });
    });

    $waitGroup->add(static function () use (&$status, $mysql): void {
        $status['mysql'] = allFeatureStatus(static function () use ($mysql): void {
            $mysql->exec(
                sql: 'INSERT INTO load_all (t) VALUES (?)',
                bindings: ['load'],
            );

            $mysql->fetchAll('SELECT 1');
        });
    });

    $waitGroup->add(static function () use (&$status, $pgsql): void {
        $status['pgsql'] = allFeatureStatus(static function () use ($pgsql): void {
            $pgsql->exec(
                sql: 'INSERT INTO load_all (t) VALUES ($1)',
                bindings: ['load'],
            );

            $pgsql->fetchAll('SELECT 1');
        });
    });

    $waitGroup->waitResults();

    $statusCode = 200;

    foreach ($status as $featureStatus) {
        if ($featureStatus !== 'ok') {
            $statusCode = 500;

            break;
        }
    }

    return text(
        $factory,
        (string) json_encode($status),
        $statusCode,
        ['Content-Type' => 'application/json'],
    );
}

/**
 * The /all operations on the same SConcur features, but sequentially and with NO
 * WaitGroup: every call suspends this request's coroutine one by one, so the
 * per-request latency is the sum of the operations while the server keeps handling
 * other requests between the suspends. Comparing /all-nowg with /all isolates the
 * intra-request fan-out win from the cross-request (spawn-per-request) concurrency.
 * Same JSON status map, same 500 on any failed feature.
 */
function allFeaturesNoWaitGroupRoute(Psr17Factory $factory): ResponseInterface
{
    [$mongo, $mysql, $pgsql] = allFeaturesContext();

    $status = [];

    $status['mongodb'] = allFeatureStatus(static function () use ($mongo): void {
        $mongo->insertOne(['t' => 'load']);
        $mongo->findOne(filter: ['t' => 'load']);
    });

    $status['mysql'] = allFeatureStatus(static function () use ($mysql): void {
        $mysql->exec(
            sql: 'INSERT INTO load_all (t) VALUES (?)',
            bindings: ['load'],
        );

        $mysql->fetchAll('SELECT 1');
    });

    $status['pgsql'] = allFeatureStatus(static function () use ($pgsql): void {
        $pgsql->exec(
            sql: 'INSERT INTO load_all (t) VALUES ($1)',
            bindings: ['load'],
        );

        $pgsql->fetchAll('SELECT 1');
    });

    $statusCode = 200;

    foreach ($status as $featureStatus) {
        if ($featureStatus !== 'ok') {
            $statusCode = 500;

            break;
        }
    }

    return text(
        $factory,
        (string) json_encode($status),
        $statusCode,
        ['Content-Type' => 'application/json'],
    );
}

/**
 * Lazily builds and caches the per-worker DB connections used by /all on its first
 * hit (so the other demo routes never pay for them and never require the backends).
 * The Go side pools the real connections by URI/DSN, so reusing these objects across
 * requests is cheap. Also makes sure the load_all tables exist (the /all SQL write
 * targets; mirrored by the RoadRunner reference worker in tests/servers/roadrunner).
 *
 * @return array{0: Collection, 1: MysqlConnection, 2: PgsqlConnection}
 */
function allFeaturesContext(): array
{
    /** @var array{0: Collection, 1: MysqlConnection, 2: PgsqlConnection} */
    return serverOnce('all-features', static function (): array {
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
            // The Go-side pool is per worker process; the load harness runs up to
            // ~nproc workers, so an unbounded pool exhausts the DB server limits
            // (PostgreSQL max_connections=100 -> "too many clients" -> 500s under
            // load). 5 conns x 16 processes stays under the limit for both DBs.
            new MysqlConnection(
                dsn: sprintf(
                    '%s:%s@tcp(%s:%s)/%s?parseTime=true',
                    $_ENV['MYSQL_USER'],
                    $_ENV['MYSQL_PASSWORD'],
                    $_ENV['MYSQL_HOST'],
                    $_ENV['MYSQL_PORT'],
                    $_ENV['MYSQL_DATABASE'],
                ),
                maxOpenConns: 5,
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
                maxOpenConns: 5,
            ),
        ];

        $context[1]->exec(sql: 'CREATE TABLE IF NOT EXISTS load_all (id BIGINT AUTO_INCREMENT PRIMARY KEY, t VARCHAR(16) NOT NULL)');
        $context[2]->exec(sql: 'CREATE TABLE IF NOT EXISTS load_all (id BIGSERIAL PRIMARY KEY, t VARCHAR(16) NOT NULL)');

        return $context;
    });
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

/**
 * Native counterpart of /all: exactly the RoadRunner reference worker's route
 * (tests/servers/roadrunner/rr-worker.php) — the same operations on NATIVE drivers
 * (mongodb/mongodb + PDO), sequentially, no SConcur features. Blocks the worker for
 * the duration of the request, like a RoadRunner worker would. Comparing /all-native
 * here with /all here and with RoadRunner's /all isolates the server/transport layer
 * from the driver stack. Same JSON status map, same 500 on any failed feature.
 */
function allFeaturesNativeRoute(Psr17Factory $factory): ResponseInterface
{
    [$mongo, $mysql, $pgsql] = allFeaturesNativeContext();

    $status = [];

    $status['mongodb'] = allFeatureStatus(static function () use ($mongo): void {
        $mongo->insertOne(['t' => 'load']);
        $mongo->findOne(['t' => 'load']);
    });

    $status['mysql'] = allFeatureStatus(static function () use ($mysql): void {
        $statement = $mysql->prepare('INSERT INTO load_all (t) VALUES (?)');

        $statement->execute(['load']);

        $mysql->query('SELECT 1')->fetchAll();
    });

    $status['pgsql'] = allFeatureStatus(static function () use ($pgsql): void {
        $statement = $pgsql->prepare('INSERT INTO load_all (t) VALUES (?)');

        $statement->execute(['load']);

        $pgsql->query('SELECT 1')->fetchAll();
    });

    $statusCode = 200;

    foreach ($status as $featureStatus) {
        if ($featureStatus !== 'ok') {
            $statusCode = 500;

            break;
        }
    }

    return text(
        $factory,
        (string) json_encode($status),
        $statusCode,
        ['Content-Type' => 'application/json'],
    );
}

/**
 * Lazily builds and caches the per-worker NATIVE connections used by /all-native on
 * its first hit (mirror of rrAllFeaturesContext() in the RoadRunner worker): the same
 * .env, backends and load_all targets as /all, but mongodb/mongodb + PDO. One
 * connection per worker process, like a RoadRunner worker holds.
 *
 * @return array{0: NativeMongoCollection, 1: PDO, 2: PDO}
 */
function allFeaturesNativeContext(): array
{
    /** @var array{0: NativeMongoCollection, 1: PDO, 2: PDO} */
    return serverOnce('all-features-native', static function (): array {
        Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

        $mongo = new NativeMongoClient(
            sprintf(
                'mongodb://%s:%s@%s:%s',
                $_ENV['MONGO_ADMIN_USERNAME'],
                $_ENV['MONGO_ADMIN_PASSWORD'],
                $_ENV['MONGO_HOST'],
                $_ENV['MONGO_PORT'],
            ),
        )
            ->selectCollection('u-test', 'load_all');

        $mysql = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $_ENV['MYSQL_HOST'],
                $_ENV['MYSQL_PORT'],
                $_ENV['MYSQL_DATABASE'],
            ),
            $_ENV['MYSQL_USER'],
            $_ENV['MYSQL_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pgsql = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $_ENV['POSTGRES_HOST'],
                $_ENV['POSTGRES_PORT'],
                $_ENV['POSTGRES_DB'],
            ),
            $_ENV['POSTGRES_USER'],
            $_ENV['POSTGRES_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $mysql->exec('CREATE TABLE IF NOT EXISTS load_all (id BIGINT AUTO_INCREMENT PRIMARY KEY, t VARCHAR(16) NOT NULL)');
        $pgsql->exec('CREATE TABLE IF NOT EXISTS load_all (id BIGSERIAL PRIMARY KEY, t VARCHAR(16) NOT NULL)');

        return [$mongo, $mysql, $pgsql];
    });
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

// CPU-bound route that cooperates: the same sha256 loop as /cpu/{n}, but calling
// Scheduler::switch() each iteration (1 ms quantum), so concurrent light requests
// keep progressing while this one crunches — the fairness counterpart of /cpu.
function cpuSwitchRoute(Psr17Factory $factory, string $path): ResponseInterface
{
    $iterations = (int) substr($path, strlen('/cpu-switch/'));

    $scheduler = Scheduler::get();

    $value = '';

    for ($i = 0; $i < $iterations; $i++) {
        $scheduler->switch(quantumMs: 1);

        $value = hash('sha256', $value . $i);
    }

    return text($factory, $value);
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

/**
 * Attribution-ladder serve loop (.ai/plans/cpu-per-request-attribution.md,
 * phase 4). Speaks to the extension directly: push the listener flow, pull
 * results with waitAnyBatch(), re-arm with next() and answer every request with
 * a constant 200 "ok" — inline in l1; in l2 from a fresh Fiber per request that
 * suspends with the pending respond so the push stays off the fiber stack
 * (production-shaped); in l2f with the push on the fiber stack (the known
 * pathological boundary crossing, kept as a reproducible reference). The delta
 * to the L3 full server isolates the Scheduler + PSR-7 overhead, l2 − l1 the
 * Fiber create/suspend/resume/destroy cost.
 *
 * Bench-only: no signal handling (kill ends the process), no graceful drain.
 *
 * @param array<int, string> $argv
 */
function runLadderServer(string $mode, array $argv): void
{
    $address   = '0.0.0.0:7832';
    $reusePort = false;

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--address=')) {
            $address = substr($argument, strlen('--address='));
        }

        if (str_starts_with($argument, '--reusePort=')) {
            $reusePort = substr($argument, strlen('--reusePort=')) === '1';
        }
    }

    $extension = Extension::get();
    $flowKey   = uniqid('http_ladder_', more_entropy: true);

    // The l2h rung reuses the real (private) HttpServer::decodeRequest via
    // reflection, so the pipeline under test is the production code, not a
    // copy; the invoke overhead is ~0.4 us and is noted in the plan.
    $psr17Factory  = new Psr17Factory();
    $decodeRequest = new ReflectionMethod(HttpServer::class, 'decodeRequest');

    // Mirrors the HttpServer constructor defaults; telemetry off.
    $serverTask = $extension->push(
        flowKey: $flowKey,
        payload: new ServePayload(
            address: $address,
            readHeaderTimeoutMs: 10_000,
            readTimeoutMs: 30_000,
            writeTimeoutMs: 30_000,
            idleTimeoutMs: 60_000,
            shutdownTimeoutMs: 10_000,
            maxRequestBody: 10_485_760,
            maxConcurrency: 0,
            handlerTimeoutMs: 60_000,
            reusePort: $reusePort,
            telemetrySocket: '',
            serverName: 'sconcur-ladder',
            telemetryIntervalMs: 0,
        ),
    );

    fwrite(
        STDERR,
        sprintf('sconcur http ladder (%s) listening on %s pid=%d%s', $mode, $address, getmypid(), PHP_EOL),
    );

    // Respond pushes go detached (empty flow key), exactly like the production
    // fire-and-forget respond: a push on the ladder flow would register a task
    // whose no-result completion never unregisters it, growing the flow's task
    // map by one entry per request and skewing the very numbers the ladder
    // measures (GC scan time over millions of dead entries).
    $respond = static function (string $requestPayload) use ($extension): void {
        $requestId = (string) (MessagePackTransport::unpack($requestPayload)['rid'] ?? '');

        $extension->push(
            flowKey: '',
            payload: RespondPayload::full(
                requestId: $requestId,
                status: 200,
                headers: [],
                body: 'ok',
            ),
        );
    };

    while (true) {
        $results = $extension->waitAnyBatch(maxResults: 256);

        foreach ($results as $result) {
            // Completion results of the respond pushes need no handling.
            if ($result->flowKey !== $flowKey || $result->key !== $serverTask->key) {
                continue;
            }

            // The listener stream ended: a stop, or a bind error.
            if (!$result->hasNext) {
                if ($result->isError) {
                    fwrite(STDERR, sprintf('ladder server error: %s%s', $result->payload, PHP_EOL));
                }

                return;
            }

            // No re-arm: the Go server pumps the next request event itself.
            if ($mode === 'l1') {
                $respond($result->payload);
            } elseif ($mode === 'l2h') {
                // l2 plus the full production handle pipeline inside the fiber:
                // decodeRequest -> PSR-7 request, nyholm response, headers into
                // the respond payload. L3 − l2h isolates the Scheduler/State
                // overhead, l2h − l2 the PHP handle pipeline under real load.
                $fiber = new Fiber(static function (string $requestPayload) use ($psr17Factory, $decodeRequest): void {
                    [$requestId, $request] = $decodeRequest->invoke(null, $psr17Factory, $requestPayload);

                    $response = $psr17Factory->createResponse(200);

                    $response->getBody()->write('ok');

                    Fiber::suspend(RespondPayload::full(
                        requestId: $requestId,
                        status: $response->getStatusCode(),
                        headers: $response->getHeaders(),
                        body: (string) $response->getBody(),
                    ));
                });

                $pendingRespond = $fiber->start($result->payload);

                if (!$pendingRespond instanceof RespondPayload) {
                    fwrite(STDERR, 'ladder l2h: fiber did not suspend with a RespondPayload' . PHP_EOL);

                    exit(1);
                }

                $extension->push(flowKey: '', payload: $pendingRespond);

                $fiber->resume();
            } elseif ($mode === 'l2') {
                // Production-shaped Fiber rung: the fiber suspends with its
                // pending respond payload, the loop performs the cgo push off
                // the fiber stack (like Scheduler::dispatchPendingTask) and
                // resumes the fiber to completion.
                $fiber = new Fiber(static function (string $requestPayload): void {
                    $requestId = (string) (MessagePackTransport::unpack($requestPayload)['rid'] ?? '');

                    Fiber::suspend(RespondPayload::full(
                        requestId: $requestId,
                        status: 200,
                        headers: [],
                        body: 'ok',
                    ));
                });

                $pendingRespond = $fiber->start($result->payload);

                if (!$pendingRespond instanceof RespondPayload) {
                    fwrite(STDERR, 'ladder l2: fiber did not suspend with a RespondPayload' . PHP_EOL);

                    exit(1);
                }

                $extension->push(flowKey: '', payload: $pendingRespond);

                $fiber->resume();
            } else {
                // l2f: the pathological reference — the same respond, but the
                // cgo push happens ON the fiber stack. Reproduces the
                // known-quadratic boundary-crossing cost the scheduler exists
                // to avoid (.ai/plans/async-fan-out-optimization.ru.md).
                (new Fiber($respond))->start($result->payload);
            }
        }
    }
}
