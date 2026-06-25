<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * End-to-end coverage of the telemetry panel for an HTTP pool: with panelPort and
 * adminToken set, the master collects each worker's pushed snapshot over a unix
 * socket and serves the aggregate at GET /api/stats on the panel port. The aggregate
 * carries a requests section. Exercises the whole chain (worker pusher → master
 * collector → panel) with the real extension.
 */
class HttpServerStatsTest extends TestCase
{
    private const string TOKEN = 'test-admin-token-12345';
    private const string PATH  = '/api/stats';

    public function testServesAggregatedHttpStatistics(): void
    {
        $panelPort = self::freePort();

        $master = TestWorkerMaster::start(
            options: ['panelPort' => $panelPort, 'adminToken' => self::TOKEN],
        );

        try {
            // Warm the pool so the request counters are non-trivial.
            $master->get('/');
            $master->get('/');

            [$status, $body] = $this->getStats($panelPort, 'Bearer ' . self::TOKEN);

            self::assertSame(200, $status);

            $data = json_decode($body, true);

            self::assertIsArray($data);
            self::assertArrayHasKey('totals', $data);
            self::assertIsArray($data['totals']);
            self::assertArrayHasKey('requests', $data['totals'], 'an HTTP pool reports a requests section');
            self::assertArrayNotHasKey('connections', $data['totals'], 'an HTTP pool has no connections section');
            self::assertArrayHasKey('memory', $data['totals']);
            self::assertArrayHasKey('goroutines', $data['totals']);

            // The token travels in the Authorization header / config, never the log.
            self::assertStringNotContainsString(self::TOKEN, $master->logText());

            // Default representation (curl sends Accept: */*) is the Prometheus text
            // exposition, not JSON.
            [$metricsStatus, $metricsBody] = $this->request(
                url: "http://127.0.0.1:{$panelPort}" . self::PATH,
                headers: ['Authorization: Bearer ' . self::TOKEN],
            );

            self::assertSame(200, $metricsStatus);
            self::assertStringContainsString('sconcur_pool_workers', $metricsBody);
            self::assertNull(json_decode($metricsBody, true), 'metrics body must not be JSON');

            // A valid token but a non-GET method → 405.
            [$postStatus] = $this->request(
                url: "http://127.0.0.1:{$panelPort}" . self::PATH,
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
        $panelPort = self::freePort();

        $master = TestWorkerMaster::start(
            options: ['panelPort' => $panelPort, 'adminToken' => self::TOKEN],
        );

        try {
            // Reach the panel first with a valid token, then probe the bad cases.
            [$okStatus] = $this->getStats($panelPort, 'Bearer ' . self::TOKEN);

            self::assertSame(200, $okStatus);

            [$missingStatus, $missingBody] = $this->request("http://127.0.0.1:{$panelPort}" . self::PATH, []);

            self::assertSame(404, $missingStatus);
            self::assertStringNotContainsString('workersTotal', $missingBody);

            [$wrongStatus] = $this->request(
                url: "http://127.0.0.1:{$panelPort}" . self::PATH,
                headers: ['Authorization: Bearer not-the-token'],
            );

            self::assertSame(404, $wrongStatus);
        } finally {
            $master->stop();
        }
    }

    /**
     * Polls the panel until it reports at least one worker (workers push on an
     * interval, so the pool fills in shortly after start), then returns [status, body].
     *
     * @return array{int, string}
     */
    private function getStats(int $port, string $authorization): array
    {
        $deadline = microtime(true) + 10.0;

        $result = [0, ''];

        while (microtime(true) < $deadline) {
            $result = $this->request(
                url: "http://127.0.0.1:{$port}" . self::PATH,
                headers: ['Authorization: ' . $authorization, 'Accept: application/json'],
            );

            if ($result[0] === 200) {
                $data = json_decode($result[1], true);

                if (is_array($data) && is_int($data['workersTotal'] ?? null) && $data['workersTotal'] >= 1) {
                    return $result;
                }
            }

            usleep(200_000);
        }

        return $result;
    }

    /**
     * Performs a request with optional headers and returns [status, body]; [0, ''] when
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
