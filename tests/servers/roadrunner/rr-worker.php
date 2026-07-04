<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use MongoDB\Client as NativeMongoClient;
use MongoDB\Collection as NativeMongoCollection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

/**
 * RoadRunner reference worker for the honest comparison with the SConcur HTTP
 * server (docs/benchmarks.ru.md, "Сравнение с RoadRunner"). Serves exact copies
 * of the two benchmark routes of tests/servers/http/http-server.php, but on
 * NATIVE drivers, sequentially (a RoadRunner worker has no internal concurrency):
 *   GET /    -> 200 "ok"
 *   GET /all -> usleep(1000), MongoDB insertOne+findOne (mongodb/mongodb),
 *               MySQL INSERT + SELECT 1 (PDO), PostgreSQL INSERT + SELECT 1 (PDO);
 *               per-feature error isolation, same JSON status map
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

        $response = match ($path) {
            '/'     => rrText($psr17Factory, 'ok'),
            '/all'  => rrAllFeaturesRoute($psr17Factory),
            default => rrText($psr17Factory, 'not found', 404),
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
 * four features against the same backends, each isolated so a transient backend
 * hiccup degrades just that feature; returns the same JSON status map. The
 * mandatory usleep(1000) is the sequential price a native worker pays for the
 * sleep the SConcur fan-out overlaps.
 */
function rrAllFeaturesRoute(Psr17Factory $factory): ResponseInterface
{
    [$mongo, $mysql, $pgsql] = rrAllFeaturesContext();

    $status = [];

    $status['sleeper'] = rrAllFeatureStatus(static function (): void {
        usleep(1000);
    });

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

    return rrText($factory, (string) json_encode($status));
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
