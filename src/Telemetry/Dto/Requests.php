<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * HTTP-server workload section of a snapshot. The in-flight buckets are exclusive
 * (a request in flight for 7s counts only in inFlight5to15s). Field names mirror
 * the Go schema (ext/internal/stats/snapshot.go).
 */
readonly class Requests
{
    public function __construct(
        public int $completed,
        public float $avgMs,
        public int $inFlight,
        public int $inFlight1to5s,
        public int $inFlight5to15s,
        public int $inFlightOver15s,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            completed: (int) ($data['completed'] ?? 0),
            avgMs: (float) ($data['avgMs'] ?? 0),
            inFlight: (int) ($data['inFlight'] ?? 0),
            inFlight1to5s: (int) ($data['inFlight1to5s'] ?? 0),
            inFlight5to15s: (int) ($data['inFlight5to15s'] ?? 0),
            inFlightOver15s: (int) ($data['inFlightOver15s'] ?? 0),
        );
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'completed'       => $this->completed,
            'avgMs'           => $this->avgMs,
            'inFlight'        => $this->inFlight,
            'inFlight1to5s'   => $this->inFlight1to5s,
            'inFlight5to15s'  => $this->inFlight5to15s,
            'inFlightOver15s' => $this->inFlightOver15s,
        ];
    }
}
