<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketClient;

use SConcur\Exceptions\SocketClient\SocketClientConnectException;
use SConcur\Exceptions\SocketClient\SocketClientConnectionClosedException;
use SConcur\Features\SocketClient\Dto\Connection;
use SConcur\Features\SocketClient\SocketClientOptions;

/**
 * Synchronous (outside a WaitGroup) edge cases for the socket client: connect +
 * metadata, echo / command round-trips, binary and empty frames, server push, the
 * peer closing the connection, and every error path — write after close, connecting
 * to a refused port, a connect timeout, and an oversize inbound frame. The
 * concurrency / async path is covered by SocketClientConcurrencyTest.
 */
class SocketClientTest extends BaseSocketClientTestCase
{
    public function testConnectReturnsMetadata(): void
    {
        $connection = $this->client()->connect($this->address());

        self::assertInstanceOf(Connection::class, $connection);
        self::assertNotSame('', $connection->id);
        self::assertSame($this->address(), $connection->remoteAddr);
        self::assertNotSame('', $connection->localAddr);
        self::assertFalse($connection->isClosed());

        $connection->close();
    }

    public function testEchoRoundTrip(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->write('hello');

        self::assertSame('hello', $connection->read());

        $connection->close();
    }

    public function testPingPong(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->write('ping');

        self::assertSame('pong', $connection->read());

        $connection->close();
    }

    public function testUpperCommand(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->write('upper:abc');

        self::assertSame('ABC', $connection->read());

        $connection->close();
    }

    public function testMultipleFramesKeepOrderOnOneConnection(): void
    {
        $connection = $this->client()->connect($this->address());

        $messages = ['upper:a', 'upper:b', 'ping', 'echo-me'];
        $expected = ['A', 'B', 'pong', 'echo-me'];

        $received = [];

        foreach ($messages as $message) {
            $connection->write($message);

            $received[] = $connection->read();
        }

        $connection->close();

        self::assertSame($expected, $received);
    }

    public function testBinarySafePayloadRoundTrips(): void
    {
        $connection = $this->client()->connect($this->address());

        $payload = random_bytes(2048) . "\x00\x0a\xff" . random_bytes(2048);

        $connection->write($payload);

        self::assertSame($payload, $connection->read());

        $connection->close();
    }

    public function testEmptyFrameRoundTrips(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->write('');

        // An empty frame is a real frame (not EOF), so read() returns '' not null.
        self::assertSame('', $connection->read());

        $connection->close();
    }

    public function testLargePayloadRoundTrips(): void
    {
        $connection = $this->client()->connect($this->address());

        // Far larger than the Go-side 64 KiB read buffer, so the inbound frame is
        // reassembled across several bufio refills.
        $payload = random_bytes(256 * 1024);

        $connection->write($payload);

        self::assertSame($payload, $connection->read());

        $connection->close();
    }

    public function testServerPushManyFramesForOneRequest(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->write('push:3');

        self::assertSame('p0', $connection->read());
        self::assertSame('p1', $connection->read());
        self::assertSame('p2', $connection->read());

        $connection->close();
    }

    public function testServerStreamsFramesWithAsyncWorkBetween(): void
    {
        $connection = $this->client()->connect($this->address());

        // The server pushes s0..s2, sleeping (async) between frames.
        $connection->write('stream:3');

        self::assertSame('s0', $connection->read());
        self::assertSame('s1', $connection->read());
        self::assertSame('s2', $connection->read());

        $connection->close();
    }

    public function testReadReturnsNullWhenServerClosesAfterReply(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->write('closeafter:bye');

        self::assertSame('bye', $connection->read());
        // The server closed its side after the reply: the next read hits EOF.
        self::assertNull($connection->read());

        $connection->close();
    }

    public function testReadReturnsNullWhenServerClosesWithoutReply(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->write('close');

        self::assertNull($connection->read());

        $connection->close();
    }

    public function testReadKeepsReturningNullAfterInboundEnded(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->write('close');

        self::assertNull($connection->read());
        // Idempotent: once the inbound stream ended, every later read is null too.
        self::assertNull($connection->read());

        $connection->close();
    }

    public function testWriteAfterCloseThrows(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->close();

        self::assertTrue($connection->isClosed());

        $this->expectException(SocketClientConnectionClosedException::class);

        $connection->write('too late');
    }

    public function testCloseIsIdempotent(): void
    {
        $connection = $this->client()->connect($this->address());

        $connection->close();
        $connection->close();

        self::assertTrue($connection->isClosed());
    }

    public function testConnectToRefusedPortThrows(): void
    {
        $this->expectException(SocketClientConnectException::class);

        // Port 1 is not listening: the connection is refused (network-class error).
        $this->client(
            new SocketClientOptions(
                connectTimeoutMs: 1_000,
            ),
        )->connect('127.0.0.1:1');
    }

    public function testConnectRefusedCarriesNetworkMarker(): void
    {
        try {
            $this->client()->connect('127.0.0.1:1');

            self::fail('Expected a connect exception.');
        } catch (SocketClientConnectException $exception) {
            // The Go side tags network failures with a "net:" marker, preserved through
            // the wrapping exception.
            self::assertStringContainsString('net:', $exception->getMessage());
        }
    }

    public function testConnectTimeoutThrows(): void
    {
        $this->expectException(SocketClientConnectException::class);

        // A non-routable address with a tiny connect timeout: the dial cannot complete
        // (it times out or is reported unreachable) — either way a connect failure.
        $this->client(
            new SocketClientOptions(
                connectTimeoutMs: 200,
            ),
        )->connect('10.255.255.1:9100');
    }

    public function testOversizeInboundFrameEndsInput(): void
    {
        // The client caps a single inbound frame at 4 bytes; the server echoes a longer
        // frame, so it exceeds the cap and the input stream ends (read() returns null).
        $connection = $this->client(
            new SocketClientOptions(
                maxMessageBytes: 4,
            ),
        )->connect($this->address());

        $connection->write('echo8byte');

        self::assertNull($connection->read());

        $connection->close();
    }
}
