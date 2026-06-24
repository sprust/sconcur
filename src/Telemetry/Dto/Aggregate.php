<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * The aggregated pool view a stats request returns. generatedAt is an RFC3339
 * timestamp of when the response was built.
 */
readonly class Aggregate
{
    /**
     * @param array<int, WorkerEntry> $workers
     */
    public function __construct(
        public string $generatedAt,
        public string $name,
        public int $workersTotal,
        public int $workersHung,
        public Totals $totals,
        public array $workers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generatedAt'  => $this->generatedAt,
            'name'         => $this->name,
            'workersTotal' => $this->workersTotal,
            'workersHung'  => $this->workersHung,
            'totals'       => $this->totals->toArray(),
            'workers'      => array_map(
                static fn(WorkerEntry $worker): array => $worker->toArray(),
                $this->workers,
            ),
        ];
    }
}
