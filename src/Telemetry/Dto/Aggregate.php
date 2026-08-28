<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * The aggregated view a stats request returns. generatedAt is an RFC3339 timestamp of
 * when the response was built.
 *
 * `totals` sums every worker of the master, which is meaningful for memory and CPU;
 * `groups` sums each pool on its own, which is what the workload numbers are read by.
 */
readonly class Aggregate
{
    /**
     * @param array<int, WorkerEntry> $workers
     * @param list<GroupAggregate>    $groups  one entry per pool, summed on its own
     */
    public function __construct(
        public string $generatedAt,
        public string $name,
        public int $workersTotal,
        public int $workersHung,
        public Totals $totals,
        public array $workers,
        public ?MasterInfo $master = null,
        public array $groups = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'generatedAt'  => $this->generatedAt,
            'name'         => $this->name,
            'workersTotal' => $this->workersTotal,
            'workersHung'  => $this->workersHung,
        ];

        if ($this->master !== null) {
            $data['master'] = $this->master->toArray();
        }

        $data['totals'] = $this->totals->toArray();

        $data['groups'] = array_map(
            static fn(GroupAggregate $group): array => $group->toArray(),
            $this->groups,
        );

        $data['workers'] = array_map(
            static fn(WorkerEntry $worker): array => $worker->toArray(),
            $this->workers,
        );

        return $data;
    }
}
