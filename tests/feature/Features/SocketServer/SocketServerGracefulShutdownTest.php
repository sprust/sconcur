<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\SocketServer\TestSocketServer;

/**
 * Proves graceful shutdown end-to-end: a SIGTERM arriving while a message is in
 * flight must let that message's reply finish, and the process must then exit
 * cleanly on its own. Manages its own server (start → signal → observe exit).
 */
class SocketServerGracefulShutdownTest extends TestCase
{
    public function testInFlightMessageDrainsOnSigtermAndProcessExits(): void
    {
        $server = TestSocketServer::start();

        try {
            $connection = $server->connect();

            // Start a slow (500ms async) message and let it actually reach the handler,
            // so it is genuinely in flight when the signal lands.
            TestSocketServer::sendFrame($connection, 'msleep:500');

            usleep(100_000);

            // Mid-flight: ask the server to stop.
            $server->signal(SIGTERM);

            // The in-flight message's reply must still arrive — it is not dropped.
            self::assertSame('slept', TestSocketServer::receiveFrame($connection));

            // After draining, the connection is closed and the process exits cleanly.
            self::assertNull(TestSocketServer::receiveFrame($connection));
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
