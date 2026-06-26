<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Features\WsServer\Dto\Connection;
use SConcur\Features\WsServer\WsServer;
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

    // Default: echo the message back, preserving its text/binary type.
    $connection->write($data, binary: $connection->lastMessageWasBinary());
}
