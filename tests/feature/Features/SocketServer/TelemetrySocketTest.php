<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

use PHPUnit\Framework\TestCase;
use SConcur\Telemetry\Dto\Snapshot;
use SConcur\Telemetry\Render\HtmlRenderer;
use SConcur\Telemetry\Render\JsonRenderer;
use SConcur\Telemetry\Render\PrometheusRenderer;
use SConcur\Tests\Impl\Telemetry\TelemetryFixturesTrait;

/**
 * Telemetry coverage of the socket workload section (`connections`): how the
 * aggregator sums the per-worker connection counters and how the renderers expose
 * them (and only them, never the `requests` series). The transport-neutral core lives
 * in {@see \SConcur\Tests\Feature\Telemetry}; the HTTP counterpart is
 * {@see \SConcur\Tests\Feature\Features\HttpServer\TelemetryHttpTest}.
 */
class TelemetrySocketTest extends TestCase
{
    use TelemetryFixturesTrait;

    public function testAggregatorSumsConnectionsOnlyPool(): void
    {
        $now = 1_750_000_000_000;

        $aggregate = $this->aggregateOf(
            [
                $this->stored($this->connectionsSnapshot(pid: 1, updatedAtMs: $now, active: 2, totalAccepted: 10), $now),
                $this->stored($this->connectionsSnapshot(pid: 2, updatedAtMs: $now, active: 5, totalAccepted: 40), $now),
            ],
            'srv',
            $now,
        );

        self::assertNull($aggregate->totals->requests, 'a connections-only pool has no requests section');
        self::assertNotNull($aggregate->totals->connections);
        self::assertSame(7, $aggregate->totals->connections->active);
        self::assertSame(50, $aggregate->totals->connections->totalAccepted);
    }

    public function testRenderersProduceConnectionsOutputs(): void
    {
        $now = 1_750_000_000_000;

        $aggregate = $this->aggregateOf(
            [$this->stored($this->connectionsSnapshot(pid: 7, updatedAtMs: $now, active: 3, totalAccepted: 50), $now)],
            'srv',
            $now,
        );

        $json = (new JsonRenderer())->render($aggregate);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        self::assertSame(3, $decoded['totals']['connections']['active']);
        self::assertSame(50, $decoded['totals']['connections']['totalAccepted']);
        self::assertArrayNotHasKey('requests', $decoded['totals']);

        $metrics = (new PrometheusRenderer())->render($aggregate);

        self::assertStringContainsString('sconcur_pool_connections_active{name="srv"} 3', $metrics);
        self::assertStringContainsString('sconcur_worker_connections_accepted_total{name="srv",pid="7"} 50', $metrics);
        self::assertStringNotContainsString('sconcur_pool_requests', $metrics);

        $html = (new HtmlRenderer())->render($aggregate);

        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('accepted', $html);
    }

    public function testPrometheusEscapesLabelAndOmitsRequests(): void
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
}
