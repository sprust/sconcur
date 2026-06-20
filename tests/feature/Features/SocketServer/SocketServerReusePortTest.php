<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\SocketServer\TestSocketServer;

/**
 * Proves SO_REUSEPORT end-to-end: two independent server processes bind the very
 * same port and both serve traffic — the basis for process-per-core scaling.
 */
class SocketServerReusePortTest extends TestCase
{
    public function testTwoServersShareOnePortAndBothServe(): void
    {
        $first = TestSocketServer::start(
            options: ['reusePort' => 1],
        );

        try {
            // The second process binds the SAME port — only possible with
            // SO_REUSEPORT; without it this would fail with EADDRINUSE.
            $second = TestSocketServer::start(
                options: ['reusePort' => 1],
                port: $first->port(),
            );

            try {
                // Give a would-be EADDRINUSE crash time to surface, then confirm both
                // processes are genuinely up on the shared port.
                usleep(200_000);

                self::assertTrue($first->isRunning(), 'first server must stay up');
                self::assertTrue($second->isRunning(), 'second server must bind the shared port and stay up');

                // The shared port serves traffic (the kernel routes each connection to
                // one of the two processes).
                for ($index = 0; $index < 10; $index++) {
                    self::assertSame('pong', $first->roundtrip('ping'));
                }
            } finally {
                $second->stop();
            }
        } finally {
            $first->stop();
        }
    }
}
