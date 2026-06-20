<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

class SocketServerErrorsTest extends BaseSocketServerTestCase
{
    public function testThrowingHandlerSendsNoReplyAndKeepsConnectionUsable(): void
    {
        $connection = $this->connect();

        // A handler that throws takes the default error path: no reply is sent, but
        // the per-message timer is disarmed and the connection stays healthy, so the
        // next message is still answered.
        $this->sendFrame($connection, 'throw');
        $this->sendFrame($connection, 'ping');

        self::assertSame('pong', $this->receiveFrame($connection));

        fclose($connection);
    }

    public function testOnErrorCanSupplyAReply(): void
    {
        // The demo server's onError opts the "throw-handled" failure into a custom
        // reply instead of staying silent.
        self::assertSame('ERR:HANDLED boom', $this->roundtrip('throw-handled'));
    }

    public function testServerKeepsServingAfterAnError(): void
    {
        $connection = $this->connect();

        $this->sendFrame($connection, 'throw');

        fclose($connection);

        self::assertSame('pong', $this->roundtrip('ping'));
    }

    public function testTruncatedFrameDoesNotBreakTheServer(): void
    {
        // Send half of a 4-byte length prefix, then disconnect: the server treats the
        // mid-frame end as a clean close, cleans up, and keeps serving others.
        $connection = $this->connect();

        fwrite($connection, "\x00\x00");

        fclose($connection);

        self::assertSame('pong', $this->roundtrip('ping'));
    }
}
