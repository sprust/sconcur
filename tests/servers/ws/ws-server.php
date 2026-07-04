<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use SConcur\Features\Mongodb\Connection\Client as MongoClient;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Features\Mysql\Connection as MysqlConnection;
use SConcur\Features\Pgsql\Connection as PgsqlConnection;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Features\WsServer\Dto\Connection;
use SConcur\Features\WsServer\WsServer;
use SConcur\WaitGroup;
use Throwable;

/**
 * Demo / test WebSocket server (push model). Messages are WebSocket frames (text by
 * default, binary-safe), both ways. The handler drives the connection: it reads inbound
 * messages in a loop and pushes messages back. The inbound message is a small text
 * command:
 *   "ping"            -> push "pong"
 *   "pid"             -> push this process pid (used by the worker-master tests)
 *   "upper:<text>"    -> push uppercased <text>
 *   "msleep:<ms>"     -> async sleep <ms>, then push "slept" (concurrency demo)
 *   "cpu:<n>"         -> a CPU-bound sha256 loop of <n> rounds, then push the digest (bench)
 *   "push:<n>"        -> push <n> messages "p0".."p(n-1)" for one inbound message (server push)
 *   "stream:<n>"      -> stream <n> messages "s0".."s(n-1)" 60ms apart (async between messages)
 *   "bin:<text>"      -> push <text> back as a binary message
 *   "all"             -> fan out across the backend I/O features concurrently (load test)
 *   "noreply"         -> read but push nothing, connection stays open
 *   "closeafter:<t>"  -> push <t>, then close the connection
 *   "close"           -> close the connection (no push)
 *   "throw"           -> handler throws -> default error path (connection closed)
 *   "throw-handled"   -> handler throws -> onError pushes a final "ERR:..." message
 *   (anything else)   -> echoed back unchanged (preserving text/binary)
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/servers/ws/ws-server.php [--option=value ...]
 *
 * Launch options are named exactly like the WsServer constructor parameters, passed as
 * --name=value (e.g. --address=0.0.0.0:9200 --maxConcurrency=4).
 */

// Build the server from argv: each --name=value maps to the matching WsServer
// constructor parameter. Under WorkerMaster the injected --masterPid wires the orphan
// check. onError opts a specific failure into a final message; others stay silent.
$server = WsServer::fromArgs(
    $_SERVER['argv'],
    onError: static function (Throwable $exception, Connection $connection): void {
        // Only "throw-handled" opts into a final error message; a plain "throw" stays
        // silent, so both paths are observable.
        if (str_contains($exception->getMessage(), 'HANDLED')) {
            try {
                $connection->write('ERR:' . $exception->getMessage());
            } catch (Throwable) {
                // The connection may already be gone.
            }
        }
    },
);

$server->serve(static function (Connection $connection): void {
    while (!$connection->isClosed() && ($message = $connection->read()) !== null) {
        handleMessage($connection, $message);
    }
});

function handleMessage(Connection $connection, string $data): void
{
    if ($data === 'ping') {
        $connection->write('pong');

        return;
    }

    if ($data === 'pid') {
        $connection->write((string) getmypid());

        return;
    }

    if ($data === 'noreply') {
        return;
    }

    if ($data === 'close') {
        $connection->close();

        return;
    }

    if ($data === 'throw') {
        throw new RuntimeException('boom in handler');
    }

    if ($data === 'throw-handled') {
        throw new RuntimeException('HANDLED boom');
    }

    if (str_starts_with($data, 'upper:')) {
        $connection->write(strtoupper(substr($data, strlen('upper:'))));

        return;
    }

    if (str_starts_with($data, 'bin:')) {
        $connection->write(substr($data, strlen('bin:')), binary: true);

        return;
    }

    if (str_starts_with($data, 'msleep:')) {
        $milliseconds = (int) substr($data, strlen('msleep:'));

        Sleeper::usleep(microseconds: $milliseconds * 1000);

        $connection->write('slept');

        return;
    }

    if (str_starts_with($data, 'cpu:')) {
        // CPU-bound sha256 loop that does NOT yield — used by the CPU benchmark to show
        // SO_REUSEPORT spreading compute across processes/cores.
        $iterations = (int) substr($data, strlen('cpu:'));

        $value = '';

        for ($index = 0; $index < $iterations; $index++) {
            $value = hash('sha256', $value . $index);
        }

        $connection->write($value);

        return;
    }

    if (str_starts_with($data, 'push:')) {
        $count = (int) substr($data, strlen('push:'));

        for ($index = 0; $index < $count; $index++) {
            $connection->write('p' . $index);
        }

        return;
    }

    if (str_starts_with($data, 'stream:')) {
        $count = (int) substr($data, strlen('stream:'));

        for ($index = 0; $index < $count; $index++) {
            // Async work between messages: the first flushes immediately, then the
            // handler cooperatively suspends, so other connections keep running.
            if ($index > 0) {
                Sleeper::usleep(microseconds: 60_000);
            }

            $connection->write('s' . $index);
        }

        return;
    }

    if (str_starts_with($data, 'closeafter:')) {
        $connection->write(substr($data, strlen('closeafter:')));
        $connection->close();

        return;
    }

    if ($data === 'all') {
        // Load-test command: fan out across the backend I/O features concurrently.
        $connection->write(allFeaturesStatus());

        return;
    }

    // Default: echo the message back, preserving its text/binary type.
    $connection->write($data, binary: $connection->lastMessageWasBinary());
}

/**
 * Load-test fan-out: runs the backend I/O features concurrently in one message (a
 * nested WaitGroup) — Sleeper, MongoDB, MySQL, PostgreSQL — and returns a JSON status
 * map. Mirrors the HTTP demo server's /all route; used to watch memory/CPU under load.
 * Each feature is isolated so a transient backend hiccup degrades just that feature.
 */
function allFeaturesStatus(): string
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

    return (string) json_encode($status);
}

/**
 * Lazily builds and caches the per-worker DB connections used by "all" on its first
 * use (so the other demo commands never pay for them and never require the backends).
 * The Go side pools the real connections by URI/DSN, so reusing these objects is cheap.
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
        // Pool cap mirrors the HTTP demo server: the Go-side pool is per worker
        // process, and an unbounded pool under the load harness exhausts the DB
        // server limits (PostgreSQL max_connections=100).
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
}

/**
 * Runs one feature call and returns 'ok' or 'err: <message>', so a transient backend
 * failure degrades that one feature instead of failing the whole "all" message.
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
