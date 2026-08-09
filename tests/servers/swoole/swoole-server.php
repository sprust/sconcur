<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use MongoDB\Client as NativeMongoClient;
use MongoDB\Collection as NativeMongoCollection;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOProxy;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Swoole reference server for the comparison with the SConcur HTTP server
 * (docs/benchmarks.ru.md, "Сравнение с RoadRunner и Swoole"). The second
 * reference stack next to tests/servers/roadrunner: same benchmark routes, same
 * backends, but the coroutine application-server model (one process = many
 * concurrent requests, native drivers hooked into the coroutine scheduler).
 *
 *   GET /          -> 200 "ok"
 *   GET /db?n={q}  -> {q} sequential point SELECTs on MySQL through PDO (default 1) —
 *                     the point-query ladder bench (docs/benchmarks.md)
 *   GET /db-rw     -> INSERT one row + COUNT(*) + point SELECT of a random id within
 *                     that count through PDO, JSON {count, record} — the read-write
 *                     ladder bench
 *   GET /all       -> MongoDB insertOne+findOne (mongodb/mongodb), MySQL INSERT +
 *                     SELECT 1 (PDO), PostgreSQL INSERT + SELECT 1 (PDO), sequentially
 *                     inside the request; per-feature error isolation, the same JSON
 *                     status map as the SConcur and RoadRunner /all
 *   GET /all-coro  -> the same three features fanned out in a Swoole coroutine
 *                     WaitGroup — Swoole's own answer to the SConcur fan-out
 *   (anything else) -> 404 "not found"
 *
 * The blocking drivers become non-blocking through the runtime hooks
 * (SWOOLE_HOOK_ALL): PDO MySQL/PostgreSQL are hooked, so a request parked on a
 * query lets the worker serve others. ext-mongodb is NOT hookable (libmongoc
 * drives its own sockets from C), so every MongoDB call blocks the whole worker
 * for its duration — a real property of the model, not of this handler, and the
 * /all rows carry it.
 *
 * A PDO connection is not safe to share between concurrent coroutines, so both
 * SQL backends go through a per-worker Swoole\Database\PDOPool — the direct
 * counterpart of the SConcur feature's per-process maxOpenConns pool (the sizes
 * are mirrored: 9 for the /db* ladder, 5 for /all).
 *
 * Run via `make swoole-serve` (the swoole extension is built into the php image;
 * it is not enabled globally, the launcher passes -d extension=swoole.so).
 *
 * Tunables (env): SWOOLE_HTTP_HOST, SWOOLE_HTTP_PORT, SWOOLE_NUM_WORKERS,
 * SWOOLE_DB_POOL_SIZE (the /db* pool), SWOOLE_ALL_POOL_SIZE (the /all pools).
 */

$serverHost         = (string) (getenv('SWOOLE_HTTP_HOST') ?: '0.0.0.0');
$serverPort         = (int) (getenv('SWOOLE_HTTP_PORT') ?: 18082);
$workerCount        = (int) (getenv('SWOOLE_NUM_WORKERS') ?: 16);
$databasePoolSize   = (int) (getenv('SWOOLE_DB_POOL_SIZE') ?: 9);
$allFeaturesPool    = (int) (getenv('SWOOLE_ALL_POOL_SIZE') ?: 5);

$server = new Server($serverHost, $serverPort);

$server->set([
    // hook_flags turns the blocking drivers (PDO, curl, streams, sleep) into
    // coroutine-aware calls in every worker — without it a Swoole worker is just
    // a slower php-fpm process.
    'hook_flags'       => SWOOLE_HOOK_ALL,
    'enable_coroutine' => true,
    'worker_num'       => $workerCount,
    'log_level'        => SWOOLE_LOG_ERROR,
    // The benchmark compares transport cost, not gzip: keep the response bytes
    // identical to what the SConcur and RoadRunner servers send.
    'http_compression' => false,
]);

