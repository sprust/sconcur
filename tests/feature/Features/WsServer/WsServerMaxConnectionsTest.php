<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsServer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SConcur\Tests\Impl\WsServer\TestWsServer;

/**
 * maxConnections caps the number of connections a server handles before it shuts itself
 * down gracefully (so a master respawns a fresh worker against handler memory leaks).
 */
class WsServerMaxConnectionsTest extends TestCase
{
    public function testServerExitsAfterReachingMaxConnections(): void
    {
        $server = TestWsServer::start(
            options: ['maxConnections' => 2],
            waitReachable: false,
        );

        try {
            self::assertSame('pong', $this->roundtripWithRetry($server, 'ping'));
            self::assertTrue($server->isRunning(), 'server must keep running before the limit is reached');

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

    private function roundtripWithRetry(TestWsServer $server, string $data): ?string
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
