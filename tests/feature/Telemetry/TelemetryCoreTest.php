<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Telemetry;

use PHPUnit\Framework\TestCase;
use SConcur\Exceptions\Telemetry\FrameTooLargeException;
use SConcur\Telemetry\Aggregator;
use SConcur\Telemetry\Dto\MasterInfo;
use SConcur\Telemetry\Dto\Snapshot;
use SConcur\Telemetry\Dto\StoredSnapshot;
use SConcur\Telemetry\FrameCodec;
use SConcur\Telemetry\Render\HtmlRenderer;
use SConcur\Telemetry\Render\JsonRenderer;
use SConcur\Telemetry\Render\PrometheusRenderer;

/**
 * Unit coverage of the pure telemetry core: frame decoding, snapshot parsing,
 * aggregation math, and the three renderers — no sockets involved.
 */
class TelemetryCoreTest extends TestCase
{
    public function testExtractFramesReturnsCompleteFramesAndPartialTail(): void
    {
        $buffer = $this->frame('one') . $this->frame('two') . pack('N', 10) . 'incompl';

        [$frames, $remainder] = FrameCodec::extractFrames($buffer, 1 << 20);

        self::assertSame(['one', 'two'], $frames);
        self::assertSame(pack('N', 10) . 'incompl', $remainder);
    }

    public function testExtractFramesRejectsOversizeFrame(): void
    {
        $this->expectException(FrameTooLargeException::class);

        FrameCodec::extractFrames(pack('N', 5000) . 'xxxxx', 100);
    }

    public function testExtractFramesRejectsHighBitFrameLength(): void
    {
        // A length with the high bit set (0xFFFFFFFF) must be rejected, not fed as a
        // (possibly negative on 32-bit) length into substr().
        $this->expectException(FrameTooLargeException::class);

        FrameCodec::extractFrames("\xFF\xFF\xFF\xFF" . 'xxxx', 65_536);
    }

    public function testSnapshotFromDecodedRejectsMalformed(): void
    {
        self::assertNull(Snapshot::fromDecoded('not an array'));
        self::assertNull(Snapshot::fromDecoded(['name' => '', 'pid' => 1]));
        self::assertNull(Snapshot::fromDecoded(['name' => 'srv', 'pid' => 0]));
    }

    public function testAggregatorSumsRequestsAndWeightsAverage(): void
    {
        $now = 1_750_000_000_000;

        $aggregate = $this->aggregateOf(
            [
                $this->stored($this->requestsSnapshot(pid: 11, updatedAtMs: $now, completed: 10, avgMs: 2.0), $now),
                $this->stored($this->requestsSnapshot(pid: 12, updatedAtMs: $now, completed: 30, avgMs: 6.0), $now),
            ],
            'srv',
            $now,
        );

        self::assertSame(2, $aggregate->workersTotal);
        self::assertSame(0, $aggregate->workersHung);
        self::assertNotNull($aggregate->totals->requests);
        self::assertSame(40, $aggregate->totals->requests->completed);
        // Weighted: (2*10 + 6*30) / 40 = 5.0.
        self::assertSame(5.0, $aggregate->totals->requests->avgMs);
        self::assertSame(2000, $aggregate->totals->memory->rssBytes);
        self::assertNull($aggregate->totals->connections);
    }

    public function testAggregatorAveragesZeroWhenNothingCompleted(): void
    {
        $now = 1_750_000_000_000;

        // A requests section present but with completed = 0 must not divide by zero.
        $snapshot = Snapshot::fromDecoded([
            'name'        => 'srv',
            'pid'         => 5,
            'updatedAtMs' => $now,
            'requests'    => ['completed' => 0, 'avgMs' => 0.0, 'inFlight' => 4],
        ]);

        self::assertNotNull($snapshot);

        $aggregate = $this->aggregateOf([$this->stored($snapshot, $now)], 'srv', $now);

        self::assertNotNull($aggregate->totals->requests);
        self::assertSame(0, $aggregate->totals->requests->completed);
        self::assertSame(0.0, $aggregate->totals->requests->avgMs);
        self::assertSame(4, $aggregate->totals->requests->inFlight);
    }