$server->on('request', static function (Request $request, Response $response) use (
    $databasePoolSize,
    $allFeaturesPool,
): void {
    $path = $request->server['request_uri'] ?? '/';

    // Every route is GET-only, exactly like the SConcur demo server and the
    // RoadRunner worker: a stray POST must not run the bench INSERTs.
    if (($request->server['request_method'] ?? 'GET') !== 'GET') {
        swooleRespond($response, 'method not allowed', 405);

        return;
    }

    try {
        [$body, $statusCode] = match ($path) {
            '/'         => ['ok', 200],
            '/db'       => swooleDbPointSelectRoute($request, $databasePoolSize),
            '/db-rw'    => swooleDbReadWriteRoute($databasePoolSize),
            '/all'      => swooleAllFeaturesRoute($allFeaturesPool),
            '/all-coro' => swooleAllFeaturesCoroutineRoute($allFeaturesPool),
            default     => ['not found', 404],
        };

        swooleRespond($response, $body, $statusCode);
    } catch (Throwable $exception) {
        swooleRespond($response, 'error: ' . $exception->getMessage(), 500);
    }
});

$server->start();

/**
 * Sends a plain response: status + body (mirror of text() in http-server.php and
 * rrText() in the RoadRunner worker — a JSON body gets the JSON content type).
 */
function swooleRespond(Response $response, string $body, int $statusCode = 200): void
{
    $response->status($statusCode);
    $response->header('Content-Type', ($body !== '' && $body[0] === '{') ? 'application/json' : 'text/plain');
    $response->end($body);
}

/**
 * Per-worker lazy singleton. Concurrent coroutines in one worker would otherwise
 * all miss the cache and seed the bench tables in parallel, so the first one
 * takes a one-slot channel as a mutex and the rest park on it until the value is
 * built. (Building the channel itself never yields, so the check above it is
 * atomic inside a worker.)
 */
function swooleOnce(string $key, Closure $factory): mixed
{
    /** @var array<string, mixed> $values */
    static $values = [];
    /** @var array<string, Channel> $locks */
    static $locks = [];

    if (array_key_exists($key, $values)) {
        return $values[$key];
    }

    if (!isset($locks[$key])) {
        $locks[$key] = new Channel(1);

        $locks[$key]->push(true);
    }

    $locks[$key]->pop();

    try {
        if (!array_key_exists($key, $values)) {
            $values[$key] = $factory();
        }
    } finally {
        $locks[$key]->push(true);
    }

    return $values[$key];
}

/**
 * Runs one call against a pooled connection and always returns it to the pool.
 * A connection is checked out for one operation only — that is what makes the
 * pool the concurrency limit of the worker, exactly like the SConcur feature's
 * maxOpenConns. The pool hands out a PDOProxy (a reconnecting wrapper around
 * PDO), so the callbacks take that type, not PDO.
 */
function swooleWithPdo(PDOPool $pool, Closure $call): mixed
{
    $pdo = $pool->get();

    try {
        return $call($pdo);
    } finally {
        $pool->put($pdo);
    }
}

/**
 * Loads .env once per worker (the driver credentials come from the same file as
 * every other server in this repo).
 */
function swooleEnv(): void
{
    swooleOnce('env', static function (): bool {
        Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

        return true;
    });
}

/**
 * The MySQL pool of the /db* bench routes. Standalone by design: those routes
 * must not drag in the Mongo and PostgreSQL connections of the /all context —
 * the SConcur side of the ladder opens MySQL only.
 */
