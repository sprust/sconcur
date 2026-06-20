<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

class SocketServerMaxConcurrencyTest extends BaseSocketServerTestCase
{
    public function testSecondConnectionWaitsForAFreeSlot(): void
    {
        // The single concurrency slot is taken by the first connection (it stays open
        // after its reply), so a second connection is accepted on the socket but not
        // handled until a slot frees.
        $first = $this->connect();

        $this->sendFrame($first, 'ping');

        self::assertSame('pong', $this->receiveFrame($first));

        $second = $this->connect();

        $this->sendFrame($second, 'ping');

        // No slot available yet: the second connection gets no reply within 300ms.
        stream_set_timeout($second, 0, 300_000);

        self::assertNull(
            $this->receiveFrame($second),
            'the second connection must wait for the concurrency slot',
        );

        // Free the slot: closing the first connection ends its coroutine.
        fclose($first);

        // Now the second connection is handled.
        stream_set_timeout($second, 5);

        self::assertSame('pong', $this->receiveFrame($second));

        fclose($second);
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        return ['maxConcurrency' => 1];
    }
}
