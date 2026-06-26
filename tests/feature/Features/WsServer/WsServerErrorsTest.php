<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsServer;

class WsServerErrorsTest extends BaseWsServerTestCase
{
    public function testThrowingHandlerClosesTheConnection(): void
    {
        // A handler that throws unwinds and its connection is closed; with no onError
        // reply the client just sees the close.
        $connection = $this->connect();

        $this->sendMessage($connection, 'throw');

        self::assertNull($this->receiveMessage($connection));

        fclose($connection);
    }

    public function testOnErrorCanWriteAFinalMessage(): void
    {
        // The demo server's onError pushes a final message for the "throw-handled"
        // failure before the connection is closed.
        self::assertSame('ERR:HANDLED boom', $this->roundtrip('throw-handled'));
    }

    public function testServerKeepsServingAfterAnError(): void
    {
        $connection = $this->connect();

        $this->sendMessage($connection, 'throw');

        fclose($connection);

        self::assertSame('pong', $this->roundtrip('ping'));
    }

    public function testServerSurvivesClientDisconnectMidPush(): void
    {
        // The client asks for a long server push, reads one message, then drops the
        // connection: the handler's next write fails, the connection coroutine unwinds
        // (WsServerConnectionClosedException), and the server keeps serving others.
        $connection = $this->connect();

        $this->sendMessage($connection, 'push:1000');

        self::assertSame('p0', $this->receiveMessage($connection)['data'] ?? null);

        fclose($connection);

        self::assertSame('pong', $this->roundtrip('ping'));
    }

    public function testAbruptDisconnectDuringHandshakeDoesNotBreakTheServer(): void
    {
        // Open a raw TCP connection and drop it without completing the upgrade: the
        // server must shrug it off and keep serving.
        $raw = @fsockopen(self::server()->host(), self::server()->port(), $errno, $errstr, 5.0);

        self::assertIsResource($raw);

        fwrite($raw, "GET / HTTP/1.1\r\nHost: x\r\n");
        fclose($raw);

        self::assertSame('pong', $this->roundtrip('ping'));
    }
}
