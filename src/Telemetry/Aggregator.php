<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

use SConcur\Telemetry\Dto\Aggregate;
use SConcur\Telemetry\Dto\Connections;
use SConcur\Telemetry\Dto\MasterInfo;
use SConcur\Telemetry\Dto\Memory;
use SConcur\Telemetry\Dto\Requests;
use SConcur\Telemetry\Dto\StoredSnapshot;
use SConcur\Telemetry\Dto\Totals;
use SConcur\Telemetry\Dto\WorkerEntry;

/**
 * Sums the live worker snapshots into the pool view. Ports the Go aggregator
 * (former ext/internal/stats/aggregate.go fillTotals): summed process metrics,
 * request average weighted by completed, only the workload section actually present.
 * A snapshot whose receipt is older than hungThresholdMs flags its worker hung — the
 * age is measured against the master's own receipt clock (skew-immune), so it does
 * not depend on the worker's stamped updatedAtMs.
 */
class Aggregator
{
    public function __construct(
        protected int $hungThresholdMs = 15_000,
    ) {
    }

    /**
     * @param list<StoredSnapshot> $storedSnapshots
     */
    public function aggregate(
        array $storedSnapshots,
        string $name,
        int $nowMs,
        string $generatedAt,
        ?MasterInfo $master = null,
    ): Aggregate {
        $workers     = [];
        $workersHung = 0;

        $rssBytes          = 0;
        $goRuntimeBytes    = 0;
        $nonExtensionBytes = 0;
        $cpuPercent        = 0.0;
        $goroutines        = 0;

        $hasRequests     = false;
        $completed       = 0;
        $weightedAvgMs   = 0.0;
        $inFlight        = 0;
        $inFlight1to5s   = 0;
        $inFlight5to15s  = 0;
        $inFlightOver15s = 0;

        $hasConnections = false;
        $active         = 0;
        $totalAccepted  = 0;

        foreach ($storedSnapshots as $storedSnapshot) {
            $snapshot      = $storedSnapshot->snapshot;
            $snapshotAgeMs = $nowMs - $storedSnapshot->receivedAtMs;
            $hung          = $snapshotAgeMs > $this->hungThresholdMs;

            if ($hung) {
                $workersHung++;
            }

            $rssBytes += $snapshot->memory->rssBytes;
            $goRuntimeBytes += $snapshot->memory->goRuntimeBytes;
            $nonExtensionBytes += $snapshot->memory->nonExtensionBytes;
            $cpuPercent += $snapshot->cpuPercent;
            $goroutines += $snapshot->goroutines;

            if ($snapshot->requests !== null) {
                $hasRequests = true;
                $completed += $snapshot->requests->completed;
                $weightedAvgMs += $snapshot->requests->avgMs * $snapshot->requests->completed;
                $inFlight += $snapshot->requests->inFlight;
                $inFlight1to5s += $snapshot->requests->inFlight1to5s;
                $inFlight5to15s += $snapshot->requests->inFlight5to15s;
                $inFlightOver15s += $snapshot->requests->inFlightOver15s;
            }

            if ($snapshot->connections !== null) {
                $hasConnections = true;
                $active += $snapshot->connections->active;
                $totalAccepted += $snapshot->connections->totalAccepted;
            }

            $workers[] = new WorkerEntry(
                pid: $snapshot->pid,
                hung: $hung,
                snapshotAgeMs: $snapshotAgeMs,
                startedAtMs: $snapshot->startedAtMs,
                uptimeSeconds: $snapshot->uptimeSeconds,
                memory: $snapshot->memory,
                cpuPercent: $snapshot->cpuPercent,
                goroutines: $snapshot->goroutines,
                requests: $snapshot->requests,
                connections: $snapshot->connections,
            );
        }

        $totalsRequests = null;

        if ($hasRequests) {
            $totalsRequests = new Requests(
                completed: $completed,
                avgMs: $completed > 0 ? $weightedAvgMs / $completed : 0.0,
                inFlight: $inFlight,
                inFlight1to5s: $inFlight1to5s,
                inFlight5to15s: $inFlight5to15s,
                inFlightOver15s: $inFlightOver15s,
            );
        }

        $totalsConnections = $hasConnections
            ? new Connections(active: $active, totalAccepted: $totalAccepted)
            : null;

        $totals = new Totals(
            memory: new Memory(
                rssBytes: $rssBytes,
                goRuntimeBytes: $goRuntimeBytes,
                nonExtensionBytes: $nonExtensionBytes,
            ),
            cpuPercent: $cpuPercent,
            goroutines: $goroutines,
            requests: $totalsRequests,
            connections: $totalsConnections,
        );

        return new Aggregate(
            generatedAt: $generatedAt,
            name: $name,
            workersTotal: count($workers),
            workersHung: $workersHung,
            totals: $totals,
            workers: $workers,
            master: $master,
        );
    }
}
