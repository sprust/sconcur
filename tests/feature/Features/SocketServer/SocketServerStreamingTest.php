<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

class SocketServerStreamingTest extends BaseSocketServerTestCase
{
    public function testResponseIsStreamedIncrementallyWithAsyncWorkBetweenChunks(): void
    {
        // The handler streams a response by calling write() repeatedly, doing async
        // work between chunks. Each frame is flushed as it is produced, so the whole
        // stream is spread over time rather than arriving at once.
        $connection = $this->connect();

        $this->sendFrame($connection, 'stream:3');

        $start = microtime(true);

        $frames = [
            $this->receiveFrame($connection),
            $this->receiveFrame($connection),
            $this->receiveFrame($connection),
        ];

        $elapsed = microtime(true) - $start;

        fclose($connection);

        self::assertSame(['s0', 's1', 's2'], $frames);
        self::assertGreaterThan(
            0.1,
            $elapsed,
            sprintf('Three chunks 60ms apart arrived in %.3fs; they were not streamed over time.', $elapsed),
        );
    }

    public function testOtherConnectionsAreServedWhileOneStreams(): void
    {
        // While one connection streams (~240ms of async work), a second connection
        // must still be served promptly — the streaming handler yields between chunks.
        $streaming = $this->connect();

        $this->sendFrame($streaming, 'stream:5');

        $start = microtime(true);

        $pong = $this->roundtrip('ping');

        $pingElapsed = microtime(true) - $start;

        self::assertSame('pong', $pong);
        self::assertLessThan(
            0.2,
            $pingElapsed,
            sprintf('A second connection waited %.3fs while the first streamed; it was not served concurrently.', $pingElapsed),
        );

        // Drain the stream so the connection closes cleanly.
        for ($index = 0; $index < 5; $index++) {
            $this->receiveFrame($streaming);
        }

        fclose($streaming);
    }
}
