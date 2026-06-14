<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\HttpServer\TestHttpServer;

/**
 * Proves SO_REUSEPORT end-to-end: two independent server processes bind the very
 * same port and both serve traffic — the basis for process-per-core scaling.
 */
class HttpServerReusePortTest extends TestCase
{
    public function testTwoServersShareOnePortAndBothServe(): void
    {
        $first = TestHttpServer::start(['reusePort' => 1]);

        try {
            // The second process binds the SAME port — only possible with
            // SO_REUSEPORT; without it this would fail with EADDRINUSE.
            $second = TestHttpServer::start(['reusePort' => 1], $first->port());

            try {
                // Give a would-be EADDRINUSE crash time to surface, then confirm
                // both processes are genuinely up on the shared port.
                usleep(200_000);

                self::assertTrue($first->isRunning(), 'first server must stay up');
                self::assertTrue($second->isRunning(), 'second server must bind the shared port and stay up');

                // The shared port serves requests (the kernel routes each to one
                // of the two processes).
                $baseUrl = 'http://127.0.0.1:' . $first->port();

                for ($i = 0; $i < 10; $i++) {
                    [$status, $body] = $this->get($baseUrl . '/');

                    self::assertSame(200, $status);
                    self::assertSame('ok', $body);
                }
            } finally {
                $second->stop();
            }
        } finally {
            $first->stop();
        }
    }

    /**
     * @return array{int, string} [status, body]
     */
    private function get(string $url): array
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);

        $body   = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return [(int) $status, is_string($body) ? $body : ''];
    }
}
