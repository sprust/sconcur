<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\WsServer\TestWsServer;

/**
 * SO_REUSEPORT lets several processes bind one port and the kernel load-balances across
 * them — the basis for running one process per core under the master.
 */
class WsServerReusePortTest extends TestCase
{
    public function testTwoServersShareOnePortAndBothServe(): void
    {
        $first = TestWsServer::start(
            options: ['reusePort' => 1],
        );

        try {
            $second = TestWsServer::start(
                options: ['reusePort' => 1],
                port: $first->port(),
            );

            try {
                usleep(200_000);

                self::assertTrue($first->isRunning(), 'first server must stay up');
                self::assertTrue($second->isRunning(), 'second server must bind the shared port and stay up');

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