function swooleDbMysqlPool(int $poolSize): PDOPool
{
    /** @var PDOPool */
    return swooleOnce('db-mysql-pool', static function () use ($poolSize): PDOPool {
        swooleEnv();

        return new PDOPool(
            (new PDOConfig())
                ->withDriver('mysql')
                ->withHost((string) $_ENV['MYSQL_HOST'])
                ->withPort((int) $_ENV['MYSQL_PORT'])
                ->withDbName((string) $_ENV['MYSQL_DATABASE'])
                ->withCharset('utf8mb4')
                ->withUsername((string) $_ENV['MYSQL_USER'])
                ->withPassword((string) $_ENV['MYSQL_PASSWORD'])
                ->withOptions([PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
            $poolSize,
        );
    });
}

/**
 * Point-query bench route (the worker-count ladder in docs/benchmarks.md): ?n=
 * sequential point SELECTs per request (default 1) through the hooked PDO — the
 * coroutine counterpart of the SConcur and RoadRunner /db.
 *
 * @return array{0: string, 1: int}
 */
function swooleDbPointSelectRoute(Request $request, int $poolSize): array
{
    $pool = swooleDbMysqlPool($poolSize);

    swooleDbPointSelectSeed($pool);

    $queryCount = max(1, (int) ($request->get['n'] ?? 1));

    $rows = swooleWithPdo($pool, static function (PDOProxy $mysql) use ($queryCount): array {
        $statement = $mysql->prepare('SELECT id, t FROM bench_seed WHERE id = ?');

        $rows = [];

        for ($queryIndex = 0; $queryIndex < $queryCount; $queryIndex++) {
            $statement->execute([random_int(1, 1000)]);

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        return $rows;
    });

    $responseBody = json_encode($rows);

    if ($responseBody === false) {
        return ['json encode failed', 500];
    }

    return [$responseBody, 200];
}

/**
 * Makes sure the seeded table exists (1 000 fixed-shape rows, once per worker),
 * so the handle works out of the box — the mirror of dbPointSelectContext() in
 * http-server.php.
 */
function swooleDbPointSelectSeed(PDOPool $pool): void
{
    swooleOnce('db-point-select-seed', static function () use ($pool): bool {
        swooleWithPdo($pool, static function (PDOProxy $mysql): void {
            $mysql->exec('CREATE TABLE IF NOT EXISTS bench_seed (id BIGINT PRIMARY KEY, t VARCHAR(64) NOT NULL)');

            if ((int) $mysql->query('SELECT COUNT(*) FROM bench_seed')->fetchColumn() < 1000) {
                $insert = $mysql->prepare('INSERT IGNORE INTO bench_seed (id, t) VALUES (?, ?)');

                for ($id = 1; $id <= 1000; $id++) {
                    $insert->execute([$id, 'row-' . $id . '-' . str_repeat('x', 20)]);
                }
            }
        });

        return true;
    });
}

/**
 * Read-write bench route (the worker-count ladder in docs/benchmarks.md): one
 * INSERT, then COUNT(*), then a point SELECT of a random id within that count
 * through the hooked PDO — the coroutine counterpart of the SConcur and
 * RoadRunner /db-rw.
 *
 * @return array{0: string, 1: int}
 */
function swooleDbReadWriteRoute(int $poolSize): array
{
    $pool = swooleDbMysqlPool($poolSize);

    swooleDbReadWriteSeed($pool);

    $result = swooleWithPdo($pool, static function (PDOProxy $mysql): array {
        $mysql
            ->prepare('INSERT INTO bench_rw (title, quantity, price, active, created_date) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                uniqid('row-'),
                random_int(1, 1_000_000),
                random_int(1, 1_000_000) / 100,
                random_int(0, 1),
                date('Y-m-d'),
            ]);

        $rowCount = (int) $mysql->query('SELECT COUNT(*) FROM bench_rw')->fetchColumn();

        $select = $mysql->prepare('SELECT id, title, quantity, price, active, created_date FROM bench_rw WHERE id = ?');

        $select->execute([random_int(1, max(1, $rowCount))]);

        $record = $select->fetch(PDO::FETCH_ASSOC);

        return [
            'count'  => $rowCount,
            'record' => ($record === false) ? null : $record,
        ];
    });

    $responseBody = json_encode($result);

    if ($responseBody === false) {
        return ['json encode failed', 500];
    }

    return [$responseBody, 200];
}

/**
 * Makes sure the read-write table exists and is seeded (10 000 fixed-shape rows
 * across five typed columns, multi-row batches so a cold disk-backed MySQL seeds
 * in seconds) — the mirror of dbReadWriteContext() in http-server.php. INSERT
 * IGNORE with explicit ids keeps concurrent seeding by workers idempotent.
 */
function swooleDbReadWriteSeed(PDOPool $pool): void
{
    swooleOnce('db-read-write-seed', static function () use ($pool): bool {
        swooleWithPdo($pool, static function (PDOProxy $mysql): void {
            $mysql->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS bench_rw (
                    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(64) NOT NULL,
                    quantity INT NOT NULL,
                    price DOUBLE NOT NULL,
                    active TINYINT(1) NOT NULL,
                    created_date DATE NOT NULL
                )
                SQL);

            if ((int) $mysql->query('SELECT COUNT(*) FROM bench_rw')->fetchColumn() >= 10_000) {
                return;
            }

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

                $mysql
                    ->prepare(
                        'INSERT IGNORE INTO bench_rw (id, title, quantity, price, active, created_date) VALUES '
                            . implode(', ', $placeholders),
                    )
                    ->execute($bindings);
            }
        });

        return true;
    });
}

