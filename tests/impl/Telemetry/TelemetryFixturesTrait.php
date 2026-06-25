<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Telemetry;

use PHPUnit\Framework\Assert;
use SConcur\Telemetry\Aggregator;
use SConcur\Telemetry\Dto\Aggregate;
use SConcur\Telemetry\Dto\MasterInfo;
use SConcur\Telemetry\Dto\Snapshot;
use SConcur\Telemetry\Dto\StoredSnapshot;

/**
 * Shared fixtures for the telemetry aggregation/render tests: builds Snapshot /
 * StoredSnapshot inputs and runs them through the Aggregator. Used by the transport-
 * neutral core tests and by the per-transport telemetry tests (HTTP `requests`,
 * socket `connections`).
 */
trait TelemetryFixturesTrait
{
    /**
     * @param list<StoredSnapshot> $storedSnapshots
     */
    protected function aggregateOf(array $storedSnapshots, string $name, int $nowMs, ?MasterInfo $master = null): Aggregate
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

        Assert::assertNotNull($snapshot);

        return $snapshot;
    }

    protected function connectionsSnapshot(int $pid, int $updatedAtMs, int $active, int $totalAccepted, int $startedAtMs = 0): Snapshot
    {
        $snapshot = Snapshot::fromDecoded([
            'name'        => 'srv',
            'pid'         => $pid,
            'updatedAtMs' => $updatedAtMs,
            'startedAtMs' => $startedAtMs,
            'memory'      => ['rssBytes' => 1000, 'goRuntimeBytes' => 400, 'nonExtensionBytes' => 600],
            'cpuPercent'  => 5.0,
            'goroutines'  => 3,
            'connections' => ['active' => $active, 'totalAccepted' => $totalAccepted],
        ]);

        Assert::assertNotNull($snapshot);

        return $snapshot;
    }
}
