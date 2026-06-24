<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Telemetry;

use PHPUnit\Framework\TestCase;
use SConcur\Telemetry\TelemetryRuntime;

/**
 * Integration coverage of the collector + panel wired through TelemetryRuntime over
 * real sockets, driven by manual poll() calls (as the master loop drives it): a
 * worker pushes a snapshot frame to the unix socket, then the HTTP panel returns the
 * aggregate. No extension involved.
 */
class TelemetryRuntimeTest extends TestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sc-tel-' . uniqid('', true);

        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function testPushedSnapshotIsServedByThePanel(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        $runtime = new TelemetryRuntime(
            socketPath: $socketPath,
            panelPort: $port,
            adminToken: 'secret',
            name: 'srv',
        );

        $runtime->start();

        $worker = stream_socket_client('unix://' . $socketPath, $errno, $errstr, 1.0);

        self::assertIsResource($worker, 'worker unix connect failed: ' . $errstr);

        $snapshot = [
            'name'        => 'srv',
            'pid'         => 4242,
            'updatedAtMs' => (int) (microtime(true) * 1000),
            'requests'    => ['completed' => 42, 'avgMs' => 2.4, 'inFlight' => 1],
        ];

        $body = (string) json_encode(['t' => 'snapshot', 's' => $snapshot]);

        fwrite($worker, pack('N', strlen($body)) . $body);
        fflush($worker);

        // Let the collector accept the connection and ingest the frame.
        for ($tick = 0; $tick < 5; $tick++) {
            $runtime->poll(20_000);
        }

        [$status, $responseBody] = $this->httpGet($port, '/api/stats', ['Authorization: Bearer secret', 'Accept: application/json'], $runtime);

        self::assertSame(200, $status);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($responseBody, true);

        self::assertSame('srv', $decoded['name']);
        self::assertSame(42, $decoded['totals']['requests']['completed']);
        self::assertSame(4242, $decoded['workers'][0]['pid']);

        // Wrong/missing token must be 404 (the endpoint is hidden).
        [$statusNoToken] = $this->httpGet($port, '/api/stats', [], $runtime);

        self::assertSame(404, $statusNoToken);

        // Prometheus is the default representation.
        [$statusMetrics, $metricsBody] = $this->httpGet($port, '/api/stats', ['Authorization: Bearer secret'], $runtime);

        self::assertSame(200, $statusMetrics);
        self::assertStringContainsString('sconcur_pool_requests_completed_total{name="srv"} 42', $metricsBody);

        fclose($worker);

        $runtime->stop();

        self::assertFileDoesNotExist($socketPath);
    }

    public function testPanelClosesEvictWorkerFromAggregate(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        $runtime = new TelemetryRuntime(
            socketPath: $socketPath,
            panelPort: $port,
            adminToken: 'secret',
            name: 'srv',
        );

        $runtime->start();

        $worker = stream_socket_client('unix://' . $socketPath, $errno, $errstr, 1.0);

        self::assertIsResource($worker);

        $body = (string) json_encode([
            't' => 'snapshot',
            's' => ['name' => 'srv', 'pid' => 7, 'updatedAtMs' => (int) (microtime(true) * 1000), 'connections' => ['active' => 3, 'totalAccepted' => 9]],
        ]);

        fwrite($worker, pack('N', strlen($body)) . $body);
        fflush($worker);

        for ($tick = 0; $tick < 5; $tick++) {
            $runtime->poll(20_000);
        }

        // The worker dies (connection closes) — the collector must evict it.
        fclose($worker);

        for ($tick = 0; $tick < 5; $tick++) {
            $runtime->poll(20_000);
        }

        [$status, $responseBody] = $this->httpGet($port, '/api/stats', ['Authorization: Bearer secret', 'Accept: application/json'], $runtime);

        self::assertSame(200, $status);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($responseBody, true);

        self::assertSame(0, $decoded['workersTotal']);

        $runtime->stop();
    }

    public function testNonSnapshotFrameIsIgnored(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        $runtime = new TelemetryRuntime(
            socketPath: $socketPath,
            panelPort: $port,
            adminToken: 'secret',
            name: 'srv',
        );

        $runtime->start();

        $worker = stream_socket_client('unix://' . $socketPath, $errno, $errstr, 1.0);

        self::assertIsResource($worker);

        // An unknown envelope kind (future worker.start/stop, ...) carrying a payload
        // must not be misread as a snapshot.
        $body = (string) json_encode([
            't' => 'worker.bogus',
            's' => ['name' => 'srv', 'pid' => 7, 'updatedAtMs' => (int) (microtime(true) * 1000), 'requests' => ['completed' => 99]],
        ]);

        fwrite($worker, pack('N', strlen($body)) . $body);
        fflush($worker);

        for ($tick = 0; $tick < 5; $tick++) {
            $runtime->poll(20_000);
        }

        [$status, $responseBody] = $this->httpGet($port, '/api/stats', ['Authorization: Bearer secret', 'Accept: application/json'], $runtime);

        self::assertSame(200, $status);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($responseBody, true);

        self::assertSame(0, $decoded['workersTotal']);

        fclose($worker);

        $runtime->stop();
    }

    protected function freeTcpPort(): int
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        self::assertIsResource($listener, 'free-port bind failed: ' . $errstr);

        $name = (string) stream_socket_get_name($listener, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        fclose($listener);

        return $port;
    }

    /**
     * Issues a GET against the panel, pumping the runtime's poll() so the non-blocking
     * server accepts, reads and answers. Returns [status, body].
     *
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string}
     */
    protected function httpGet(int $port, string $path, array $headers, TelemetryRuntime $runtime): array
    {
        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2.0);

        self::assertIsResource($client, 'panel connect failed: ' . $errstr);

        stream_set_blocking($client, false);

        $request = 'GET ' . $path . " HTTP/1.1\r\nHost: 127.0.0.1\r\n";

        foreach ($headers as $header) {
            $request .= $header . "\r\n";
        }

        $request .= "Connection: close\r\n\r\n";

        $written = 0;

        while ($written < strlen($request)) {
            $runtime->poll(10_000);

            $chunk = @fwrite($client, substr($request, $written));

            if ($chunk === false) {
                break;
            }

            $written += $chunk;
        }

        $response = '';
        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline) {
            $runtime->poll(10_000);

            $chunk = @fread($client, 8_192);

            if ($chunk === false) {
                break;
            }

            $response .= $chunk;

            if ($chunk === '' && feof($client)) {
                break;
            }
        }

        fclose($client);

        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');

        $statusLine = explode("\r\n", $head)[0];

        preg_match('#HTTP/1\.1 (\d+)#', $statusLine, $matches);

        return [(int) ($matches[1] ?? 0), $body];
    }
}
