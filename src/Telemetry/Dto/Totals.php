<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * Pool-wide sum. cpuPercent is the sum of per-process percentages (so it may exceed
 * 100%); requests->avgMs is weighted by each worker's completed count. Only the
 * workload section present in the pool's snapshots is filled.
 */
readonly class Totals
{
    public function __construct(
        public Memory $memory,
        public float $cpuPercent,
        public int $goroutines,
        public ?Requests $requests,
        public ?Connections $connections,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'memory'     => $this->memory->toArray(),
            'cpuPercent' => $this->cpuPercent,
            'goroutines' => $this->goroutines,
        ];

        if ($this->requests !== null) {
            $data['requests'] = $this->requests->toArray();
        }

        if ($this->connections !== null) {
            $data['connections'] = $this->connections->toArray();
        }

        return $data;
    }
}
