<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * Process metrics of the worker master itself (it loads the extension but never starts
 * its runtime):
 * its pid, serve start, uptime, memory and CPU usage. Sampled on the
 * master side and attached to the aggregate so the panel shows the supervisor next to
 * its pool. startedAt is rendered as a UTC datetime.
 */
readonly class MasterInfo
{
    public function __construct(
        public int $pid,
        public int $startedAtMs,
        public float $uptimeSeconds,
        public Memory $memory,
        public float $cpuPercent,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pid'           => $this->pid,
            'startedAt'     => $this->startedAtMs > 0 ? gmdate('c', intdiv($this->startedAtMs, 1000)) : '',
            'uptimeSeconds' => $this->uptimeSeconds,
            'memory'        => $this->memory->toArray(),
            'cpuPercent'    => $this->cpuPercent,
        ];
    }
}
