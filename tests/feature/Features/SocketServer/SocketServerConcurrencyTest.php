<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

class SocketServerConcurrencyTest extends BaseSocketServerTestCase
{
    public function testConnectionsAreHandledConcurrently(): void
    {
        // Four connections each ask for a 300ms async sleep. Handled concurrently
        // (each in its own coroutine), the wall time stays close to 300ms — far less
        // than the ~1.2s a serial server would take.
        $start = microtime(true);

        $connections = [];

        foreach (range(0, 3) as $index) {
            $connections[$index] = $this->connect();

            $this->sendFrame($connections[$index], 'msleep:300');
        }

        foreach ($connections as $connection) {
            self::assertSame('slept', $this->receiveFrame($connection));

            fclose($connection);
        }

        $elapsed = microtime(true) - $start;

        self::assertLessThan(
            1.0,
            $elapsed,
            sprintf('Four concurrent 300ms handlers took %.3fs; they did not run concurrently.', $elapsed),
        );
    }
}
