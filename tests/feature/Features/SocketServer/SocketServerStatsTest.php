<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * Covers the dedicated stats server for a socket pool: a socket server has no HTTP
 * routes, so the only way it exposes statistics is the dedicated stats HTTP server
 * (GET /api/stats on SCONCUR_STATS_PORT). The aggregate carries a connections
 * section rather than requests.
 */
class SocketServerStatsTest extends TestCase
{
    private const string TOKEN = 'socket-stats-token-67890';
    private const string PATH  = '/api/stats';

    public function testServesAggregatedSocketStatistics(): void
    {
        $statsPort = self::freePort();

        $master = TestWorkerMaster::start(
            options: [
                'workerScript' => self::socketWorkerScript(),
                'name'         => 'sconcur-socket-server',
            ],
            env: [
                'SCONCUR_ADMIN_TOKEN' => self::TOKEN,
                'SCONCUR_STATS_PORT'  => (string) $statsPort,
            ],
            // The socket pool's main port speaks no HTTP, so the http /pid probe does
            // not apply; getStats() waits for the dedicated stats port instead.
            waitReachable: false,
        );

        try {
            [$status, $body] = $this->getStats($statsPort, 'Bearer ' . self::TOKEN);

            self::assertSame(200, $status);

            $data = json_decode($body, true);

            self::assertIsArray($data);
            self::assertGreaterThanOrEqual(1, $data['workersTotal']);
            self::assertArrayHasKey('connections', $data['totals'], 'a socket pool reports a connections section');
            self::assertArrayNotHasKey('requests', $data['totals'], 'a socket pool has no requests section');
            self::assertArrayHasKey('active', $data['totals']['connections']);
            self::assertArrayHasKey('totalAccepted', $data['totals']['connections']);

            // Missing token → 404.
            [$missingStatus] = $this->request("http://127.0.0.1:{$statsPort}" . self::PATH, []);

            self::assertSame(404, $missingStatus);
        } finally {
            $master->stop();
        }
    }

    private static function socketWorkerScript(): string
    {
        return dirname(__DIR__, 4) . '/tests/servers/socket/socket-server.php';
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
