<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * Covers the admin statistics endpoint (GET /sconcur-server-api/admin/stats),
 * served entirely on the Go side over a SO_REUSEPORT pool: a worker that catches
 * the request aggregates every worker's snapshot file. The token is read from the
 * SCONCUR_ADMIN_TOKEN env (forwarded to workers by the master); without it the
 * endpoint is off and the path flows to the normal PHP handler.
 */
class HttpServerAdminStatsTest extends TestCase
{
    private const string TOKEN = 'test-admin-token-12345';
    private const string PATH  = '/sconcur-server-api/admin/stats';

    public function testAggregatesPoolStatisticsWithValidToken(): void
    {
        $master = TestWorkerMaster::start(
            env: ['SCONCUR_ADMIN_TOKEN' => self::TOKEN],
        );

        try {
            // Warm the pool so the request counters are non-trivial.
            $master->get('/');
            $master->get('/');

            [$status, $body] = $this->request(
                url: $master->baseUrl() . self::PATH,
                headers: ['Authorization: Bearer ' . self::TOKEN],
            );

            self::assertSame(200, $status);

            $data = json_decode($body, true);

            self::assertIsArray($data);
            self::assertArrayHasKey('workersTotal', $data);
            self::assertGreaterThanOrEqual(1, $data['workersTotal']);
            self::assertArrayHasKey('totals', $data);
            self::assertIsArray($data['totals']);
            self::assertArrayHasKey('requests', $data['totals']);
            self::assertArrayHasKey('memory', $data['totals']);
            self::assertArrayHasKey('goroutines', $data['totals']);
            self::assertArrayHasKey('workers', $data);
            self::assertIsArray($data['workers']);

            // The token travels in the Authorization header, never the URL, so it must
            // not appear in the access log the master captures from the workers.
            self::assertStringNotContainsString(self::TOKEN, $master->logText());
        } finally {
            $master->stop();
        }
    }

    public function testRejectsMissingOrWrongTokenWith404(): void
    {
        $master = TestWorkerMaster::start(
            env: ['SCONCUR_ADMIN_TOKEN' => self::TOKEN],
        );

        try {
            [$missingStatus, $missingBody] = $this->request(
                url: $master->baseUrl() . self::PATH,
                headers: [],
            );

            self::assertSame(404, $missingStatus);
            self::assertStringNotContainsString('workersTotal', $missingBody);

            [$wrongStatus, $wrongBody] = $this->request(
                url: $master->baseUrl() . self::PATH,
                headers: ['Authorization: Bearer not-the-token'],
            );

            self::assertSame(404, $wrongStatus);
            self::assertStringNotContainsString('workersTotal', $wrongBody);
        } finally {
            $master->stop();
        }
    }

    public function testEndpointOffWithoutToken(): void
    {
        // No SCONCUR_ADMIN_TOKEN configured: the server does not intercept the path,
        // so it reaches the PHP handler, which 404s it as an unknown route.
        $master = TestWorkerMaster::start();

        try {
            [$status, $body] = $this->request(
                url: $master->baseUrl() . self::PATH,
                headers: [],
            );

            self::assertSame(404, $status);
            self::assertStringContainsString('not found', $body);
        } finally {
            $master->stop();
        }
    }

    /**
     * Performs a GET with optional headers and returns [status, body].
     *
     * @param list<string> $headers
     *
     * @return array{int, string}
     */
    private function request(string $url, array $headers): array
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_FRESH_CONNECT  => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 3,
        ]);

        $body   = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return [$status, is_string($body) ? $body : ''];
    }
}
