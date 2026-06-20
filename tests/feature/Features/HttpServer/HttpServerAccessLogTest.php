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

            // "<date>T<hh:mm:ss>.<microseconds> GET / 200 <n>ms"
            self::assertMatchesRegularExpression('#\dT\d{2}:\d{2}:\d{2}\.\d{6} GET / 200 [\d.]+ms#', $output);
            self::assertMatchesRegularExpression('#GET /does-not-exist 404 [\d.]+ms#', $output);
        } finally {
            $server->stop();
        }
    }

    /**
     * A request whose URL-encoded path decodes to a newline must not be able to
     * forge an extra access-log line: the control bytes are escaped (\xNN) so the
     * whole request stays on a single line.
     */
    public function testEncodedNewlineInPathCannotForgeALogLine(): void
    {
        $server = TestHttpServer::start();

        try {
            // %0A decodes to "\n"; "forged200ms" mimics the tail of a fake access-log
            // line. Sent path-as-is so curl forwards the raw %0A unchanged. (No spaces
            // in the path: a raw space would make an invalid request line.)
            $this->get(
                $server->baseUrl() . '/inj%0Aforged200ms',
                pathAsIs: true,
            );

            usleep(150_000);

            $output = $server->output();

            // The decoded newline is escaped, keeping method/path/status on one line.
            self::assertStringContainsString('GET /inj\\x0Aforged200ms 404', $output);

            // No raw newline leaked into the path field, so it cannot start a new line.
            self::assertStringNotContainsString("/inj\nforged", $output);
        } finally {
            $server->stop();
        }
    }

    private function get(string $url, bool $pathAsIs = false): void
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_PATH_AS_IS     => $pathAsIs,
        ]);

        curl_exec($curl);
        curl_close($curl);
    }
}