/**
 * Sequential copy of allFeaturesRoute() from http-server.php: the same three
 * features against the same backends, each isolated so a transient backend
 * hiccup stays visible per feature in the JSON map, but any failed feature turns
 * the response into a 500 (load tools then count the request as an error).
 *
 * Sequential inside the request, concurrent across requests: the hooked PDO
 * calls yield the worker to other requests, the MongoDB call does not.
 *
 * @return array{0: string, 1: int}
 */
function swooleAllFeaturesRoute(int $poolSize): array
{
    [$mongo, $mysqlPool, $pgsqlPool] = swooleAllFeaturesContext($poolSize);

    $status = [];

    $status['mongodb'] = swooleAllFeatureStatus(static function () use ($mongo): void {
        $mongo->insertOne(['t' => 'load']);
        $mongo->findOne(['t' => 'load']);
    });

    $status['mysql'] = swooleAllFeatureStatus(static function () use ($mysqlPool): void {
        swooleWithPdo($mysqlPool, static function (PDOProxy $mysql): void {
            $mysql->prepare('INSERT INTO load_all (t) VALUES (?)')->execute(['load']);

            $mysql->query('SELECT 1')->fetchAll();
        });
    });

    $status['pgsql'] = swooleAllFeatureStatus(static function () use ($pgsqlPool): void {
        swooleWithPdo($pgsqlPool, static function (PDOProxy $pgsql): void {
            $pgsql->prepare('INSERT INTO load_all (t) VALUES (?)')->execute(['load']);

            $pgsql->query('SELECT 1')->fetchAll();
        });
    });

    return swooleAllFeaturesResponse($status);
}

/**
 * The fan-out counterpart of the route above: the same three features, but each
 * in its own Swoole coroutine joined by a Coroutine\WaitGroup — Swoole's own
 * answer to the SConcur WaitGroup. The two SQL features overlap for real (hooked
 * PDO); the MongoDB coroutine blocks the worker while libmongoc waits, so the
 * fan-out here can only overlap what the runtime hooks reach.
 *
 * @return array{0: string, 1: int}
 */
function swooleAllFeaturesCoroutineRoute(int $poolSize): array
{
    [$mongo, $mysqlPool, $pgsqlPool] = swooleAllFeaturesContext($poolSize);

    $status = [];

    $waitGroup = new WaitGroup();

    $waitGroup->add(3);

    go(static function () use ($waitGroup, &$status, $mongo): void {
        $status['mongodb'] = swooleAllFeatureStatus(static function () use ($mongo): void {
            $mongo->insertOne(['t' => 'load']);
            $mongo->findOne(['t' => 'load']);
        });

        $waitGroup->done();
    });

    go(static function () use ($waitGroup, &$status, $mysqlPool): void {
        $status['mysql'] = swooleAllFeatureStatus(static function () use ($mysqlPool): void {
            swooleWithPdo($mysqlPool, static function (PDOProxy $mysql): void {
                $mysql->prepare('INSERT INTO load_all (t) VALUES (?)')->execute(['load']);

                $mysql->query('SELECT 1')->fetchAll();
            });
        });

        $waitGroup->done();
    });

    go(static function () use ($waitGroup, &$status, $pgsqlPool): void {
        $status['pgsql'] = swooleAllFeatureStatus(static function () use ($pgsqlPool): void {
            swooleWithPdo($pgsqlPool, static function (PDOProxy $pgsql): void {
                $pgsql->prepare('INSERT INTO load_all (t) VALUES (?)')->execute(['load']);

                $pgsql->query('SELECT 1')->fetchAll();
            });
        });

        $waitGroup->done();
    });

    $waitGroup->wait();

    return swooleAllFeaturesResponse($status);
}

