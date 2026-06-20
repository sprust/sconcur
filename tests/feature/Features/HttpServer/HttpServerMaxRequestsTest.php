<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\HttpServer\TestHttpServer;

/**
 * Proves the bounded-lifetime feature (maxRequests): after handling the configured
 * number of requests the server shuts itself down gracefully — the limiting request
 * still drains to completion, the listener is closed before draining (so no accepted
 * request is bounced), and the process exits cleanly so a supervisor can respawn it.
 * Each test manages its own server (start → drive → observe exit).
 */
class HttpServerMaxRequestsTest extends TestCase
{
    public function testServerExitsAfterReachingMaxRequests(): void
    {
        $server = TestHttpServer::start(['maxRequests' => 2]);

        try {
            // The first request is below the limit: the server keeps serving.
            [$firstStatus, $firstBody] = $this->get($server->baseUrl() . '/');

            self::assertSame(200, $firstStatus);
            self::assertSame('ok', $firstBody);
            self::assertTrue($server->isRunning(), 'server must keep running before the limit is reached');

            // The second request reaches the limit: it is served, then the server
            // drains and exits on its own (it is not killed).
            [$secondStatus, $secondBody] = $this->get($server->baseUrl() . '/');

            self::assertSame(200, $secondStatus);
            self::assertSame('ok', $secondBody);

            $exitCode = $server->waitForExit(3.0);

            self::assertNotNull($exitCode, 'server did not exit after reaching maxRequests');
            self::assertSame(0, $exitCode, 'server should exit cleanly after reaching maxRequests');

            // The port is closed now: a new connection is refused.
            self::assertSame(0, $this->connectStatus($server->baseUrl() . '/'));
        } finally {
            $server->stop();
        }
    }

    public function testLimitingRequestDrainsAndListenerClosesFirst(): void
    {
        // maxRequests = 1: the very first request reaches the limit. It must still
        // drain to completion while the listener is already closed.
        $server = TestHttpServer::start(['maxRequests' => 1]);

        try {
            // Start a slow request and let it actually reach the handler (it sleeps
            // 500ms), so the server is mid-drain (listener closed) while it runs.
            $multi = curl_multi_init();
            $slow  = curl_init($server->baseUrl() . '/msleep/500');

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

            // The limit is already reached (one request dispatched), so the listener
            // must be closed up front: a NEW connection is refused (curl code 0), not
            // accepted and answered 503.
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
                'the listener must close before draining, so new connections are refused (not 503)',
            );

            // The limiting request still completes — it is not dropped.
            do {
                curl_multi_exec($multi, $running);

                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0);

            $status = (int) curl_getinfo($slow, CURLINFO_HTTP_CODE);
            $body   = (string) curl_multi_getcontent($slow);

            curl_multi_remove_handle($multi, $slow);
            curl_close($slow);
            curl_multi_close($multi);

            self::assertSame(200, $status, 'the limiting request must drain to completion');
            self::assertSame('slept', $body);
            self::assertSame(0, $server->waitForExit(3.0), 'server should exit cleanly after draining');
        } finally {
            $server->stop();
        }
    }

    /**
     * Performs a blocking GET and returns [status, body].
     *
     * @return array{int, string}
     */
    private function get(string $url): array
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);

        $body   = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return [$status, is_string($body) ? $body : ''];
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
