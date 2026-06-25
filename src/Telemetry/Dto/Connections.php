<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * Socket-server workload section of a snapshot: active is the current open
 * connection count, totalAccepted the lifetime number accepted. Field names mirror
 * the Go schema (ext/internal/stats/snapshot.go).
 */
readonly class Connections
{
    public function __construct(
        public int $active,
        public int $totalAccepted,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            active: (int) ($data['active'] ?? 0),
            totalAccepted: (int) ($data['totalAccepted'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'active'        => $this->active,
            'totalAccepted' => $this->totalAccepted,
        ];
    }
}