/**
 * Turns the per-feature status map into the response the SConcur and RoadRunner
 * /all routes send: the JSON map, and 500 as soon as one feature failed.
 *
 * @param array<string, string> $status
 *
 * @return array{0: string, 1: int}
 */
function swooleAllFeaturesResponse(array $status): array
{
    $statusCode = 200;

    foreach ($status as $featureStatus) {
        if ($featureStatus !== 'ok') {
            $statusCode = 500;

            break;
        }
    }

    return [(string) json_encode($status), $statusCode];
}

/**
 * Lazily builds and caches the per-worker connections on the first /all hit
 * (mirror of allFeaturesContext() in http-server.php, including the pool cap —
 * the pools live per worker process, and unbounded ones across ~nproc workers
 * would exhaust PostgreSQL's max_connections). Same .env, same backends, same
 * collection/table names.
 *
 * The MongoDB client is a single per-worker instance and needs no pool: its
 * calls are not hooked, so they never run concurrently inside a worker.
 *
 * @return array{0: NativeMongoCollection, 1: PDOPool, 2: PDOPool}
 */
function swooleAllFeaturesContext(int $poolSize): array
{
    /** @var array{0: NativeMongoCollection, 1: PDOPool, 2: PDOPool} */
    return swooleOnce('all-features-context', static function () use ($poolSize): array {
        swooleEnv();

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

        $mysqlPool = new PDOPool(
            (new PDOConfig())
                ->withDriver('mysql')
                ->withHost((string) $_ENV['MYSQL_HOST'])
                ->withPort((int) $_ENV['MYSQL_PORT'])
                ->withDbName((string) $_ENV['MYSQL_DATABASE'])
                ->withCharset('utf8mb4')
                ->withUsername((string) $_ENV['MYSQL_USER'])
                ->withPassword((string) $_ENV['MYSQL_PASSWORD'])
                ->withOptions([PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
            $poolSize,
        );

        $pgsqlPool = new PDOPool(
            (new PDOConfig())
                ->withDriver('pgsql')
                ->withHost((string) $_ENV['POSTGRES_HOST'])
                ->withPort((int) $_ENV['POSTGRES_PORT'])
                ->withDbName((string) $_ENV['POSTGRES_DB'])
                ->withUsername((string) $_ENV['POSTGRES_USER'])
                ->withPassword((string) $_ENV['POSTGRES_PASSWORD'])
                ->withOptions([PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
            $poolSize,
        );

        swooleWithPdo($mysqlPool, static function (PDOProxy $mysql): void {
            $mysql->exec(
                'CREATE TABLE IF NOT EXISTS load_all (id BIGINT AUTO_INCREMENT PRIMARY KEY, t VARCHAR(16) NOT NULL)',
            );
        });

        swooleWithPdo($pgsqlPool, static function (PDOProxy $pgsql): void {
            $pgsql->exec('CREATE TABLE IF NOT EXISTS load_all (id BIGSERIAL PRIMARY KEY, t VARCHAR(16) NOT NULL)');
        });

        return [$mongo, $mysqlPool, $pgsqlPool];
    });
}

/**
 * Runs one feature call and returns 'ok' or 'err: <message>' — the same
 * per-feature isolation as allFeatureStatus() in http-server.php.
 */
function swooleAllFeatureStatus(callable $call): string
{
    try {
        $call();

        return 'ok';
    } catch (Throwable $exception) {
        return 'err: ' . $exception->getMessage();
    }
}
