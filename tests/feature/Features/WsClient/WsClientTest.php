<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsClient;

use SConcur\Exceptions\WsClient\WsClientConnectException;
use SConcur\Exceptions\WsClient\WsClientConnectionClosedException;
use SConcur\Features\WsClient\Dto\Connection;
use SConcur\Features\WsClient\WsClientOptions;

/**
 * Synchronous (outside a WaitGroup) edge cases for the ws client driving a real SConcur
 * WsServer — the end-to-end dogfood of both features: connect + metadata, echo / command
 * round-trips, text and binary messages, empty and large payloads, server push, the peer
 * closing the connection, and every error path — write after close, a refused dial, a
 * connect timeout, and an oversize inbound message. The concurrency / async path is
 * covered by WsClientConcurrencyTest.
 */
class WsClientTest extends BaseWsClientTestCase
{
    public function testConnectReturnsMetadata(): void
    {
        $connection = $this->client()->connect($this->url());

        self::assertInstanceOf(Connection::class, $connection);
        self::assertNotSame('', $connection->id);
        self::assertSame(self::server()->host() . ':' . self::server()->port(), $connection->remoteAddr);
        self::assertSame('', $connection->subprotocol);
        self::assertFalse($connection->isClosed());

        $connection->close();
    }

    public function testEchoRoundTrip(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->write('hello');

        self::assertSame('hello', $connection->read());
        self::assertFalse($connection->lastMessageWasBinary());

        $connection->close();
    }

    public function testPingPong(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->write('ping');

        self::assertSame('pong', $connection->read());

        $connection->close();
    }

    public function testUpperCommand(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->write('upper:abc');

        self::assertSame('ABC', $connection->read());

        $connection->close();
    }

    public function testMultipleMessagesKeepOrderOnOneConnection(): void
    {
        $connection = $this->client()->connect($this->url());

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

    public function testBinaryMessageRoundTripsWithType(): void
    {
        $connection = $this->client()->connect($this->url());

        $payload = random_bytes(2048) . "\x00\x0a\xff" . random_bytes(2048);

        $connection->write($payload, binary: true);

        self::assertSame($payload, $connection->read());
        self::assertTrue($connection->lastMessageWasBinary());

        $connection->close();
    }

    public function testBinCommandReturnsBinaryMessage(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->write('bin:hello');

        self::assertSame('hello', $connection->read());
        self::assertTrue($connection->lastMessageWasBinary());

        $connection->close();
    }

    public function testEmptyMessageRoundTrips(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->write('');

        // An empty message is a real message (not EOF), so read() returns '' not null.
        self::assertSame('', $connection->read());

        $connection->close();
    }

    public function testLargePayloadRoundTrips(): void
    {
        $connection = $this->client()->connect($this->url());

        $payload = random_bytes(256 * 1024);

        $connection->write($payload, binary: true);

        self::assertSame($payload, $connection->read());

        $connection->close();
    }

    public function testServerPushManyMessagesForOneRequest(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->write('push:3');

        self::assertSame('p0', $connection->read());
        self::assertSame('p1', $connection->read());
        self::assertSame('p2', $connection->read());

        $connection->close();
    }

    public function testServerStreamsMessagesWithAsyncWorkBetween(): void
    {
        $connection = $this->client()->connect($this->url());

        // The server pushes s0..s2, sleeping (async) between messages.
        $connection->write('stream:3');

        self::assertSame('s0', $connection->read());
        self::assertSame('s1', $connection->read());
        self::assertSame('s2', $connection->read());

        $connection->close();
    }

    public function testReadReturnsNullWhenServerClosesAfterReply(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->write('closeafter:bye');

        self::assertSame('bye', $connection->read());
        // The server closed its side after the reply: the next read hits the close.
        self::assertNull($connection->read());

        $connection->close();
    }

    public function testReadReturnsNullWhenServerClosesWithoutReply(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->write('close');

        self::assertNull($connection->read());

        $connection->close();
    }

    public function testReadKeepsReturningNullAfterInboundEnded(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->write('close');

        self::assertNull($connection->read());
        // Idempotent: once the inbound stream ended, every later read is null too.
        self::assertNull($connection->read());

        $connection->close();
    }

    public function testConnectToWrongPathThrows(): void
    {
        $this->expectException(WsClientConnectException::class);

        // The server serves only "/"; a valid upgrade to another path is answered 404,
        // which the dial surfaces as a connect failure.
        $this->client()->connect($this->url() . 'nope');
    }

    public function testWriteAfterCloseThrows(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->close();

        self::assertTrue($connection->isClosed());

        $this->expectException(WsClientConnectionClosedException::class);

        $connection->write('too late');
    }

    public function testCloseIsIdempotent(): void
    {
        $connection = $this->client()->connect($this->url());

        $connection->close();
        $connection->close();

        self::assertTrue($connection->isClosed());
    }

    public function testConnectToRefusedPortThrows(): void
    {
        $this->expectException(WsClientConnectException::class);

        // Port 1 is not listening: the dial is refused (network-class error).
        $this->client(
            new WsClientOptions(
                connectTimeoutMs: 1_000,
            ),
        )->connect('ws://127.0.0.1:1/');
    }

    public function testConnectRefusedCarriesNetworkMarker(): void
    {
        try {
            $this->client()->connect('ws://127.0.0.1:1/');

            self::fail('Expected a connect exception.');
        } catch (WsClientConnectException $exception) {
            // The Go side tags network failures with a "net:" marker, preserved through
            // the wrapping exception.
            self::assertStringContainsString('net:', $exception->getMessage());
        }
    }

    public function testConnectTimeoutThrows(): void
    {
        $this->expectException(WsClientConnectException::class);

        // A non-routable address with a tiny connect timeout: the dial cannot complete.
        $this->client(
            new WsClientOptions(
                connectTimeoutMs: 200,
            ),
        )->connect('ws://10.255.255.1:9200/');
    }

    public function testOversizeInboundMessageEndsInput(): void
    {
        // The client caps a single inbound message at 4 bytes; the server echoes a longer
        // message, so it exceeds the cap and the input stream ends (read() returns null).
        $connection = $this->client(
            new WsClientOptions(
                maxMessageBytes: 4,
            ),
        )->connect($this->url());

        $connection->write('echo8byte');

        self::assertNull($connection->read());

        $connection->close();
    }
}
