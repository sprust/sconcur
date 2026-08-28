<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * One pool of a master, summed on its own. A master supervises several groups, and
 * their numbers are not comparable — an HTTP pool and a queue-consumer pool share
 * nothing but memory and CPU — so the totals that matter are per group.
 *
 * The grouping comes from the label a worker puts on its snapshots, "<group>:<slot>"
 * (WorkerGroup::buildEnv).
 */
readonly class GroupAggregate
{
    public function __construct(
        public string $name,
        public int $workersTotal,
        public int $workersHung,
        public Totals $totals,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'workersTotal' => $this->workersTotal,
            'workersHung'  => $this->workersHung,
            'totals'       => $this->totals->toArray(),
        ];
    }
}
