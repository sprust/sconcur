<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

class SocketServerMaxMessageBytesTest extends BaseSocketServerTestCase
{
    public function testFrameWithinLimitWorks(): void
    {
        self::assertSame('0123456789', $this->roundtrip('0123456789'));
    }

    public function testOversizeFrameClosesConnection(): void
    {
        $connection = $this->connect();

        // A frame whose length prefix exceeds maxMessageBytes is rejected on the Go
        // side without buffering the payload, and the connection is closed.
        $this->sendFrame($connection, str_repeat('x', 64));

        self::assertNull($this->receiveFrame($connection));

        fclose($connection);
    }

    public function testServerKeepsServingAfterRejectingAnOversizeFrame(): void
    {
        // A rejected oversize frame closes only that connection; the server keeps
        // serving new connections.
        $this->testOversizeFrameClosesConnection();

        self::assertSame('pong', $this->roundtrip('ping'));
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        return ['maxMessageBytes' => 16];
    }
}
