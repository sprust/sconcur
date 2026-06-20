<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

/**
 * The handler-timeout deadline lives on the Go side, so it fires independently of
 * PHP: a handler stuck in a NATIVE blocking call (here usleep, which never yields to
 * the scheduler) still gets the client a 504 at the deadline — it does not wait for
 * the blocking call to return. (The timeout cannot un-freeze the single PHP thread;
 * that is a process-level concern handled by the worker master.)
 *
 * Runs in its own class so the natively-frozen server cannot bleed into other tests:
 * the per-class server is SIGKILLed in teardown regardless of the stuck handler.
 */
class HttpServerNativeBlockTimeoutTest extends BaseHttpServerTestCase
{
    public function testNativeBlockingHandlerIsAnsweredWith504WithoutWaitingForIt(): void
    {
        // The handler blocks natively for 3s; the 250ms deadline must answer 504 long
        // before that (proving the Go timer fires without PHP yielding).
        $start = microtime(true);

        [$status] = $this->request(
            method: 'GET',
            path: '/native-msleep/3000',
        );

        $elapsed = microtime(true) - $start;

        self::assertSame(504, $status, 'a natively-blocked handler must still get the client a 504');
        self::assertLessThan(
            1.5,
            $elapsed,
            sprintf('504 took %.3fs; the Go-side deadline did not fire independently of the blocked PHP thread.', $elapsed),
        );
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        return ['handlerTimeoutMs' => 250];
    }
}
