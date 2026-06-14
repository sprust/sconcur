<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerHandlerTimeoutTest extends BaseHttpServerTestCase
{
    public function testSlowHandlerIsAnsweredWith504WithoutWaitingForIt(): void
    {
        // The handler would sleep 10s; the 200ms deadline must answer 504 long
        // before that, freeing the connection.
        $start = microtime(true);

        [$status] = $this->request('GET', '/msleep/10000');

        $elapsed = microtime(true) - $start;

        self::assertSame(504, $status);
        self::assertLessThan(2.0, $elapsed, sprintf('504 took %.3fs; the deadline did not fire promptly.', $elapsed));
    }

    public function testFastHandlerIsNotAffected(): void
    {
        [$status, $body] = $this->request('GET', '/msleep/50');

        self::assertSame(200, $status);
        self::assertSame('slept', $body);
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        return ['handlerTimeoutMs' => 200];
    }
}
