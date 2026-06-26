<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsServer;

class WsServerMaxMessageBytesTest extends BaseWsServerTestCase
{
    public function testMessageWithinLimitWorks(): void
    {
        self::assertSame('0123456789', $this->roundtrip('0123456789'));
    }

    public function testOversizeMessageClosesConnection(): void
    {
        // A message past the 16-byte cap closes the connection (the library sends a 1009
        // close), so the next read returns null.
        $connection = $this->connect();

        $this->sendMessage($connection, str_repeat('x', 64));

        self::assertNull($this->receiveMessage($connection));

        fclose($connection);
    }

    public function testServerKeepsServingAfterRejectingAnOversizeMessage(): void
    {
        $this->testOversizeMessageClosesConnection();

        self::assertSame('pong', $this->roundtrip('ping'));
    }

    protected static function serverOptions(): array
    {
        return ['maxMessageBytes' => 16];
    }
}
