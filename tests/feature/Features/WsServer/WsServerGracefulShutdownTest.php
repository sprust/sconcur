<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\WsServer\TestWsServer;

/**
 * Graceful shutdown: an in-flight handler must finish (drain) when SIGTERM arrives, and
 * the process must then exit cleanly (exit 0). Manages its own server.
 */
class WsServerGracefulShutdownTest extends TestCase
{
    public function testInFlightMessageDrainsOnSigtermAndProcessExits(): void
    {
        $server = TestWsServer::start();

        try {
            $connection = $server->connect();

            // Start a slow (async) handler, then signal the server mid-flight.
            TestWsServer::sendMessage($connection, 'msleep:500');

            usleep(100_000);

            $server->signal(SIGTERM);

            // The in-flight handler must still complete and deliver its reply, then the
            // connection ends.
            self::assertSame('slept', TestWsServer::receiveMessage($connection)['data'] ?? null);
            self::assertNull(TestWsServer::receiveMessage($connection));

            self::assertSame(
                0,
                $server->waitForExit(3.0),
                'server should exit cleanly after draining in-flight work',
            );

            fclose($connection);
        } finally {
            $server->stop();
        }
    }
}
