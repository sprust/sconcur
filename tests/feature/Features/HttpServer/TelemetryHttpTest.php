<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use SConcur\Telemetry\Render\HtmlRenderer;
use SConcur\Telemetry\Render\JsonRenderer;
use SConcur\Telemetry\Render\PrometheusRenderer;
use SConcur\Tests\Impl\Telemetry\TelemetryFixturesTrait;

/**
 * Telemetry coverage of the HTTP workload section (`requests`): how the aggregator
 * sums and weights the per-worker request counters and how the three renderers expose
 * them. The transport-neutral core lives in {@see \SConcur\Tests\Feature\Telemetry};
 * the socket counterpart is {@see \SConcur\Tests\Feature\Features\SocketServer\TelemetrySocketTest}.
 */
class TelemetryHttpTest extends TestCase
{
    use TelemetryFixturesTrait;

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
        $snapshot = $this->requestsSnapshot(pid: 5, updatedAtMs: $now, completed: 0, avgMs: 0.0);

        $aggregate = $this->aggregateOf([$this->stored($snapshot, $now)], 'srv', $now);

        self::assertNotNull($aggregate->totals->requests);
        self::assertSame(0, $aggregate->totals->requests->completed);
        self::assertSame(0.0, $aggregate->totals->requests->avgMs);
        self::assertSame(2, $aggregate->totals->requests->inFlight);
    }

    public function testRenderersProduceRequestsOutputs(): void
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
}
