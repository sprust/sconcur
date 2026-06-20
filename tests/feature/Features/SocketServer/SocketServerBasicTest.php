<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

class SocketServerBasicTest extends BaseSocketServerTestCase
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

    public function testBinarySafePayloadRoundTrips(): void
    {
        $payload = random_bytes(2048) . "\x00\x0a\xff" . random_bytes(2048);

        $this->assertSame($payload, $this->roundtrip($payload));
    }

    public function testEmptyFrameRoundTrips(): void
    {
        $this->assertSame('', $this->roundtrip(''));
    }

    public function testMultipleMessagesKeepOrderOnOneConnection(): void
    {
        $connection = $this->connect();

        $messages = ['upper:a', 'upper:b', 'upper:c', 'ping', 'echo-me'];
        $expected = ['A', 'B', 'C', 'pong', 'echo-me'];

        foreach ($messages as $message) {
            $this->sendFrame($connection, $message);
        }

        $received = [];

        foreach ($messages as $ignored) {
            $received[] = $this->receiveFrame($connection);
        }

        fclose($connection);

        $this->assertSame($expected, $received);
    }

    public function testNoReplyKeepsConnectionUsable(): void
    {
        $connection = $this->connect();

        // "noreply" yields no frame; the next message must still be answered, proving
        // the connection (and its per-message handler timer) stays healthy.
        $this->sendFrame($connection, 'noreply');
        $this->sendFrame($connection, 'ping');

        $this->assertSame('pong', $this->receiveFrame($connection));

        fclose($connection);
    }

    public function testCloseAfterRepliesThenClosesConnection(): void
    {
        $connection = $this->connect();

        $this->sendFrame($connection, 'closeafter:bye');

        $this->assertSame('bye', $this->receiveFrame($connection));
        // The server closed the connection after the reply: the next read hits EOF.
        $this->assertNull($this->receiveFrame($connection));

        fclose($connection);
    }

    public function testCloseCommandClosesWithoutReply(): void
    {
        $connection = $this->connect();

        $this->sendFrame($connection, 'close');

        $this->assertNull($this->receiveFrame($connection));

        fclose($connection);
    }

    public function testServerKeepsServingAcrossConnections(): void
    {
        $this->assertSame('pong', $this->roundtrip('ping'));
        $this->assertSame('one', $this->roundtrip('one'));
        $this->assertSame('pong', $this->roundtrip('ping'));
    }
}
