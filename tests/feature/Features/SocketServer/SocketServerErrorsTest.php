<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

class SocketServerErrorsTest extends BaseSocketServerTestCase
{
    public function testThrowingHandlerClosesTheConnection(): void
    {
        // A handler that throws unwinds and its connection is closed; with no onError
        // reply the client just sees EOF.
        $connection = $this->connect();

        $this->sendFrame($connection, 'throw');

        self::assertNull($this->receiveFrame($connection));

        fclose($connection);
    }

    public function testOnErrorCanWriteAFinalFrame(): void
    {
        // The demo server's onError pushes a final frame for the "throw-handled"
        // failure before the connection is closed.
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
