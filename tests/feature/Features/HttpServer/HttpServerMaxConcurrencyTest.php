<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerMaxConcurrencyTest extends BaseHttpServerTestCase
{
    public function testConcurrencyIsCappedSoExcessRequestsSerialize(): void
    {
        // Four 300ms requests against a 2-slot server run in two waves: the wall
        // time is about 600ms, clearly more than the ~300ms an unlimited server
        // would take, proving the cap holds.
        $start = microtime(true);

        $results = $this->concurrentGet(['/msleep/300', '/msleep/300', '/msleep/300', '/msleep/300']);

        $elapsed = microtime(true) - $start;

        foreach ($results as [$status, $body]) {
            self::assertSame(200, $status);
            self::assertSame('slept', $body);
        }

        self::assertGreaterThan(
            0.5,
            $elapsed,
            sprintf('Four 300ms requests with a 2-slot cap took %.3fs; the cap did not serialize them.', $elapsed),
        );

        self::assertLessThan(
            1.5,
            $elapsed,
            sprintf('Four 300ms requests took %.3fs; far longer than the expected ~0.6s.', $elapsed),
        );
    }

    public function testCapDoesNotBlockServingOverTime(): void
    {
        // After a batch drains, the server keeps accepting — a later request works.
        [$status, $body] = $this->request('GET', '/');

        self::assertSame(200, $status);
        self::assertSame('ok', $body);
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        return ['maxConcurrency' => 2];
    }
}
