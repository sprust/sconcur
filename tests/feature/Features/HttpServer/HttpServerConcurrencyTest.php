<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerConcurrencyTest extends BaseHttpServerTestCase
{
    public function testConcurrentSleepRequestsRunInParallel(): void
    {
        // Each /msleep/500 handler sleeps 500ms in its own coroutine. Five
        // concurrent requests must overlap, so the total wall time stays well under
        // their sum (2.5s) — under 1s.
        $start = microtime(true);

        $results = $this->concurrentGet(['/msleep/500', '/msleep/500', '/msleep/500', '/msleep/500', '/msleep/500']);

        $elapsed = microtime(true) - $start;

        foreach ($results as [$status, $body]) {
            self::assertSame(200, $status);
            self::assertSame('slept', $body);
        }

        self::assertLessThan(
            1.0,
            $elapsed,
            sprintf('Three concurrent 500ms requests took %.3fs; they did not run in parallel.', $elapsed),
        );

        // Sanity: they really did sleep (not an instant stub).
        self::assertGreaterThan(0.4, $elapsed);
    }
}
