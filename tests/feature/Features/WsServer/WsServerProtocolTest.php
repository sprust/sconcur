<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsServer;

/**
 * Protocol-level tests driving the server with the raw WebSocket handshake and frame
 * codec (TestWsServer), independent of WsClient. The richer behavioural suites dogfood
 * WsClient; this one keeps the wire protocol honest.
 */
class WsServerProtocolTest extends BaseWsServerTestCase
{
    public function testEchoRoundTrip(): void
    {
        $this->assertSame('hello', $this->roundtrip('hello'));
    }

    public function testPingPong(): void
    {
        $this->assertSame('pong', $this->roundtrip('ping'));
    }

    public function testUpperCommand(): void
    {
        $this->assertSame('ABC', $this->roundtrip('upper:abc'));
    }

    public function testEmptyMessageRoundTrips(): void
    {
        $this->assertSame('', $this->roundtrip(''));
    }

    public function testBinaryMessageRoundTripsWithType(): void
    {
        $payload = random_bytes(1024) . "\x00\x0a\xff" . random_bytes(1024);

        $connection = $this->connect();

        $this->sendMessage($connection, $payload, binary: true);

        $message = $this->receiveMessage($connection);

        fclose($connection);

        $this->assertNotNull($message);
        $this->assertTrue($message['binary'], 'a binary message must echo back as binary');
        $this->assertSame($payload, $message['data']);
    }

    public function testBinCommandReturnsBinaryMessage(): void
    {
        $connection = $this->connect();

        $this->sendMessage($connection, 'bin:payload');

        $message = $this->receiveMessage($connection);

        fclose($connection);

        $this->assertNotNull($message);
        $this->assertTrue($message['binary']);
        $this->assertSame('payload', $message['data']);
    }

    public function testMultipleMessagesKeepOrderOnOneConnection(): void
    {
        $connection = $this->connect();

        $messages = ['upper:a', 'upper:b', 'upper:c', 'ping', 'echo-me'];
        $expected = ['A', 'B', 'C', 'pong', 'echo-me'];

        foreach ($messages as $message) {
            $this->sendMessage($connection, $message);
        }

        $received = [];

        foreach ($messages as $ignored) {
            $received[] = $this->receiveMessage($connection)['data'] ?? null;
        }

        fclose($connection);

        $this->assertSame($expected, $received);
    }

    public function testServerPushManyMessagesForOneInbound(): void
    {
        $connection = $this->connect();

        $this->sendMessage($connection, 'push:3');

        $received = [];

        for ($index = 0; $index < 3; $index++) {
            $received[] = $this->receiveMessage($connection)['data'] ?? null;
        }

        fclose($connection);

        $this->assertSame(['p0', 'p1', 'p2'], $received);
    }

    public function testNoReplyKeepsConnectionUsable(): void
    {
        $connection = $this->connect();

        $this->sendMessage($connection, 'noreply');
        $this->sendMessage($connection, 'ping');

        $this->assertSame('pong', $this->receiveMessage($connection)['data'] ?? null);

        fclose($connection);
    }

    public function testCloseAfterRepliesThenClosesConnection(): void
    {
        $connection = $this->connect();

        $this->sendMessage($connection, 'closeafter:bye');

        $this->assertSame('bye', $this->receiveMessage($connection)['data'] ?? null);
        // The server closed the connection after the reply: the next read hits the close.
        $this->assertNull($this->receiveMessage($connection));

        fclose($connection);
    }

    public function testCloseCommandClosesWithoutReply(): void
    {
        $connection = $this->connect();

        $this->sendMessage($connection, 'close');

        $this->assertNull($this->receiveMessage($connection));

        fclose($connection);
    }

    public function testNonWebSocketRequestIsRejected(): void
    {
        $connection = @fsockopen(self::server()->host(), self::server()->port(), $errno, $errstr, 5.0);

        $this->assertIsResource($connection);

        fwrite(
            $connection,
            "GET / HTTP/1.1\r\nHost: " . self::server()->host() . "\r\nConnection: close\r\n\r\n",
        );

        $statusLine = (string) fgets($connection);

        fclose($connection);

        $this->assertStringContainsString('426', $statusLine);
    }

    public function testUpgradeOnWrongPathIsRejected(): void
    {
        // A valid WebSocket upgrade, but to a path the server does not serve (default
        // path is "/"): the request is answered 404 before the handshake, and the server
        // keeps serving the right path.
        $connection = @fsockopen(self::server()->host(), self::server()->port(), $errno, $errstr, 5.0);

        $this->assertIsResource($connection);

        fwrite(
            $connection,
            "GET /nope HTTP/1.1\r\n"
            . 'Host: ' . self::server()->host() . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Connection: close\r\n\r\n",
        );

        $statusLine = (string) fgets($connection);

        fclose($connection);

        $this->assertStringContainsString('404', $statusLine);
        $this->assertSame('pong', $this->roundtrip('ping'));
    }

    public function testServerKeepsServingAcrossConnections(): void
    {
        $this->assertSame('pong', $this->roundtrip('ping'));
        $this->assertSame('one', $this->roundtrip('one'));
        $this->assertSame('pong', $this->roundtrip('ping'));
    }
}
