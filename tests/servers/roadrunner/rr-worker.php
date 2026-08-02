<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use MongoDB\Client as NativeMongoClient;
use MongoDB\Collection as NativeMongoCollection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Features\Mongodb\Connection\Client as SconcurMongoClient;
use SConcur\Features\Mongodb\Connection\Collection as SconcurMongoCollection;
use SConcur\Features\Mysql\Connection as SconcurMysqlConnection;
use SConcur\Features\Pgsql\Connection as SconcurPgsqlConnection;
use SConcur\WaitGroup;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

/**
 * RoadRunner reference worker for the honest comparison with the SConcur HTTP
 * server (docs/benchmarks.ru.md, "Сравнение с RoadRunner"). Serves exact copies
 * of the benchmark routes of tests/servers/http/http-server.php:
 *   GET /    -> 200 "ok"
 *   GET /db?n={q} -> {q} sequential point SELECTs on MySQL via PDO (default 1) —
 *               the point-query ladder bench (docs/benchmarks.md)
 *   GET /db-rw -> INSERT one row + COUNT(*) + point SELECT of a random id within
 *               that count via PDO, JSON {count, record} — the read-write ladder bench
 *   GET /all -> MongoDB insertOne+findOne (mongodb/mongodb), MySQL INSERT +
 *               SELECT 1 (PDO), PostgreSQL INSERT + SELECT 1 (PDO) — NATIVE
 *               drivers, sequentially (a RoadRunner worker has no internal
 *               concurrency); per-feature error isolation, same JSON status map
 *   GET /all-sconcur -> the same three features, but through SConcur fanned out
 *               in a WaitGroup — the "SConcur inside a RoadRunner worker" story.
 *               Needs the extension in the worker: start rr with
 *               RR_WORKER_CMD='php -d extension=/sconcur/ext/build/sconcur.so rr-worker.php'
 *   (anything else) -> 404 "not found"
 *
 * The backends and the payload shape are identical to the SConcur /all route —
 * only the driver stack differs. Run via `make rr-serve` (rr binary and the
 * spiral/roadrunner-* packages are installed in the php container image).
 */

$psr17Factory = new Psr17Factory();

$psr7Worker = new PSR7Worker(
    Worker::create(),
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
);

while (true) {
    try {
        $request = $psr7Worker->waitRequest();
    } catch (Throwable) {
        // Malformed request payload: report and keep the worker alive.
        $psr7Worker->respond(rrText($psr17Factory, 'bad request', 400));

        continue;
    }

    if ($request === null) {
        // Graceful stop from RoadRunner.
        break;
    }

    try {
        $path = $request->getUri()->getPath();

        // Every route is GET-only, exactly like the SConcur demo server: a stray
        // POST must not run the bench INSERTs.
        if ($request->getMethod() !== 'GET') {
            $psr7Worker->respond(rrText($psr17Factory, 'method not allowed', 405));

            continue;
        }

        $response = match ($path) {
            '/'            => rrText($psr17Factory, 'ok'),
            '/db'          => rrDbPointSelectRoute($psr17Factory, $request),
            '/db-rw'       => rrDbReadWriteRoute($psr17Factory),
            '/all'         => rrAllFeaturesRoute($psr17Factory),
            '/all-sconcur' => rrAllFeaturesSconcurRoute($psr17Factory),
            default        => rrText($psr17Factory, 'not found', 404),
        };

        $psr7Worker->respond($response);
    } catch (Throwable $exception) {
        $psr7Worker->respond(rrText($psr17Factory, 'error: ' . $exception->getMessage(), 500));
    }
}

/**
 * Builds a plain response: status + optional body (mirror of text() in
 * http-server.php, without the headers the benchmark routes do not use).
 */
function rrText(Psr17Factory $factory, string $body = '', int $status = 200): ResponseInterface
{
    $response = $factory->createResponse($status);

    if ($body !== '') {
        $response = $response->withBody($factory->createStream($body));
    }

    return $response->withHeader('Content-Type', $body !== '' && $body[0] === '{' ? 'application/json' : 'text/plain');
}

/**
 * Native, sequential copy of allFeaturesRoute() from http-server.php: the same
 * three features against the same backends, each isolated so a transient backend
 * hiccup stays visible per feature in the JSON map, but any failed feature turns
 * the response into a 500 (load tools then count the request as an error).
 */
