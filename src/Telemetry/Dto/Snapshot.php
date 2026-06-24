<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * One worker's statistics as pushed over the telemetry socket (the "s" field of a
 * snapshot frame). Exactly one workload section is set: requests (HTTP) or
 * connections (socket). Field names mirror the Go schema
 * (ext/internal/stats/snapshot.go).
 */
readonly class Snapshot
{
    public function __construct(
        public string $name,
        public int $pid,
        public int $updatedAtMs,
        public float $uptimeSeconds,
        public Memory $memory,
        public float $cpuPercent,
        public int $goroutines,
        public ?Requests $requests,
        public ?Connections $connections,
    ) {
    }

    /**
     * Parses a decoded snapshot frame body. Returns null when the payload is not a
     * usable snapshot (missing name or a non-positive pid), so a malformed frame is
     * dropped rather than polluting the store.
     */
    public static function fromDecoded(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $name = (string) ($data['name'] ?? '');
        $pid  = (int) ($data['pid'] ?? 0);

        if ($name === '' || $pid <= 0) {
            return null;
        }

        $memory = is_array($data['memory'] ?? null) ? Memory::fromArray($data['memory']) : new Memory(0, 0, 0);

        return new self(
            name: $name,
            pid: $pid,
            updatedAtMs: (int) ($data['updatedAtMs'] ?? 0),
            uptimeSeconds: (float) ($data['uptimeSeconds'] ?? 0),
            memory: $memory,
            cpuPercent: (float) ($data['cpuPercent'] ?? 0),
            goroutines: (int) ($data['goroutines'] ?? 0),
            requests: is_array($data['requests'] ?? null) ? Requests::fromArray($data['requests']) : null,
            connections: is_array($data['connections'] ?? null) ? Connections::fromArray($data['connections']) : null,
        );
    }
}
