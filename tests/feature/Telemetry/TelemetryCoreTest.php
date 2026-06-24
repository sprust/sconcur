<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Telemetry;

use PHPUnit\Framework\TestCase;
use SConcur\Exceptions\Telemetry\FrameTooLargeException;
use SConcur\Telemetry\Aggregator;
use SConcur\Telemetry\Dto\Snapshot;
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
                $this->requestsSnapshot(pid: 11, updatedAtMs: $now, completed: 10, avgMs: 2.0),
                $this->requestsSnapshot(pid: 12, updatedAtMs: $now, completed: 30, avgMs: 6.0),
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

    public function testAggregatorFlagsHungBySnapshotAge(): void
    {
        $now = 1_750_000_000_000;

        $aggregate = $this->aggregateOf(
            [$this->requestsSnapshot(pid: 11, updatedAtMs: $now - 20_000, completed: 1, avgMs: 1.0)],
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
            [$this->requestsSnapshot(pid: 12346, updatedAtMs: $now, completed: 42, avgMs: 2.4)],
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

        $metrics = (new PrometheusRenderer())->render($this->aggregateOf([$snapshot], 'po"ol', $now));

        self::assertStringContainsString('sconcur_pool_connections_active{name="po\"ol"} 3', $metrics);
        self::assertStringNotContainsString('sconcur_pool_requests', $metrics);
    }

    protected function frame(string $body): string
    {
        return pack('N', strlen($body)) . $body;
    }

    /**
     * @param list<Snapshot> $snapshots
     */
    protected function aggregateOf(array $snapshots, string $name, int $nowMs): \SConcur\Telemetry\Dto\Aggregate
    {
        return (new Aggregator())->aggregate($snapshots, $name, $nowMs, '2026-01-01T00:00:00+00:00');
    }

    protected function requestsSnapshot(int $pid, int $updatedAtMs, int $completed, float $avgMs): Snapshot
    {
        $snapshot = Snapshot::fromDecoded([
            'name'        => 'srv',
            'pid'         => $pid,
            'updatedAtMs' => $updatedAtMs,
            'memory'      => ['rssBytes' => 1000, 'goRuntimeBytes' => 400, 'nonExtensionBytes' => 600],
            'cpuPercent'  => 5.0,
            'goroutines'  => 3,
            'requests'    => ['completed' => $completed, 'avgMs' => $avgMs, 'inFlight' => 2],
        ]);

        self::assertNotNull($snapshot);

        return $snapshot;
    }
}
