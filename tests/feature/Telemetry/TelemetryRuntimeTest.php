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

        $masterStartedAtMs = 1_700_000_000_000;

        $runtime = new TelemetryRuntime(
            socketPath: $socketPath,
            panelPort: $port,
            adminToken: 'secret',
            name: 'srv',
            masterStartedAtMs: $masterStartedAtMs,
        );

        $runtime->start();

        $worker = stream_socket_client('unix://' . $socketPath, $errno, $errstr, 1.0);

        self::assertIsResource($worker, 'worker unix connect failed: ' . $errstr);

        $snapshot = [
            'name'        => 'srv',
            'pid'         => 4242,
            'updatedAtMs' => (int) (microtime(true) * 1000),
            'startedAtMs' => (int) (microtime(true) * 1000) - 1_000,
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

        // The worker carries its serve start as a UTC datetime.
        self::assertStringEndsWith('+00:00', (string) $decoded['workers'][0]['startedAt']);

        // The master section reports the supervisor's own metrics, start datetime in UTC.
        self::assertArrayHasKey('master', $decoded);
        self::assertSame((int) getmypid(), $decoded['master']['pid']);
        self::assertSame(gmdate('c', intdiv($masterStartedAtMs, 1000)), $decoded['master']['startedAt']);
        self::assertArrayHasKey('rssBytes', $decoded['master']['memory']);

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

    public function testQueryTokenAuthorizesStats(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        $runtime = $this->startedRuntime($socketPath, $port);

        $worker = $this->connectWorker($socketPath);

        fwrite($worker, $this->snapshotFrame(['name' => 'srv', 'pid' => 4242, 'updatedAtMs' => $this->nowMs(), 'requests' => ['completed' => 7, 'avgMs' => 1.0, 'inFlight' => 0]]));
        fflush($worker);

        $this->pump($runtime);

        // No Authorization header — the token rides the query string (so a browser can
        // open the panel). Must authorize just like the Bearer header.
        [$status, $body] = $this->httpGet($port, '/api/stats?token=secret', ['Accept: application/json'], $runtime);

        self::assertSame(200, $status);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true);

        self::assertSame('srv', $decoded['name']);
        self::assertSame(7, $decoded['totals']['requests']['completed']);

        // A wrong query token is still hidden as 404.
        [$wrongStatus] = $this->httpGet($port, '/api/stats?token=nope', ['Accept: application/json'], $runtime);

        self::assertSame(404, $wrongStatus);

        fclose($worker);

        $runtime->stop();
    }

    public function testHtmlPanelRouteServesPage(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        $runtime = $this->startedRuntime($socketPath, $port);

        $worker = $this->connectWorker($socketPath);

        fwrite($worker, $this->snapshotFrame(['name' => 'srv', 'pid' => 4242, 'updatedAtMs' => $this->nowMs(), 'requests' => ['completed' => 3, 'avgMs' => 1.0, 'inFlight' => 0]]));
        fflush($worker);

        $this->pump($runtime);

        // The browser-facing live panel at GET /?token= must return the HTML page (with
        // the meta-refresh wiring), not the metrics/JSON representation.
        [$status, $body] = $this->httpGet($port, '/?token=secret', [], $runtime);

        self::assertSame(200, $status);
        self::assertStringContainsString('<!doctype html>', $body);
        self::assertStringContainsString('<caption>Workers</caption>', $body);
        self::assertStringContainsString('http-equiv="refresh"', $body);

        fclose($worker);

        $runtime->stop();
    }

    public function testSseStreamsAggregateAndPushesPeriodically(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        $runtime = $this->startedRuntime($socketPath, $port);

        $worker = $this->connectWorker($socketPath);

        fwrite($worker, $this->snapshotFrame(['name' => 'srv', 'pid' => 4242, 'updatedAtMs' => $this->nowMs(), 'requests' => ['completed' => 5, 'avgMs' => 1.0, 'inFlight' => 0]]));
        fflush($worker);

        $this->pump($runtime);

        $client  = $this->connectPanel($port);
        $request = "GET /events HTTP/1.1\r\nHost: 127.0.0.1\r\nAuthorization: Bearer secret\r\n\r\n";

        for ($written = 0; $written < strlen($request);) {
            $runtime->poll(10_000);

            $chunk = @fwrite($client, substr($request, $written));

            if ($chunk === false) {
                break;
            }

            $written += $chunk;
        }

        // The SSE upgrade headers plus the immediate first event arrive without waiting
        // for the periodic tick.
        $buffer = $this->pumpRead(
            $client,
            $runtime,
            static fn(string $accumulated): bool => str_contains($accumulated, "\n\n") && substr_count($accumulated, 'data: ') >= 1,
        );

        self::assertStringContainsString('text/event-stream', $buffer);
        self::assertStringContainsString('data: ', $buffer);

        $firstEvent = $this->firstSseData($buffer);

        self::assertSame('srv', $firstEvent['name']);
        self::assertSame(1, $firstEvent['workersTotal']);

        // The runtime pushes a fresh aggregate on its ~1s cadence: after the interval a
        // second event must arrive on the same open stream.
        usleep(1_100_000);

        $buffer = $this->pumpRead(
            $client,
            $runtime,
            static fn(string $accumulated): bool => substr_count($accumulated, 'data: ') >= 2,
            timeoutSeconds: 3.0,
            seed: $buffer,
        );

        self::assertGreaterThanOrEqual(2, substr_count($buffer, 'data: '), 'the SSE stream must push periodically');

        fclose($client);
        fclose($worker);

        $runtime->stop();
    }

    public function testOversizeFrameDropsTheConnection(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        $runtime = $this->startedRuntime($socketPath, $port);

        $worker = $this->connectWorker($socketPath);

        // Declare a frame far above the collector's cap. The collector must drop the
        // connection rather than buffer it, so nothing is ever stored.
        fwrite($worker, pack('N', 5_000_000) . str_repeat('x', 64));
        fflush($worker);

        $this->pump($runtime);

        [$status, $body] = $this->httpGet($port, '/api/stats', ['Authorization: Bearer secret', 'Accept: application/json'], $runtime);

        self::assertSame(200, $status);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true);

        self::assertSame(0, $decoded['workersTotal'], 'an oversize frame must not be ingested');

        fclose($worker);

        $runtime->stop();
    }

    public function testFrameSplitAcrossReadsIsIngested(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        $runtime = $this->startedRuntime($socketPath, $port);

        $worker = $this->connectWorker($socketPath);

        $frame = $this->snapshotFrame(['name' => 'srv', 'pid' => 99, 'updatedAtMs' => $this->nowMs(), 'requests' => ['completed' => 13, 'avgMs' => 1.0, 'inFlight' => 0]]);
        $split = intdiv(strlen($frame), 2);

        // First half: an incomplete frame must not yet produce a worker.
        fwrite($worker, substr($frame, 0, $split));
        fflush($worker);

        $this->pump($runtime, 3);

        [, $partialBody] = $this->httpGet($port, '/api/stats', ['Authorization: Bearer secret', 'Accept: application/json'], $runtime);
        /** @var array<string, mixed> $partial */
        $partial = json_decode($partialBody, true);

        self::assertSame(0, $partial['workersTotal'], 'a half-arrived frame must not be ingested');

        // Second half completes the frame across reads — the collector reassembles it.
        fwrite($worker, substr($frame, $split));
        fflush($worker);

        $this->pump($runtime, 3);

        [$status, $body] = $this->httpGet($port, '/api/stats', ['Authorization: Bearer secret', 'Accept: application/json'], $runtime);

        self::assertSame(200, $status);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true);

        self::assertSame(1, $decoded['workersTotal']);
        self::assertSame(13, $decoded['totals']['requests']['completed']);

        fclose($worker);

        $runtime->stop();
    }

    public function testOversizeRequestHeaderIsDropped(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        $runtime = $this->startedRuntime($socketPath, $port);

        $client = $this->connectPanel($port);

        // A request whose header never terminates and grows past the cap must be
        // dropped, not buffered without bound.
        $flood    = str_repeat('a', 20_000);
        $deadline = microtime(true) + 2.0;
        $written  = 0;

        while ($written < strlen($flood) && microtime(true) < $deadline) {
            $runtime->poll(10_000);

            $chunk = @fwrite($client, substr($flood, $written));

            if ($chunk === false || $chunk === 0) {
                break;
            }

            $written += $chunk;
        }

        // The server closed its side — the client now sees EOF.
        $closed = false;

        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline) {
            $runtime->poll(10_000);

            $chunk = @fread($client, 8_192);

            if ($chunk === '' && feof($client)) {
                $closed = true;

                break;
            }
        }

        self::assertTrue($closed, 'the oversize request connection must be dropped');

        fclose($client);

        // The panel survived and still serves a well-formed request.
        [$status] = $this->httpGet($port, '/api/stats', ['Authorization: Bearer secret', 'Accept: application/json'], $runtime);

        self::assertSame(200, $status);

        $runtime->stop();
    }

    public function testBindFailureDisablesRuntime(): void
    {
        $socketPath = $this->directory . '/t.sock';
        $port       = $this->freeTcpPort();

        // Occupy the panel port so the runtime's panel listener fails to bind.
        $occupier = stream_socket_server('tcp://0.0.0.0:' . $port, $errno, $errstr);

        self::assertIsResource($occupier, 'occupier bind failed: ' . $errstr);

        $runtime = new TelemetryRuntime(
            socketPath: $socketPath,
            panelPort: $port,
            adminToken: 'secret',
            name: 'srv',
        );

        $runtime->start();

        // A half-open plane is never left behind: the collector socket it had already
        // bound is torn down and unlinked.
        self::assertFileDoesNotExist($socketPath);

        // A disabled runtime degrades poll() to a plain sleep and stop() is a no-op —
        // neither must throw.
        $runtime->poll(1_000);
        $runtime->stop();

        fclose($occupier);
    }

    protected function startedRuntime(string $socketPath, int $port, string $token = 'secret', string $name = 'srv'): TelemetryRuntime
    {
        $runtime = new TelemetryRuntime(
            socketPath: $socketPath,
            panelPort: $port,
            adminToken: $token,
            name: $name,
        );

        $runtime->start();

        return $runtime;
    }

    /**
     * @return resource
     */
    protected function connectWorker(string $socketPath)
    {
        $worker = stream_socket_client('unix://' . $socketPath, $errno, $errstr, 1.0);

        self::assertIsResource($worker, 'worker unix connect failed: ' . $errstr);

        return $worker;
    }

    /**
     * @return resource
     */
    protected function connectPanel(int $port)
    {
        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2.0);

        self::assertIsResource($client, 'panel connect failed: ' . $errstr);

        stream_set_blocking($client, false);

        return $client;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    protected function snapshotFrame(array $snapshot): string
    {
        $body = (string) json_encode(['t' => 'snapshot', 's' => $snapshot]);

        return pack('N', strlen($body)) . $body;
    }

    protected function pump(TelemetryRuntime $runtime, int $ticks = 5): void
    {
        for ($tick = 0; $tick < $ticks; $tick++) {
            $runtime->poll(20_000);
        }
    }

    protected function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Pumps the runtime while draining a non-blocking client until $done(buffer) holds
     * or the deadline passes, returning everything read (prepended with $seed).
     *
     * @param resource              $client
     * @param callable(string):bool $done
     */
    protected function pumpRead($client, TelemetryRuntime $runtime, callable $done, float $timeoutSeconds = 2.0, string $seed = ''): string
    {
        $buffer   = $seed;
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $runtime->poll(10_000);

            $chunk = @fread($client, 8_192);

            if (is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
            }

            if ($done($buffer)) {
                break;
            }
        }

        return $buffer;
    }

    /**
     * Decodes the first `data: {...}` SSE event in $buffer.
     *
     * @return array<string, mixed>
     */
    protected function firstSseData(string $buffer): array
    {
        foreach (explode("\n", $buffer) as $line) {
            if (str_starts_with($line, 'data: ')) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode(trim(substr($line, strlen('data: '))), true);

                return $decoded;
            }
        }

        self::fail('no SSE data event found');
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
