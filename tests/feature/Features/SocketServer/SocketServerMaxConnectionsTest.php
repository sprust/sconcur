<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SConcur\Tests\Impl\SocketServer\TestSocketServer;

/**
 * Proves the bounded-lifetime feature (maxConnections): after handling the
 * configured number of connections the server shuts itself down gracefully and the
 * process exits cleanly, so a supervisor can respawn a fresh one. Manages its own
 * server (start → drive → observe exit).
 */
class SocketServerMaxConnectionsTest extends TestCase
{
    public function testServerExitsAfterReachingMaxConnections(): void
    {
        // waitReachable=false: the readiness probe opens a connection, which would
        // itself count against maxConnections — connect with a retry loop instead.
        $server = TestSocketServer::start(
            options: ['maxConnections' => 2],
            waitReachable: false,
        );

        try {
            // First connection is below the limit: the server keeps serving.
            self::assertSame('pong', $this->roundtripWithRetry($server, 'ping'));
            self::assertTrue($server->isRunning(), 'server must keep running before the limit is reached');

            // Second connection reaches the limit: it is served, then the server
            // drains and exits on its own (it is not killed).
            self::assertSame('pong', $this->roundtripWithRetry($server, 'ping'));

            self::assertSame(
                0,
                $server->waitForExit(3.0),
                'server should exit cleanly after reaching maxConnections',
            );
            self::assertFalse($server->isRunning());
        } finally {
            $server->stop();
        }
    }

    private function roundtripWithRetry(TestSocketServer $server, string $data): ?string
    {
        $deadline = microtime(true) + 5.0;

        while (true) {
            try {
                return $server->roundtrip($data);
            } catch (RuntimeException $exception) {
                if (microtime(true) > $deadline) {
                    throw $exception;
                }

                usleep(50_000);
            }
        }
    }
}
