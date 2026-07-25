<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsServer;

class WsServerConcurrencyTest extends BaseWsServerTestCase
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

            $this->sendMessage($connections[$index], 'msleep:300');
        }

        foreach ($connections as $connection) {
            $message = $this->receiveMessage($connection);

            self::assertNotNull($message);
            self::assertSame('slept', $message['data']);

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
