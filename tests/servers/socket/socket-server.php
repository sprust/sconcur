<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Features\SocketServer\Dto\Message;
use SConcur\Features\SocketServer\Dto\MessageResponse;
use SConcur\Features\SocketServer\SocketServer;
use Throwable;

/**
 * Demo / test socket server. Each message is a 4-byte big-endian length prefix +
 * payload, both ways. The message data is a small text command:
 *   "ping"            -> "pong"
 *   "pid"             -> this process pid (used by the worker-master tests)
 *   "upper:<text>"    -> uppercased <text>
 *   "msleep:<ms>"     -> async sleep <ms>, then "slept" (concurrency demo)
 *   "native:<ms>"     -> blocks the thread <ms> natively (handler-timeout test)
 *   "noreply"         -> no reply (null), the connection stays open
 *   "closeafter:<t>"  -> reply <t>, then close the connection
 *   "close"           -> close the connection (no reply)
 *   "throw"           -> handler throws -> default error path (no reply)
 *   "throw-handled"   -> handler throws -> onError supplies a reply ("ERR:...")
 *   (anything else)   -> echoed back unchanged (binary-safe)
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/servers/socket/socket-server.php [--option=value ...]
 *
 * Launch options are named exactly like the SocketServer constructor parameters,
 * passed as --name=value (e.g. --address=0.0.0.0:9100 --maxConcurrency=4).
 */

// Build the server from argv: each --name=value maps to the matching SocketServer
// constructor parameter. Under WorkerMaster the injected --masterPid wires the
// orphan check (the worker self-terminates if its master dies). onError opts a
// specific failure into a custom reply; every other failure falls back to no reply.
$server = SocketServer::fromArgs(
    $_SERVER['argv'],
    onError: static function (Throwable $exception, Message $message): ?string {
        // Only "throw-handled" opts into a custom error reply; a plain "throw" gets
        // the default (no reply), so both paths are observable.
        return str_contains($exception->getMessage(), 'HANDLED')
            ? 'ERR:' . $exception->getMessage()
            : null;
    },
);

$server->serve(static function (Message $message): string|MessageResponse|null {
    $data = $message->data;

    return match (true) {
        $data === 'ping'                      => 'pong',
        $data === 'pid'                       => (string) getmypid(),
        $data === 'noreply'                   => null,
        $data === 'close'                     => new MessageResponse(close: true),
        $data === 'throw'                     => throw new RuntimeException('boom in handler'),
        $data === 'throw-handled'             => throw new RuntimeException('HANDLED boom'),
        str_starts_with($data, 'upper:')      => strtoupper(substr($data, strlen('upper:'))),
        str_starts_with($data, 'msleep:')     => msleepCommand($data),
        str_starts_with($data, 'native:')     => nativeCommand($data),
        str_starts_with($data, 'closeafter:') => new MessageResponse(
            data: substr($data, strlen('closeafter:')),
            close: true,
        ),
        default => $data,
    };
});

function msleepCommand(string $data): string
{
    $milliseconds = (int) substr($data, strlen('msleep:'));

    Sleeper::usleep(microseconds: $milliseconds * 1000);

    return 'slept';
}

// Native, BLOCKING sleep — unlike the async usleep above it does NOT yield to the
// scheduler, so it freezes the whole single-threaded server. Used to verify that the
// Go-side handlerTimeoutMs cuts the connection off even when the PHP handler is
// blocked natively (the timer fires independently of PHP).
function nativeCommand(string $data): string
{
    $milliseconds = (int) substr($data, strlen('native:'));

    usleep($milliseconds * 1000);

    return 'native-slept';
}
