<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

use SConcur\Telemetry\Dto\Snapshot;
use SConcur\Telemetry\Dto\StoredSnapshot;

/**
 * In-memory store of the latest snapshot per live worker, keyed by the telemetry
 * connection id. A worker pushes repeatedly over one connection (latest wins);
 * closing the connection evicts it (clean dead-worker detection — no liveness probe,
 * no files). Replaces the old per-worker snapshot files.
 *
 * Each entry also carries the master-clock receipt time, so the aggregator can age a
 * snapshot against the master's own clock (skew-immune) rather than the
 * worker-stamped timestamp.
 */
class Store
{
    /** @var array<int, StoredSnapshot> connection id → its last snapshot + receipt time */
    protected array $snapshots = [];

    /**
     * Records the latest snapshot for a connection (overwrites the previous one).
     * receivedAtMs is the master-clock time the frame was ingested.
     */
    public function put(int $connectionId, Snapshot $snapshot, int $receivedAtMs): void
    {
        $this->snapshots[$connectionId] = new StoredSnapshot(
            snapshot: $snapshot,
            receivedAtMs: $receivedAtMs,
        );
    }

    /**
     * Evicts a connection's snapshot when it closes (the worker is gone).
     */
    public function remove(int $connectionId): void
    {
        unset($this->snapshots[$connectionId]);
    }

    /**
     * All live snapshots (with their receipt time), in arbitrary order.
     *
     * @return list<StoredSnapshot>
     */
    public function all(): array
    {
        return array_values($this->snapshots);
    }
}
