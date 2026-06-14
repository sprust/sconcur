<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\HttpServer\TestHttpServer;

/**
 * Proves graceful shutdown end-to-end: a SIGTERM arriving while a request is in
 * flight must let that request finish, and the process must then exit cleanly on
 * its own. Manages its own server (start → signal → observe exit), so it does not
 * use the shared per-class server.
 */
class HttpServerGracefulShutdownTest extends TestCase
{
    public function testInFlightRequestDrainsOnSigtermAndProcessExits(): void
    {
        $server = TestHttpServer::start();

        try {
            // Start a slow request and let it actually reach the handler (it sleeps
            // 500ms), so it is genuinely in flight when the signal lands.
            $multi = curl_multi_init();
            $curl  = curl_init($server->baseUrl() . '/msleep/500');

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);

            curl_multi_add_handle($multi, $curl);

            $running   = null;
            $pumpUntil = microtime(true) + 0.2;

            do {
                curl_multi_exec($multi, $running);
                usleep(10_000);
            } while (microtime(true) < $pumpUntil && $running > 0);

            // Request is mid-flight: ask the server to stop.
            $server->signal(SIGTERM);

            // Finish the in-flight request — it must complete, not be dropped.
            do {
                curl_multi_exec($multi, $running);

                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0);

            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $body   = (string) curl_multi_getcontent($curl);

            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
            curl_multi_close($multi);

            self::assertSame(200, $status, 'the in-flight request must be drained, not dropped');
            self::assertSame('slept', $body);

            // After draining, the server must shut itself down (it is not killed).
            $exitCode = $server->waitForExit(3.0);

            self::assertNotNull($exitCode, 'server did not exit after draining the in-flight request');
            self::assertSame(0, $exitCode, 'server should exit cleanly after a graceful shutdown');
        } finally {
            $server->stop();
        }
    }

    public function testListenerStopsAcceptingAtDrainStart(): void
    {
        $server = TestHttpServer::start();

        try {
            // Hold a slow request in flight so the server is mid-drain after SIGTERM.
            $multi = curl_multi_init();
            $slow  = curl_init($server->baseUrl() . '/msleep/800');

            curl_setopt_array($slow, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);

            curl_multi_add_handle($multi, $slow);

            $running   = null;
            $pumpUntil = microtime(true) + 0.2;

            do {
                curl_multi_exec($multi, $running);
                usleep(10_000);
            } while (microtime(true) < $pumpUntil && $running > 0);

            $server->signal(SIGTERM);

            // While the slow request is still draining, the listener must already be
            // closed: a NEW connection is refused (curl code 0), not accepted and
            // answered 503. (Before closing the listener up front, it would 503.)
            $refused  = false;
            $deadline = microtime(true) + 1.5;

            while (microtime(true) < $deadline) {
                if ($this->connectStatus($server->baseUrl() . '/') === 0) {
                    $refused = true;

                    break;
                }

                usleep(50_000);
            }

            self::assertTrue(
                $refused,
                'the listener must stop accepting at drain start, so new connections are refused (not 503)',
            );

            // The in-flight request still completes.
            do {
                curl_multi_exec($multi, $running);

                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0);

            $status = (int) curl_getinfo($slow, CURLINFO_HTTP_CODE);

            curl_multi_remove_handle($multi, $slow);
            curl_close($slow);
            curl_multi_close($multi);

            self::assertSame(200, $status, 'the in-flight request must still drain to completion');
            self::assertSame(0, $server->waitForExit(3.0), 'server should exit cleanly after draining');
        } finally {
            $server->stop();
        }
    }

    /**
     * Returns the HTTP status of a quick request, or 0 if the connection could not
     * be made (e.g. the listener is closed).
     */
    private function connectStatus(string $url): int
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
        ]);

        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return $status;
    }
}
