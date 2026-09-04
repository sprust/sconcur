<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * Pool-wide sum. cpuPercent is the sum of per-process percentages (so it may exceed 100%);
 * requests->avgMs is weighted by each worker's completed count, and consumers->avgMs by its
 * timed count — the deliveries it actually measured, which is the denominator it divided
 * by. Only the workload sections present in the pool's snapshots are filled: a master
 * running unlike pools reports each of them beside the others, and a section nobody
 * reported stays null.
 */
readonly class Totals
{
    public function __construct(
        public Memory $memory,
        public float $cpuPercent,
        public int $runtimeTasks,
        public ?Requests $requests,
        public ?Connections $connections,
        public ?Consumers $consumers = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'memory'       => $this->memory->toArray(),
            'cpuPercent'   => $this->cpuPercent,
            'runtimeTasks' => $this->runtimeTasks,
        ];

        if ($this->requests !== null) {
            $data['requests'] = $this->requests->toArray();
        }

        if ($this->consumers !== null) {
            $data['consumers'] = $this->consumers->toArray();
        }

        if ($this->connections !== null) {
            $data['connections'] = $this->connections->toArray();
        }

        return $data;
    }
}
