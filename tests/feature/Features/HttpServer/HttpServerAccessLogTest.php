<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\HttpServer\TestHttpServer;

/**
 * The server writes one access-log line per request to stdout: start time,
 * method, path, status and execution time. Manages its own server so it can read
 * the captured stdout.
 */
class HttpServerAccessLogTest extends TestCase
{
    public function testEachRequestIsLoggedWithMethodPathStatusAndTiming(): void
    {
        $server = TestHttpServer::start();

        try {
            $this->get($server->baseUrl() . '/');
            $this->get($server->baseUrl() . '/does-not-exist');

            // The line is logged right after the response is sent; give stdout a
            // moment to flush to the captured file.
            usleep(150_000);

            $output = $server->output();

            // "<date>T<time> GET / 200 <n>ms"
            self::assertMatchesRegularExpression('#\dT[\d:]+ GET / 200 [\d.]+ms#', $output);
            self::assertMatchesRegularExpression('#GET /does-not-exist 404 [\d.]+ms#', $output);
        } finally {
            $server->stop();
        }
    }

    private function get(string $url): void
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);

        curl_exec($curl);
        curl_close($curl);
    }
}