// Standalone MySQL PDO for the /db* bench routes: they must not drag in the
// Mongo and PostgreSQL connections of the /all context — the SConcur side of
// the ladder opens MySQL only, so the extra backends would skew the per-worker
// footprint (and fail the routes when those containers are down).
function rrDbMysqlPdo(): PDO
{
    static $mysql = null;

    if ($mysql !== null) {
        return $mysql;
    }

    Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

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

    return $mysql;
}

// Point-query bench route (the worker-count ladder vs RoadRunner in
// docs/benchmarks.md): ?n= sequential point SELECTs per request (default 1)
// through native PDO — the sequential-worker counterpart of the SConcur /db.
function rrDbPointSelectRoute(Psr17Factory $factory, ServerRequestInterface $request): ResponseInterface
{
    $mysql = rrDbMysqlPdo();

    rrDbPointSelectSeed($mysql);

    parse_str($request->getUri()->getQuery(), $queryParams);

    $queryCount = max(1, (int) ($queryParams['n'] ?? 1));

    $statement = $mysql->prepare('SELECT id, t FROM bench_seed WHERE id = ?');

    $rows = [];

    for ($queryIndex = 0; $queryIndex < $queryCount; $queryIndex++) {
        $statement->execute([random_int(1, 1000)]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    $responseBody = json_encode($rows);

    if ($responseBody === false) {
        return rrText($factory, 'json encode failed', 500);
    }

    return rrText($factory, $responseBody);
}

// Makes sure the seeded table exists (1 000 fixed-shape rows, once per worker),
// so the handle works out of the box — the mirror of dbPointSelectContext() in
// http-server.php.
function rrDbPointSelectSeed(PDO $mysql): void
{
    static $seeded = false;

    if ($seeded) {
        return;
    }

    $mysql->exec('CREATE TABLE IF NOT EXISTS bench_seed (id BIGINT PRIMARY KEY, t VARCHAR(64) NOT NULL)');

    if ((int) $mysql->query('SELECT COUNT(*) FROM bench_seed')->fetchColumn() < 1000) {
        $insert = $mysql->prepare('INSERT IGNORE INTO bench_seed (id, t) VALUES (?, ?)');

        for ($id = 1; $id <= 1000; $id++) {
            $insert->execute([$id, 'row-' . $id . '-' . str_repeat('x', 20)]);
        }
    }

    $seeded = true;
}

// Read-write bench route (the worker-count ladder vs RoadRunner in
// docs/benchmarks.md): one INSERT, then COUNT(*), then a point SELECT of a
// random id within that count via native PDO — the sequential-worker
// counterpart of the SConcur /db-rw.
function rrDbReadWriteRoute(Psr17Factory $factory): ResponseInterface
{
    $mysql = rrDbMysqlPdo();

    rrDbReadWriteSeed($mysql);

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

    $responseBody = json_encode([
        'count'  => $rowCount,
        'record' => ($record === false) ? null : $record,
    ]);

    if ($responseBody === false) {
        return rrText($factory, 'json encode failed', 500);
    }

    return rrText($factory, $responseBody);
}

// Makes sure the read-write table exists and is seeded (10 000 fixed-shape rows
// across five typed columns, multi-row batches so a cold disk-backed MySQL
// seeds in seconds) — the mirror of dbReadWriteContext() in http-server.php.
// INSERT IGNORE with explicit ids keeps concurrent seeding by workers
// idempotent.
function rrDbReadWriteSeed(PDO $mysql): void
{
    static $seeded = false;

    if ($seeded) {
        return;
    }

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

    if ((int) $mysql->query('SELECT COUNT(*) FROM bench_rw')->fetchColumn() < 10_000) {
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
    }

    $seeded = true;
}

function rrAllFeaturesRoute(Psr17Factory $factory): ResponseInterface
{
    [$mongo, $mysql, $pgsql] = rrAllFeaturesContext();

    $status = [];

    $status['mongodb'] = rrAllFeatureStatus(static function () use ($mongo): void {
        $mongo->insertOne(['t' => 'load']);
        $mongo->findOne(['t' => 'load']);
    });

    $status['mysql'] = rrAllFeatureStatus(static function () use ($mysql): void {
        $statement = $mysql->prepare('INSERT INTO load_all (t) VALUES (?)');

        $statement->execute(['load']);

        $mysql->query('SELECT 1')->fetchAll();
    });

    $status['pgsql'] = rrAllFeatureStatus(static function () use ($pgsql): void {
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

    return rrText($factory, (string) json_encode($status), $statusCode);
}

/**
 * Lazily builds and caches the per-worker native connections on the first /all
 * hit (mirror of allFeaturesContext() in http-server.php) and makes sure the
 * load_all tables exist. Same .env, same backends, same collection/table names.
 *
 * @return array{0: NativeMongoCollection, 1: PDO, 2: PDO}
 */
function rrAllFeaturesContext(): array
{
    /** @var array{0: NativeMongoCollection, 1: PDO, 2: PDO}|null $context */
    static $context = null;

    if ($context !== null) {
        return $context;
    }

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

    $context = [$mongo, $mysql, $pgsql];

    return $context;
}

/**
 * Copy of allFeaturesRoute() from http-server.php inside the RoadRunner worker:
 * the same three features against the same backends, but through the SConcur
 * features fanned out concurrently in a WaitGroup. Shows that SConcur does not
 * replace RoadRunner but complements it — the worker stays a plain RR worker,
 * only the request body gains fan-out concurrency. Same per-feature isolation,
 * same JSON status map, 500 on any failed feature.
 */
function rrAllFeaturesSconcurRoute(Psr17Factory $factory): ResponseInterface
{
    if (!extension_loaded('sconcur')) {
        return rrText(
            $factory,
            'sconcur extension not loaded: start rr with RR_WORKER_CMD=\'php -d extension=/sconcur/ext/build/sconcur.so rr-worker.php\'',
            500,
        );
    }

    [$mongo, $mysql, $pgsql] = rrAllFeaturesSconcurContext();

    $status = [];

    $waitGroup = WaitGroup::create();

    $waitGroup->add(static function () use (&$status, $mongo): void {
        $status['mongodb'] = rrAllFeatureStatus(static function () use ($mongo): void {
            $mongo->insertOne(['t' => 'load']);
            $mongo->findOne(filter: ['t' => 'load']);
        });
    });

    $waitGroup->add(static function () use (&$status, $mysql): void {
        $status['mysql'] = rrAllFeatureStatus(static function () use ($mysql): void {
            $mysql->exec(
                sql: 'INSERT INTO load_all (t) VALUES (?)',
                bindings: ['load'],
            );

            $mysql->fetchAll('SELECT 1');
        });
    });

    $waitGroup->add(static function () use (&$status, $pgsql): void {
        $status['pgsql'] = rrAllFeatureStatus(static function () use ($pgsql): void {
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

    return rrText($factory, (string) json_encode($status), $statusCode);
}

/**
 * Lazily builds and caches the per-worker SConcur connections on the first
 * /all-sconcur hit (mirror of allFeaturesContext() in http-server.php, including
 * the maxOpenConns: 5 cap — the Go-side pool is per worker process, and an
 * unbounded pool across ~nproc workers would exhaust PostgreSQL's
 * max_connections). Same .env, same backends, same collection/table names.
 *
 * @return array{0: SconcurMongoCollection, 1: SconcurMysqlConnection, 2: SconcurPgsqlConnection}
 */
function rrAllFeaturesSconcurContext(): array
{
    /** @var array{0: SconcurMongoCollection, 1: SconcurMysqlConnection, 2: SconcurPgsqlConnection}|null $context */
    static $context = null;

    if ($context !== null) {
        return $context;
    }

    Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

    $context = [
        new SconcurMongoClient(
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
        new SconcurMysqlConnection(
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
        new SconcurPgsqlConnection(
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
}

/**
 * Runs one feature call and returns 'ok' or 'err: <message>' — the same
 * per-feature isolation as allFeatureStatus() in http-server.php.
 */
function rrAllFeatureStatus(callable $call): string
{
    try {
        $call();

        return 'ok';
    } catch (Throwable $exception) {
        return 'err: ' . $exception->getMessage();
    }
}
