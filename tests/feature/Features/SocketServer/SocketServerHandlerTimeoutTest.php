<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

class SocketServerHandlerTimeoutTest extends BaseSocketServerTestCase
{
    public function testHandlerWithinTimeoutSucceeds(): void
    {
        // 50ms of async work is well under the 200ms limit: the reply arrives.
        self::assertSame('slept', $this->roundtrip('msleep:50'));
    }

    public function testNativeBlockingHandlerIsCutOff(): void
    {
        // A handler that blocks the thread natively for 1.5s exceeds the 200ms limit.
        // The Go-side timer fires independently of the blocked PHP thread and cuts the
        // connection off, so the client sees EOF well before the native block ends.
        $start = microtime(true);

        $connection = $this->connect();

        $this->sendFrame($connection, 'native:1500');

        self::assertNull($this->receiveFrame($connection));

        $elapsed = microtime(true) - $start;

        self::assertLessThan(
            1.2,
            $elapsed,
            sprintf('Connection was cut off after %.3fs; the handler timeout did not fire independently.', $elapsed),
        );

        fclose($connection);
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        return ['handlerTimeoutMs' => 200];
    }
}
