<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * A snapshot as held in the collector Store: the worker's pushed Snapshot plus the
 * master-clock timestamp at which the collector received it. Snapshot age (and the
 * hung flag) are derived from receivedAtMs, not the worker-stamped updatedAtMs, so
 * they are immune to any clock skew between the worker and the master.
 */
readonly class StoredSnapshot
{
    public function __construct(
        public Snapshot $snapshot,
        public int $receivedAtMs,
    ) {
    }
}
