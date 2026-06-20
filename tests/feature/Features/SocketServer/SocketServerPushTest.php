<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

class SocketServerPushTest extends BaseSocketServerTestCase
{
    public function testHandlerPushesManyFramesForOneInboundFrame(): void
    {
        // The core push capability: one inbound frame triggers several server-pushed
        // frames, not a single response.
        $connection = $this->connect();

        $this->sendFrame($connection, 'push:3');

        self::assertSame('p0', $this->receiveFrame($connection));
        self::assertSame('p1', $this->receiveFrame($connection));
        self::assertSame('p2', $this->receiveFrame($connection));

        fclose($connection);
    }

    public function testPushThenContinueReadingOnSameConnection(): void
    {
        $connection = $this->connect();

        $this->sendFrame($connection, 'push:2');

        self::assertSame('p0', $this->receiveFrame($connection));
        self::assertSame('p1', $this->receiveFrame($connection));

        // The connection stays usable for further traffic after a push burst.
        $this->sendFrame($connection, 'ping');

        self::assertSame('pong', $this->receiveFrame($connection));

        fclose($connection);
    }
}
