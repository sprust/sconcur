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
 * Sums the live worker snapshots into the pool view. Ports the aggregator that used to
 * live in the extension: summed process metrics, request average weighted by completed,
 * only the workload section actually present.
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
        $workers = [];

        /** @var array<string, list<StoredSnapshot>> $byGroup */
        $byGroup = [];

        /** @var array<string, int> $hungByGroup */
        $hungByGroup = [];

        foreach ($storedSnapshots as $storedSnapshot) {
            $snapshot      = $storedSnapshot->snapshot;
            $snapshotAgeMs = $nowMs - $storedSnapshot->receivedAtMs;
            $hung          = $snapshotAgeMs > $this->hungThresholdMs;

            $group = static::groupOf($snapshot->name);

            $hungByGroup[$group] = ($hungByGroup[$group] ?? 0) + ($hung ? 1 : 0);

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

        $groupOrder = [];

        foreach (array_keys($byGroup) as $position => $groupName) {
            $groupOrder[$groupName] = $position;
        }

        // Workers come out ordered by group, in the order the groups themselves are
        // reported, so the two tables of the panel read down the same way. usort is
        // stable, so within a group the workers keep the order they arrived in.
        usort(
            $workers,
            static fn(WorkerEntry $left, WorkerEntry $right): int => ($groupOrder[$left->group] ?? 0) <=> ($groupOrder[$right->group] ?? 0),
        );

        foreach ($byGroup as $groupName => $groupSnapshots) {
            $groups[] = new GroupAggregate(
                // Cast because a numeric group name — "42" passes the config's own
                // charset check — comes back from the array key as an int.
                name: (string) $groupName,
                workersTotal: count($groupSnapshots),
                workersHung: $hungByGroup[(string) $groupName] ?? 0,
                totals: $this->sum($groupSnapshots),
            );
        }

        return new Aggregate(
            generatedAt: $generatedAt,
            name: $name,
            workersTotal: count($workers),
            workersHung: array_sum($hungByGroup),
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
     * Sums a set of snapshots. Process metrics add up for any mix of workers; a workload
     * section is filled only when somebody in the set reported it, so a master running
     * unlike pools shows each kind beside the others instead of one zeroed row.
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

        foreach ($storedSnapshots as $storedSnapshot) {
            $snapshot = $storedSnapshot->snapshot;

            $rssBytes += $snapshot->memory->rssBytes;
            $goRuntimeBytes += $snapshot->memory->goRuntimeBytes;
            $nonExtensionBytes += $snapshot->memory->nonExtensionBytes;
            $cpuPercent += $snapshot->cpuPercent;
            $goroutines += $snapshot->goroutines;
        }

        return new Totals(
            memory: new Memory(
                rssBytes: $rssBytes,
                goRuntimeBytes: $goRuntimeBytes,
                nonExtensionBytes: $nonExtensionBytes,
            ),
            cpuPercent: $cpuPercent,
            goroutines: $goroutines,
            requests: static::sumRequests($storedSnapshots),
            connections: static::sumConnections($storedSnapshots),
            consumers: static::sumConsumers($storedSnapshots),
        );
    }

    /**
     * The HTTP workload of a set of snapshots, or null when none of them serves requests.
     * The average is weighted by what each worker actually completed, which is the
     * denominator it divided by.
     *
     * @param list<StoredSnapshot> $storedSnapshots
     */
    protected static function sumRequests(array $storedSnapshots): ?Requests
    {
        $reported = false;

        $completed       = 0;
        $weightedAvgMs   = 0.0;
        $inFlight        = 0;
        $inFlight1to5s   = 0;
        $inFlight5to15s  = 0;
        $inFlightOver15s = 0;

        foreach ($storedSnapshots as $storedSnapshot) {
            $requests = $storedSnapshot->snapshot->requests;

            if ($requests === null) {
                continue;
            }

            $reported = true;

            $completed += $requests->completed;
            $weightedAvgMs += $requests->avgMs * $requests->completed;
            $inFlight += $requests->inFlight;
            $inFlight1to5s += $requests->inFlight1to5s;
            $inFlight5to15s += $requests->inFlight5to15s;
            $inFlightOver15s += $requests->inFlightOver15s;
        }

        if (!$reported) {
            return null;
        }

        return new Requests(
            completed: $completed,
            avgMs: $completed > 0 ? $weightedAvgMs / $completed : 0.0,
            inFlight: $inFlight,
            inFlight1to5s: $inFlight1to5s,
            inFlight5to15s: $inFlight5to15s,
            inFlightOver15s: $inFlightOver15s,
        );
    }

    /**
     * The connection workload of a set of snapshots, or null when none of them accepts
     * connections.
     *
     * @param list<StoredSnapshot> $storedSnapshots
     */
    protected static function sumConnections(array $storedSnapshots): ?Connections
    {
        $reported = false;

        $active        = 0;
        $totalAccepted = 0;

        foreach ($storedSnapshots as $storedSnapshot) {
            $connections = $storedSnapshot->snapshot->connections;

            if ($connections === null) {
                continue;
            }

            $reported = true;

            $active += $connections->active;
            $totalAccepted += $connections->totalAccepted;
        }

        if (!$reported) {
            return null;
        }

        return new Connections(
            active: $active,
            totalAccepted: $totalAccepted,
        );
    }

    /**
     * The queue workload of a set of snapshots, or null when none of them consumes.
     *
     * The average is weighted by the deliveries each worker actually timed, which is the
     * denominator it divided by. Acked plus refused counts settlements it never measured —
     * an auto-acknowledged delivery among them — and weighting by that skews the pool
     * average by an order of magnitude.
     *
     * @param list<StoredSnapshot> $storedSnapshots
     */
    protected static function sumConsumers(array $storedSnapshots): ?Consumers
    {
        $reported = false;

        $coroutines      = 0;
        $delivered       = 0;
        $acked           = 0;
        $refused         = 0;
        $timed           = 0;
        $weightedAvgMs   = 0.0;
        $inFlight        = 0;
        $inFlight1to5s   = 0;
        $inFlight5to15s  = 0;
        $inFlightOver15s = 0;

        foreach ($storedSnapshots as $storedSnapshot) {
            $consumers = $storedSnapshot->snapshot->consumers;

            if ($consumers === null) {
                continue;
            }

            $reported = true;

            $coroutines += $consumers->coroutines;
            $delivered += $consumers->delivered;
            $acked += $consumers->acked;
            $refused += $consumers->refused;
            $timed += $consumers->timed;
            $weightedAvgMs += $consumers->avgMs * $consumers->timed;
            $inFlight += $consumers->inFlight;
            $inFlight1to5s += $consumers->inFlight1to5s;
            $inFlight5to15s += $consumers->inFlight5to15s;
            $inFlightOver15s += $consumers->inFlightOver15s;
        }

        if (!$reported) {
            return null;
        }

        return new Consumers(
            coroutines: $coroutines,
            delivered: $delivered,
            acked: $acked,
            refused: $refused,
            timed: $timed,
            avgMs: $timed > 0 ? $weightedAvgMs / $timed : 0.0,
            inFlight: $inFlight,
            inFlight1to5s: $inFlight1to5s,
            inFlight5to15s: $inFlight5to15s,
            inFlightOver15s: $inFlightOver15s,
        );
    }
}