    public function testAggregatorSumsConnectionsOnlyPool(): void
    {
        $now = 1_750_000_000_000;

        $first  = Snapshot::fromDecoded(['name' => 'srv', 'pid' => 1, 'updatedAtMs' => $now, 'connections' => ['active' => 2, 'totalAccepted' => 10]]);
        $second = Snapshot::fromDecoded(['name' => 'srv', 'pid' => 2, 'updatedAtMs' => $now, 'connections' => ['active' => 5, 'totalAccepted' => 40]]);

        self::assertNotNull($first);
        self::assertNotNull($second);

        $aggregate = $this->aggregateOf(
            [$this->stored($first, $now), $this->stored($second, $now)],
            'srv',
            $now,
        );

        self::assertNull($aggregate->totals->requests, 'a connections-only pool has no requests section');
        self::assertNotNull($aggregate->totals->connections);
        self::assertSame(7, $aggregate->totals->connections->active);
        self::assertSame(50, $aggregate->totals->connections->totalAccepted);
    }

    public function testAggregatorKeepsBothWorkloadSectionsInAMixedPool(): void
    {
        $now = 1_750_000_000_000;

        $httpWorker   = $this->requestsSnapshot(pid: 1, updatedAtMs: $now, completed: 4, avgMs: 3.0);
        $socketWorker = Snapshot::fromDecoded(['name' => 'srv', 'pid' => 2, 'updatedAtMs' => $now, 'connections' => ['active' => 6, 'totalAccepted' => 12]]);

        self::assertNotNull($socketWorker);

        $aggregate = $this->aggregateOf(
            [$this->stored($httpWorker, $now), $this->stored($socketWorker, $now)],
            'srv',
            $now,
        );

        // A heterogeneous pool surfaces both sections in the totals; each worker keeps
        // only its own section.
        self::assertNotNull($aggregate->totals->requests);
        self::assertSame(4, $aggregate->totals->requests->completed);
        self::assertNotNull($aggregate->totals->connections);
        self::assertSame(6, $aggregate->totals->connections->active);

        self::assertNotNull($aggregate->workers[0]->requests);
        self::assertNull($aggregate->workers[0]->connections);
        self::assertNull($aggregate->workers[1]->requests);
        self::assertNotNull($aggregate->workers[1]->connections);
    }

    public function testAggregatorFlagsHungBySnapshotAge(): void
    {
        $now = 1_750_000_000_000;

        // Age is measured from the receipt time (second arg), not the worker stamp:
        // a frame received 20s ago is hung regardless of its own updatedAtMs.
        $aggregate = $this->aggregateOf(
            [$this->stored($this->requestsSnapshot(pid: 11, updatedAtMs: $now, completed: 1, avgMs: 1.0), $now - 20_000)],
            'srv',
            $now,
        );

        self::assertSame(1, $aggregate->workersHung);
        self::assertTrue($aggregate->workers[0]->hung);
        self::assertSame(20_000, $aggregate->workers[0]->snapshotAgeMs);
    }

    public function testRenderersProduceParityOutputs(): void
    {
        $now = 1_750_000_000_000;

        $aggregate = $this->aggregateOf(
            [$this->stored($this->requestsSnapshot(pid: 12346, updatedAtMs: $now, completed: 42, avgMs: 2.4), $now)],
            'srv',
            $now,
        );

        $json = (new JsonRenderer())->render($aggregate);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        self::assertSame('srv', $decoded['name']);
        self::assertSame(42, $decoded['totals']['requests']['completed']);

        $metrics = (new PrometheusRenderer())->render($aggregate);

        self::assertStringContainsString('sconcur_pool_requests_completed_total{name="srv"} 42', $metrics);
        self::assertStringContainsString('sconcur_worker_requests_completed_total{name="srv",pid="12346"} 42', $metrics);
        self::assertStringContainsString('# TYPE sconcur_pool_workers gauge', $metrics);

        $html = (new HtmlRenderer())->render($aggregate);

        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('srv', $html);
        self::assertStringContainsString('completed', $html);
    }

