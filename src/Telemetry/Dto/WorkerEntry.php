<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * One worker in the aggregated response: its last snapshot plus the derived
 * snapshotAgeMs and hung flag.
 */
readonly class WorkerEntry
{
    public function __construct(
        public int $pid,
        public bool $hung,
        public int $snapshotAgeMs,
        public int $startedAtMs,
        public float $uptimeSeconds,
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
            'pid'           => $this->pid,
            'hung'          => $this->hung,
            'snapshotAgeMs' => $this->snapshotAgeMs,
            'startedAt'     => $this->startedAtMs > 0 ? gmdate('c', intdiv($this->startedAtMs, 1000)) : '',
            'uptimeSeconds' => $this->uptimeSeconds,
            'memory'        => $this->memory->toArray(),
            'cpuPercent'    => $this->cpuPercent,
            'goroutines'    => $this->goroutines,
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
