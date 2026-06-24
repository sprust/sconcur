<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * Covers the dedicated stats server for an HTTP pool: with SCONCUR_ADMIN_TOKEN and
 * SCONCUR_STATS_PORT set, every worker binds the stats port (SO_REUSEPORT) and
 * serves GET /api/stats, aggregating the whole pool from the shared snapshot files.
 * Served entirely on the Go side.
 */
class HttpServerStatsTest extends TestCase
{
    private const string TOKEN = 'test-admin-token-12345';
    private const string PATH  = '/api/stats';

    public function testServesAggregatedHttpStatistics(): void
    {
        $statsPort = self::freePort();

        $master = TestWorkerMaster::start(
            env: [
                'SCONCUR_ADMIN_TOKEN' => self::TOKEN,
                'SCONCUR_STATS_PORT'  => (string) $statsPort,
            ],
        );

        try {
            // Warm the pool so the request counters are non-trivial.
            $master->get('/');
            $master->get('/');

            [$status, $body] = $this->getStats($statsPort, 'Bearer ' . self::TOKEN);

            self::assertSame(200, $status);

            $data = json_decode($body, true);

            self::assertIsArray($data);
            self::assertArrayHasKey('workersTotal', $data);
            self::assertGreaterThanOrEqual(1, $data['workersTotal']);
            self::assertArrayHasKey('totals', $data);
            self::assertIsArray($data['totals']);
            self::assertArrayHasKey('requests', $data['totals'], 'an HTTP pool reports a requests section');
            self::assertArrayNotHasKey('connections', $data['totals'], 'an HTTP pool has no connections section');
            self::assertArrayHasKey('memory', $data['totals']);
            self::assertArrayHasKey('goroutines', $data['totals']);

            // The token travels in the Authorization header, never logged.
            self::assertStringNotContainsString(self::TOKEN, $master->logText());

            // Default representation (curl sends Accept: */*, no JSON/HTML preference)
            // is the Prometheus text exposition, not JSON.
            [$metricsStatus, $metricsBody] = $this->request(
                url: "http://127.0.0.1:{$statsPort}" . self::PATH,
                headers: ['Authorization: Bearer ' . self::TOKEN],
            );

            self::assertSame(200, $metricsStatus);
            self::assertStringContainsString('sconcur_pool_workers', $metricsBody);
            self::assertNull(json_decode($metricsBody, true), 'metrics body must not be JSON');

            // A valid token but a non-GET method → 405.
            [$postStatus] = $this->request(
                url: "http://127.0.0.1:{$statsPort}" . self::PATH,
                headers: ['Authorization: Bearer ' . self::TOKEN],
                method: 'POST',
            );

            self::assertSame(405, $postStatus);
        } finally {
            $master->stop();
        }
    }

    public function testRejectsMissingOrWrongTokenWith404(): void
    {
        $statsPort = self::freePort();

        $master = TestWorkerMaster::start(
            env: [
                'SCONCUR_ADMIN_TOKEN' => self::TOKEN,
                'SCONCUR_STATS_PORT'  => (string) $statsPort,
            ],
        );

        try {
            // Reach the stats port first with a valid token, then probe the bad cases.
            [$okStatus] = $this->getStats($statsPort, 'Bearer ' . self::TOKEN);

            self::assertSame(200, $okStatus);

            [$missingStatus, $missingBody] = $this->request("http://127.0.0.1:{$statsPort}" . self::PATH, []);

            self::assertSame(404, $missingStatus);
            self::assertStringNotContainsString('workersTotal', $missingBody);

            [$wrongStatus] = $this->request(
                url: "http://127.0.0.1:{$statsPort}" . self::PATH,
                headers: ['Authorization: Bearer not-the-token'],
            );

            self::assertSame(404, $wrongStatus);
        } finally {
            $master->stop();
        }
    }

    public function testNoStatsServerWithoutPort(): void
    {
        // Token but no stats port: no dedicated server is bound. The main HTTP port no
        // longer intercepts the path either, so it 404s as a normal unknown route.
        $master = TestWorkerMaster::start(
            env: ['SCONCUR_ADMIN_TOKEN' => self::TOKEN],
        );

        try {
            [$status, $body] = $master->get(self::PATH);

            self::assertSame(404, $status);
            self::assertStringContainsString('not found', $body);
        } finally {
            $master->stop();
        }
    }

    /**
     * Polls the stats port until it answers (the workers may still be booting), then
     * returns [status, body].
     *
     * @return array{int, string}
     */
    private function getStats(int $port, string $authorization): array
    {
        $deadline = microtime(true) + 5.0;

        $result = [0, ''];

        while (microtime(true) < $deadline) {
            $result = $this->request(
                url: "http://127.0.0.1:{$port}" . self::PATH,
                headers: $authorization === '' ? [] : ['Authorization: ' . $authorization, 'Accept: application/json'],
            );

            if ($result[0] !== 0) {
                return $result;
            }

            usleep(100_000);
        }

        return $result;
    }

    /**
     * Performs a GET with optional headers and returns [status, body]; [0, ''] when
     * the connection fails.
     *
     * @param list<string> $headers
     *
     * @return array{int, string}
     */
    private function request(string $url, array $headers, string $method = 'GET'): array
    {
        $curl = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_FRESH_CONNECT  => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 3,
        ];

        if ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        curl_setopt_array($curl, $options);

        $body   = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return [$status, is_string($body) ? $body : ''];
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            self::fail("Could not allocate a port: {$errstr}");
        }

        $name = (string) stream_socket_get_name($socket, false);

        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
