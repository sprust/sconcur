<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Telemetry;

use PHPUnit\Framework\TestCase;
use SConcur\Exceptions\Telemetry\FrameTooLargeException;
use SConcur\Telemetry\Dto\MasterInfo;
use SConcur\Telemetry\Dto\Snapshot;
use SConcur\Telemetry\FrameCodec;
use SConcur\Telemetry\Render\HtmlRenderer;
use SConcur\Telemetry\Render\JsonRenderer;
use SConcur\Telemetry\Render\PrometheusRenderer;
use SConcur\Tests\Impl\Telemetry\TelemetryFixturesTrait;

/**
 * Transport-neutral coverage of the telemetry core: frame decoding, snapshot parsing,
 * the cross-cutting aggregation (mixed pools, hung detection) and the master section —
 * no sockets involved. The workload-specific aggregation/render cases live next to
 * their server: {@see \SConcur\Tests\Feature\Features\HttpServer\TelemetryHttpTest}
 * (HTTP `requests`) and
 * {@see \SConcur\Tests\Feature\Features\SocketServer\TelemetrySocketTest} (socket
 * `connections`).
 */
class TelemetryCoreTest extends TestCase
{
    use TelemetryFixturesTrait;

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

    public function testAggregatorKeepsBothWorkloadSectionsInAMixedPool(): void
    {
        $now = 1_750_000_000_000;

        $httpWorker   = $this->requestsSnapshot(pid: 1, updatedAtMs: $now, completed: 4, avgMs: 3.0);
        $socketWorker = $this->connectionsSnapshot(pid: 2, updatedAtMs: $now, active: 6, totalAccepted: 12);

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

    protected function frame(string $body): string
    {
        return pack('N', strlen($body)) . $body;
    }
}
