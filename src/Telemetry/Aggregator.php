<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

use SConcur\Telemetry\Dto\Aggregate;
use SConcur\Telemetry\Dto\Connections;
use SConcur\Telemetry\Dto\Consumers;
use SConcur\Telemetry\Dto\GroupAggregate;
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

        /** @var array<string, list<StoredSnapshot>> $byGroup */
        $byGroup = [];

        foreach ($storedSnapshots as $storedSnapshot) {
            $snapshot      = $storedSnapshot->snapshot;
            $snapshotAgeMs = $nowMs - $storedSnapshot->receivedAtMs;
            $hung          = $snapshotAgeMs > $this->hungThresholdMs;

            if ($hung) {
                $workersHung++;
            }

            $group = static::groupOf($snapshot->name);

            $byGroup[$group][] = $storedSnapshot;

            $workers[] = new WorkerEntry(
                pid: $snapshot->pid,
                group: $group,
                hung: $hung,
                snapshotAgeMs: $snapshotAgeMs,
                startedAtMs: $snapshot->startedAtMs,
                uptimeSeconds: $snapshot->uptimeSeconds,
                memory: $snapshot->memory,
                cpuPercent: $snapshot->cpuPercent,
                goroutines: $snapshot->goroutines,
                requests: $snapshot->requests,
                connections: $snapshot->connections,
                consumers: $snapshot->consumers,
            );
        }

        $groups = [];

        foreach ($byGroup as $groupName => $groupSnapshots) {
            $groups[] = new GroupAggregate(
                name: $groupName,
                workersTotal: count($groupSnapshots),
                workersHung: $this->countHung($groupSnapshots, $nowMs),
                totals: $this->sum($groupSnapshots),
            );
        }

        return new Aggregate(
            generatedAt: $generatedAt,
            name: $name,
            workersTotal: count($workers),
            workersHung: $workersHung,
            totals: $this->sum($storedSnapshots),
            workers: $workers,
            master: $master,
            groups: $groups,
        );
    }

    /**
     * The pool a worker belongs to, read off the label it stamps its snapshots with:
     * "<group>:<slot>". A label with no slot is taken whole — a worker started by hand,
     * outside a master, still lands somewhere sensible.
     */
    protected static function groupOf(string $name): string
    {
        $separator = strrpos($name, ':');

        if ($separator === false || $separator === 0) {
            return $name;
        }

        return substr($name, 0, $separator);
    }

    /**
     * @param list<StoredSnapshot> $storedSnapshots
     */
    protected function countHung(array $storedSnapshots, int $nowMs): int
    {
        $hung = 0;

        foreach ($storedSnapshots as $storedSnapshot) {
            if (($nowMs - $storedSnapshot->receivedAtMs) > $this->hungThresholdMs) {
                $hung++;
            }
        }

        return $hung;
    }

    /**
     * Sums a set of snapshots. Process metrics add up for any mix of workers; a
     * workload section is filled only when somebody in the set reported it, and its
     * average is weighted by what that somebody actually finished.
     *
     * @param list<StoredSnapshot> $storedSnapshots
     */
    protected function sum(array $storedSnapshots): Totals
    {
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

        $hasConsumers            = false;
        $delivered               = 0;
        $acked                   = 0;
        $refused                 = 0;
        $consumersWeightedAvgMs  = 0.0;
        $consumersInFlight       = 0;
        $consumersInFlight1to5s  = 0;
        $consumersInFlight5to15s = 0;
        $consumersInFlightOver15 = 0;

        foreach ($storedSnapshots as $storedSnapshot) {
            $snapshot = $storedSnapshot->snapshot;

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

            if ($snapshot->consumers !== null) {
                $hasConsumers = true;
                $delivered += $snapshot->consumers->delivered;
                $acked += $snapshot->consumers->acked;
                $refused += $snapshot->consumers->refused;
                // Weighted by what the worker actually settled, the same way the
                // request average is weighted by what it completed.
                $consumersWeightedAvgMs += $snapshot->consumers->avgMs
                    * ($snapshot->consumers->acked + $snapshot->consumers->refused);
                $consumersInFlight += $snapshot->consumers->inFlight;
                $consumersInFlight1to5s += $snapshot->consumers->inFlight1to5s;
                $consumersInFlight5to15s += $snapshot->consumers->inFlight5to15s;
                $consumersInFlightOver15 += $snapshot->consumers->inFlightOver15s;
            }
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

        $totalsConsumers = null;

        if ($hasConsumers) {
            $settled = $acked + $refused;

            $totalsConsumers = new Consumers(
                delivered: $delivered,
                acked: $acked,
                refused: $refused,
                avgMs: $settled > 0 ? $consumersWeightedAvgMs / $settled : 0.0,
                inFlight: $consumersInFlight,
                inFlight1to5s: $consumersInFlight1to5s,
                inFlight5to15s: $consumersInFlight5to15s,
                inFlightOver15s: $consumersInFlightOver15,
            );
        }

        return new Totals(
            memory: new Memory(
                rssBytes: $rssBytes,
                goRuntimeBytes: $goRuntimeBytes,
                nonExtensionBytes: $nonExtensionBytes,
            ),
            cpuPercent: $cpuPercent,
            goroutines: $goroutines,
            requests: $totalsRequests,
            connections: $totalsConnections,
            consumers: $totalsConsumers,
        );
    }
}
