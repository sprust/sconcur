<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

use SConcur\Telemetry\Dto\Snapshot;

/**
 * In-memory store of the latest snapshot per live worker, keyed by the telemetry
 * connection id. A worker pushes repeatedly over one connection (latest wins);
 * closing the connection evicts it (clean dead-worker detection — no liveness probe,
 * no files). Replaces the old per-worker snapshot files.
 */
class Store
{
    /** @var array<int, Snapshot> connection id → its last snapshot */
    protected array $snapshots = [];

    /**
     * Records the latest snapshot for a connection (overwrites the previous one).
     */
    public function put(int $connectionId, Snapshot $snapshot): void
    {
        $this->snapshots[$connectionId] = $snapshot;
    }

    /**
     * Evicts a connection's snapshot when it closes (the worker is gone).
     */
    public function remove(int $connectionId): void
    {
        unset($this->snapshots[$connectionId]);
    }

    /**
     * All live snapshots, in arbitrary order.
     *
     * @return list<Snapshot>
     */
    public function all(): array
    {
        return array_values($this->snapshots);
    }
}