    public function testMasterSectionAndStartTimesRender(): void
    {
        $now         = 1_750_000_000_000;
        $startedAtMs = $now - 5_000;

        $master = new MasterInfo(
            pid: 4242,
            startedAtMs: $now - 60_000,
            uptimeSeconds: 60.0,
            rssBytes: 52_428_800,
            cpuPercent: 7.5,
        );

        $aggregate = $this->aggregateOf(
            [$this->stored($this->requestsSnapshot(pid: 11, updatedAtMs: $now, completed: 1, avgMs: 1.0, startedAtMs: $startedAtMs), $now)],
            'srv',
            $now,
            $master,
        );

        /** @var array<string, mixed> $json */
        $json = json_decode((new JsonRenderer())->render($aggregate), true);

        self::assertArrayHasKey('master', $json);
        self::assertSame(4242, $json['master']['pid']);
        self::assertStringEndsWith('+00:00', (string) $json['master']['startedAt']);
        self::assertSame(52_428_800, $json['master']['memory']['rssBytes']);
        self::assertStringEndsWith('+00:00', (string) $json['workers'][0]['startedAt']);

        $metrics = (new PrometheusRenderer())->render($aggregate);

        self::assertStringContainsString('sconcur_master_memory_rss_bytes{name="srv"} 52428800', $metrics);
        self::assertStringContainsString('sconcur_master_cpu_percent{name="srv"} 7.5', $metrics);
        self::assertStringContainsString('sconcur_worker_start_time_seconds{name="srv",pid="11"} ' . intdiv($startedAtMs, 1000), $metrics);

        $html = (new HtmlRenderer())->render($aggregate);

        self::assertStringContainsString('<caption>Master</caption>', $html);
        self::assertStringContainsString('started (UTC)', $html);
    }

    public function testPrometheusEscapesLabelAndOmitsOtherWorkload(): void
    {
        $now = 1_750_000_000_000;

        $snapshot = Snapshot::fromDecoded([
            'name'        => 'po"ol',
            'pid'         => 7,
            'updatedAtMs' => $now,
            'connections' => ['active' => 3, 'totalAccepted' => 50],
        ]);

        self::assertNotNull($snapshot);

        $metrics = (new PrometheusRenderer())->render($this->aggregateOf([$this->stored($snapshot, $now)], 'po"ol', $now));

        self::assertStringContainsString('sconcur_pool_connections_active{name="po\"ol"} 3', $metrics);
        self::assertStringNotContainsString('sconcur_pool_requests', $metrics);
    }

    protected function frame(string $body): string
    {
        return pack('N', strlen($body)) . $body;
    }

    /**
     * @param list<StoredSnapshot> $storedSnapshots
     */
    protected function aggregateOf(array $storedSnapshots, string $name, int $nowMs, ?MasterInfo $master = null): \SConcur\Telemetry\Dto\Aggregate
    {
        return (new Aggregator())->aggregate($storedSnapshots, $name, $nowMs, '2026-01-01T00:00:00+00:00', $master);
    }

    protected function stored(Snapshot $snapshot, int $receivedAtMs): StoredSnapshot
    {
        return new StoredSnapshot(
            snapshot: $snapshot,
            receivedAtMs: $receivedAtMs,
        );
    }

    protected function requestsSnapshot(int $pid, int $updatedAtMs, int $completed, float $avgMs, int $startedAtMs = 0): Snapshot
    {
        $snapshot = Snapshot::fromDecoded([
            'name'        => 'srv',
            'pid'         => $pid,
            'updatedAtMs' => $updatedAtMs,
            'startedAtMs' => $startedAtMs,
            'memory'      => ['rssBytes' => 1000, 'goRuntimeBytes' => 400, 'nonExtensionBytes' => 600],
            'cpuPercent'  => 5.0,
            'goroutines'  => 3,
            'requests'    => ['completed' => $completed, 'avgMs' => $avgMs, 'inFlight' => 2],
        ]);

        self::assertNotNull($snapshot);

        return $snapshot;
    }
}
